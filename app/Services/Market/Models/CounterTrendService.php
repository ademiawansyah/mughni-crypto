<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Services\Market\MarketRegimeService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CounterTrendService
{
    private const MODEL_NAME = 'counter_trend';

    private const MODEL_VERSION = '2.0';

    private const MIN_VOLUME_USD = 5_000_000;

    private const MAX_RESULTS = 10;

    private const STRUCTURE_LIMIT = 100;

    private const ENTRY_LIMIT = 100;

    private const MACRO_LIMIT = 10;

    private const SCORE_SWEEP = 40;

    private const SCORE_MSS = 30;

    private const SCORE_ENTRY_ZONE = 15;

    private const SCORE_OI = 8;

    private const SCORE_FUNDING = 7;

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Execute Model 1 (Counter Trend) scanning pipeline.
     *
     * @return array{
     *   execution_id: string,
     *   model: string,
     *   version: string,
     *   timestamp: string,
     *   execution_date: string,
     *   evaluated: int,
     *   shortlisted: int,
     *   results: array<int, array<string, mixed>>,
     * }
     */
    public function execute(
        ?string $executionId = null,
        string $structureTimeframe = '1h',
        string $entryTimeframe = '15m',
        string $macroTimeframe = '1d',
    ): array {
        $resolvedExecutionId = $executionId ?: Str::uuid()->toString();

        Log::info('[CounterTrendService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'structure_timeframe' => $structureTimeframe,
            'entry_timeframe' => $entryTimeframe,
            'macro_timeframe' => $macroTimeframe,
        ]);

        $candidates = $this->filterCoins();
        $signals = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            $structureKlines = $this->fetchAndStoreOhlcv(
                coin: $coin,
                timeframe: $structureTimeframe,
                limit: self::STRUCTURE_LIMIT,
            );

            if ($structureKlines === []) {
                Log::warning('[CounterTrendService] Skipped coin due to missing OHLCV', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                ]);

                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => $coin->symbol,
                    'reason' => 'missing_ohlcv',
                ];

                continue;
            }

            $analysis = $this->analyzeCoin(
                symbol: $coin->symbol,
                structureKlines: $structureKlines,
                entryKlines: $this->fetchAndStoreOhlcv(
                    coin: $coin,
                    timeframe: $entryTimeframe,
                    limit: self::ENTRY_LIMIT,
                ),
                macroKlines: $this->fetchAndStoreOhlcv(
                    coin: $coin,
                    timeframe: $macroTimeframe,
                    limit: self::MACRO_LIMIT,
                ),
                currentPrice: $coin->current_price,
                structureTimeframe: $structureTimeframe,
                entryTimeframe: $entryTimeframe,
                macroTimeframe: $macroTimeframe,
            );

            if ($analysis['signal'] === null) {
                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => $coin->symbol,
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                ];

                Log::info('[CounterTrendService] Coin rejected by analysis', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                ]);

                continue;
            }

            $signals[] = $analysis['signal'];
        }

        usort(
            $signals,
            static fn(array $left, array $right): int => $right['total_score'] <=> $left['total_score'],
        );

        $ranked = array_values(array_map(
            static fn(array $signal, int $index): array => array_merge($signal, ['rank' => $index + 1]),
            array_slice($signals, 0, self::MAX_RESULTS),
            array_keys(array_slice($signals, 0, self::MAX_RESULTS)),
        ));

        $timestamp = now()->toIso8601String();
        $executionDate = now()->toDateString();

        $standardOutput = [
            'model' => self::MODEL_NAME,
            'version' => self::MODEL_VERSION,
            'timestamp' => $timestamp,
            'execution_date' => $executionDate,
            'signal_count' => count($ranked),
            'results' => $ranked,
        ];

        $supportingData = [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $candidates->count(),
            'shortlisted' => count($ranked),
            'failed_count' => count($failedCoins),
            'requested_timeframes' => [
                'structure' => $structureTimeframe,
                'entry' => $entryTimeframe,
                'macro' => $macroTimeframe,
            ],
            'all_scored_results' => $signals,
            'failed_coins' => $failedCoins,
        ];

        $this->modelOutputStoreService->store(
            modelName: self::MODEL_NAME,
            executionId: $resolvedExecutionId,
            executionDate: $executionDate,
            result: $standardOutput,
            supportingData: $supportingData,
        );

        $result = array_merge($standardOutput, [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $supportingData['evaluated'],
            'shortlisted' => $supportingData['shortlisted'],
        ]);

        $this->notificationService->sendModelExecutionResult([
            'execution_id' => $resolvedExecutionId,
            'model' => self::MODEL_NAME,
            'evaluated' => $supportingData['evaluated'],
            'shortlisted' => $supportingData['shortlisted'],
            'results' => $ranked,
        ]);

        Log::info('[CounterTrendService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
        ]);

        return $result;
    }

    /**
     * Fetch OHLCV from Binance and persist latest payload per coin+interval.
     *
     * @return array<int, array<int, mixed>>
     */
    private function fetchAndStoreOhlcv(Coin $coin, string $timeframe, int $limit): array
    {
        return $this->marketRegimeService->getOhlcvDataForCoin(
            $coin->symbol,
            $timeframe,
            $limit,
        );
    }

    /**
     * Layer 3 candidate selection for Model 1.
     */
    private function filterCoins()
    {
        return Coin::query()
            ->where('is_valid', true)
            ->whereRaw("COALESCE((raw_data->>'market_cap_rank')::int, 999999) BETWEEN ? AND ?", [50, 300])
            ->where(function ($query) {
                $query->where('total_volume', '>=', self::MIN_VOLUME_USD)
                    ->orWhere('volume_24h', '>=', self::MIN_VOLUME_USD);
            })
            ->get();
    }

    /**
     * @param  array<int, array<int, mixed>>  $structureKlines
     * @return array{
     *   signal: array<string, mixed>|null,
     *   rejection_reason: string|null,
     *   rejection_context: array<string, mixed>
     * }
     */
    private function analyzeCoin(
        string $symbol,
        array $structureKlines,
        array $entryKlines,
        array $macroKlines,
        ?float $currentPrice,
        string $structureTimeframe,
        string $entryTimeframe,
        string $macroTimeframe,
    ): array {
        $candles = $this->mapKlinesToCandles($structureKlines);
        $entryCandles = $this->mapKlinesToCandles($entryKlines);
        $macroCandles = $this->mapKlinesToCandles($macroKlines);

        if (count($candles) < 20) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_structure_candles',
                'rejection_context' => [
                    'required' => 20,
                    'actual' => count($candles),
                ],
            ];
        }

        $sweep = $this->detectLiquiditySweep($candles);

        if (! $sweep['detected']) {
            return [
                'signal' => null,
                'rejection_reason' => 'sweep_not_confirmed',
                'rejection_context' => [
                    'direction' => $sweep['direction'],
                ],
            ];
        }

        $mss = $this->detectMarketStructureShift(
            candles: $candles,
            sweepCandleIndex: $sweep['candle_index'],
            sweepDirection: $sweep['direction'],
        );

        if (! $mss['detected']) {
            return [
                'signal' => null,
                'rejection_reason' => 'mss_not_confirmed',
                'rejection_context' => [
                    'direction' => $sweep['direction'],
                ],
            ];
        }

        $macroAligned = $this->isMacroAligned($macroCandles, $sweep['direction']);

        if (! $macroAligned) {
            return [
                'signal' => null,
                'rejection_reason' => 'macro_opposes_sweep',
                'rejection_context' => [
                    'direction' => $sweep['direction'],
                ],
            ];
        }

        $fvg = count($entryCandles) >= 5
            ? $this->detectFvgOrOrderBlock($entryCandles)
            : [
                'detected' => false,
                'zone_high' => null,
                'zone_low' => null,
            ];

        $futuresSymbol = strtoupper($symbol) . 'USDT';
        $oiDecline = $this->detectOiDecline($futuresSymbol);
        $fundingExtreme = $this->detectExtremeFundingRate($futuresSymbol);

        $score = self::SCORE_SWEEP + self::SCORE_MSS;
        $score += $fvg['detected'] ? self::SCORE_ENTRY_ZONE : 0;
        $score += $oiDecline ? self::SCORE_OI : 0;
        $score += $fundingExtreme ? self::SCORE_FUNDING : 0;

        $sweepCandle = $candles[$sweep['candle_index']];
        $stopLoss = $sweep['direction'] === 'bullish'
            ? (float) $sweepCandle['low']
            : (float) $sweepCandle['high'];

        return [
            'signal' => [
                'symbol' => $futuresSymbol,
                'price' => $currentPrice,
                'total_score' => $score,
                'components' => [
                    'liquidity_sweep' => $sweep['direction'],
                    'liquidity_sweep_level' => $sweep['level'],
                    'mss' => $mss['direction'],
                    'fvg_ob_15m' => $fvg['detected'],
                    'oi_declining' => $oiDecline,
                    'extreme_funding' => $fundingExtreme,
                ],
                'metadata' => [
                    'structure_timeframe' => strtoupper($structureTimeframe),
                    'entry_timeframe' => strtoupper($entryTimeframe),
                    'macro_timeframe' => strtoupper($macroTimeframe),
                    'macro_aligned' => $macroAligned,
                    'stop_loss' => $stopLoss,
                    'fvg_zone_15m' => $fvg['detected']
                        ? sprintf('%.6f-%.6f', $fvg['zone_low'], $fvg['zone_high'])
                        : null,
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
        ];
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     */
    private function isMacroAligned(array $candles, string $sweepDirection): bool
    {
        if (count($candles) < 2) {
            return true;
        }

        $lastCandle = $candles[array_key_last($candles)];
        $dailyBody = $lastCandle['close'] - $lastCandle['open'];
        $dailyRange = max($lastCandle['high'] - $lastCandle['low'], 1e-12);
        $opposingBodyStrength = abs($dailyBody) / $dailyRange;

        if ($sweepDirection === 'bullish') {
            return ! ($dailyBody < 0.0 && $opposingBodyStrength >= 0.6);
        }

        return ! ($dailyBody > 0.0 && $opposingBodyStrength >= 0.6);
    }

    /**
     * @param  array<int, array<int, mixed>>  $klines
     * @return array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function mapKlinesToCandles(array $klines): array
    {
        $candles = [];

        foreach ($klines as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            if (! is_numeric($row[0]) || ! is_numeric($row[1]) || ! is_numeric($row[2]) || ! is_numeric($row[3]) || ! is_numeric($row[4]) || ! is_numeric($row[5])) {
                continue;
            }

            $candles[] = [
                'open_time' => (int) $row[0],
                'open' => (float) $row[1],
                'high' => (float) $row[2],
                'low' => (float) $row[3],
                'close' => (float) $row[4],
                'volume' => (float) $row[5],
            ];
        }

        return $candles;
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{0: array<int, array{index: int, price: float}>, 1: array<int, array{index: int, price: float}>}
     */
    private function findSwingPoints(array $candles, int $lookback = 5): array
    {
        $swingHighs = [];
        $swingLows = [];
        $count = count($candles);

        for ($i = $lookback; $i < $count - $lookback; $i++) {
            $high = $candles[$i]['high'];
            $low = $candles[$i]['low'];
            $isSwingHigh = true;
            $isSwingLow = true;

            for ($j = $i - $lookback; $j <= $i + $lookback; $j++) {
                if ($j === $i) {
                    continue;
                }

                if ($candles[$j]['high'] >= $high) {
                    $isSwingHigh = false;
                }

                if ($candles[$j]['low'] <= $low) {
                    $isSwingLow = false;
                }

                if (! $isSwingHigh && ! $isSwingLow) {
                    break;
                }
            }

            if ($isSwingHigh) {
                $swingHighs[] = ['index' => $i, 'price' => $high];
            }

            if ($isSwingLow) {
                $swingLows[] = ['index' => $i, 'price' => $low];
            }
        }

        return [$swingHighs, $swingLows];
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{detected: bool, direction: string|null, level: float|null, candle_index: int|null}
     */
    private function detectLiquiditySweep(array $candles, int $scanRecent = 10): array
    {
        if (count($candles) < 20) {
            return [
                'detected' => false,
                'direction' => null,
                'level' => null,
                'candle_index' => null,
            ];
        }

        $count = count($candles);
        $start = max($count - $scanRecent, 10);

        for ($i = $count - 1; $i >= $start; $i--) {
            $reference = array_slice($candles, 0, $i);
            [$swingHighs, $swingLows] = $this->findSwingPoints($reference, 5);
            $candle = $candles[$i];

            if ($swingHighs !== []) {
                $level = $swingHighs[array_key_last($swingHighs)]['price'];
                if ($candle['high'] > $level && $candle['close'] < $level) {
                    return [
                        'detected' => true,
                        'direction' => 'bearish',
                        'level' => $level,
                        'candle_index' => $i,
                    ];
                }
            }

            if ($swingLows !== []) {
                $level = $swingLows[array_key_last($swingLows)]['price'];
                if ($candle['low'] < $level && $candle['close'] > $level) {
                    return [
                        'detected' => true,
                        'direction' => 'bullish',
                        'level' => $level,
                        'candle_index' => $i,
                    ];
                }
            }
        }

        return [
            'detected' => false,
            'direction' => null,
            'level' => null,
            'candle_index' => null,
        ];
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{detected: bool, direction: string|null}
     */
    private function detectMarketStructureShift(array $candles, ?int $sweepCandleIndex, ?string $sweepDirection): array
    {
        if ($sweepCandleIndex === null || $sweepDirection === null) {
            return [
                'detected' => false,
                'direction' => null,
            ];
        }

        $reference = array_slice($candles, 0, $sweepCandleIndex);

        if (count($reference) < 10) {
            return [
                'detected' => false,
                'direction' => null,
            ];
        }

        [$swingHighs, $swingLows] = $this->findSwingPoints($reference, 5);

        for ($i = $sweepCandleIndex + 1; $i < count($candles); $i++) {
            $candle = $candles[$i];

            if ($sweepDirection === 'bullish' && $swingHighs !== []) {
                $lastSwingHigh = $swingHighs[array_key_last($swingHighs)]['price'];
                if ($candle['close'] > $lastSwingHigh) {
                    return [
                        'detected' => true,
                        'direction' => 'bullish',
                    ];
                }
            }

            if ($sweepDirection === 'bearish' && $swingLows !== []) {
                $lastSwingLow = $swingLows[array_key_last($swingLows)]['price'];
                if ($candle['close'] < $lastSwingLow) {
                    return [
                        'detected' => true,
                        'direction' => 'bearish',
                    ];
                }
            }
        }

        return [
            'detected' => false,
            'direction' => null,
        ];
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{detected: bool, zone_high: float|null, zone_low: float|null}
     */
    private function detectFvgOrOrderBlock(array $candles): array
    {
        if (count($candles) < 5) {
            return [
                'detected' => false,
                'zone_high' => null,
                'zone_low' => null,
            ];
        }

        $lastPrice = $candles[array_key_last($candles)]['close'];
        $searchStart = max(0, count($candles) - 22);

        for ($i = $searchStart; $i < count($candles) - 2; $i++) {
            $c1 = $candles[$i];
            $c3 = $candles[$i + 2];

            if ($c1['high'] < $c3['low']) {
                $zoneLow = $c1['high'];
                $zoneHigh = $c3['low'];
                if ($zoneLow <= $lastPrice && $lastPrice <= $zoneHigh) {
                    return [
                        'detected' => true,
                        'zone_high' => $zoneHigh,
                        'zone_low' => $zoneLow,
                    ];
                }
            }

            if ($c1['low'] > $c3['high']) {
                $zoneLow = $c3['high'];
                $zoneHigh = $c1['low'];
                if ($zoneLow <= $lastPrice && $lastPrice <= $zoneHigh) {
                    return [
                        'detected' => true,
                        'zone_high' => $zoneHigh,
                        'zone_low' => $zoneLow,
                    ];
                }
            }
        }

        return [
            'detected' => false,
            'zone_high' => null,
            'zone_low' => null,
        ];
    }

    private function detectOiDecline(string $futuresSymbol): bool
    {
        $history = $this->marketRegimeService->getCounterTrendOpenInterestHistoryForCoin(
            symbol: $futuresSymbol,
            interval: '1hour',
            limit: 24,
        );

        if (count($history) < 2) {
            return false;
        }

        $prior = $history[count($history) - 2]['open_interest'];
        $recent = $history[array_key_last($history)]['open_interest'];

        if ($prior <= 0.0) {
            return false;
        }

        return (($prior - $recent) / $prior) >= 0.05;
    }

    private function detectExtremeFundingRate(string $futuresSymbol): bool
    {
        $history = $this->marketRegimeService->getCounterTrendFundingRateHistoryForCoin(
            symbol: $futuresSymbol,
            limit: 10,
        );

        if ($history === []) {
            return false;
        }

        $latestRate = $history[array_key_last($history)]['funding_rate'];

        return abs($latestRate) >= 0.001;
    }
}
