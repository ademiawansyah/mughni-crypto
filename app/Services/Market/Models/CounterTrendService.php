<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Services\Market\DTO\CounterTrendAnalysisDTO;
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

    private const SCORE_CVD = 5;

    private const DERIVATIVES_UNAVAILABLE_PENALTY = 0.85;

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
        string $structureTimeframe = '4h',
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
        $passedDtos = [];
        $rejectedDtos = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            try {
                $futuresSymbol = strtoupper($coin->symbol).'USDT';

                if (! $this->marketRegimeService->hasPerpetualUsdtSymbol($futuresSymbol)) {
                    $rejectedDto = CounterTrendAnalysisDTO::rejected(
                        executionId: $resolvedExecutionId,
                        coinId: $coin->id,
                        symbol: $coin->symbol,
                        rejectionReason: 'perpetual_symbol_unavailable',
                        rejectionContext: [
                            'symbol' => $futuresSymbol,
                        ],
                        score: 0.0,
                        price: $coin->current_price,
                    );
                    $rejectedDtos[] = $rejectedDto;
                    $failedCoins[] = [
                        'id' => $rejectedDto->coin_id,
                        'symbol' => $rejectedDto->symbol,
                        'reason' => $rejectedDto->rejection_reason,
                        'context' => $rejectedDto->rejection_context,
                        'price' => $rejectedDto->price,
                        'score' => $rejectedDto->score,
                    ];

                    continue;
                }

                $structureKlines = $this->fetchAndStoreOhlcv(
                    futuresSymbol: $futuresSymbol,
                    timeframe: $structureTimeframe,
                    limit: self::STRUCTURE_LIMIT,
                );

                if ($structureKlines === []) {
                    Log::warning('[CounterTrendService] Skipped coin due to missing OHLCV', [
                        'execution_id' => $resolvedExecutionId,
                        'symbol' => $coin->symbol,
                    ]);

                    $rejectedDto = CounterTrendAnalysisDTO::rejected(
                        executionId: $resolvedExecutionId,
                        coinId: $coin->id,
                        symbol: $coin->symbol,
                        rejectionReason: 'missing_ohlcv',
                        rejectionContext: [],
                        score: 0.0,
                        price: $coin->current_price,
                    );
                    $rejectedDtos[] = $rejectedDto;
                    $failedCoins[] = [
                        'id' => $rejectedDto->coin_id,
                        'symbol' => $rejectedDto->symbol,
                        'reason' => $rejectedDto->rejection_reason,
                        'context' => $rejectedDto->rejection_context,
                        'price' => $rejectedDto->price,
                        'score' => $rejectedDto->score,
                    ];

                    continue;
                }

                $analysis = $this->analyzeCoin(
                    futuresSymbol: $futuresSymbol,
                    structureKlines: $structureKlines,
                    entryKlines: $this->fetchAndStoreOhlcv(
                        futuresSymbol: $futuresSymbol,
                        timeframe: $entryTimeframe,
                        limit: self::ENTRY_LIMIT,
                    ),
                    macroKlines: $this->fetchAndStoreOhlcv(
                        futuresSymbol: $futuresSymbol,
                        timeframe: $macroTimeframe,
                        limit: self::MACRO_LIMIT,
                    ),
                    currentPrice: $coin->current_price,
                    structureTimeframe: $structureTimeframe,
                    entryTimeframe: $entryTimeframe,
                    macroTimeframe: $macroTimeframe,
                );
            } catch (\Throwable $exception) {
                Log::warning('[CounterTrendService] Coin analysis failed and was skipped', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                    'error' => $exception->getMessage(),
                ]);

                $rejectedDto = CounterTrendAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: $coin->symbol,
                    rejectionReason: 'analysis_exception',
                    rejectionContext: [
                        'message' => $exception->getMessage(),
                    ],
                    score: 0.0,
                    price: $coin->current_price,
                );
                $rejectedDtos[] = $rejectedDto;
                $failedCoins[] = [
                    'id' => $rejectedDto->coin_id,
                    'symbol' => $rejectedDto->symbol,
                    'reason' => $rejectedDto->rejection_reason,
                    'context' => $rejectedDto->rejection_context,
                    'price' => $rejectedDto->price,
                    'score' => $rejectedDto->score,
                ];

                continue;
            }

            if ($analysis['signal'] === null) {
                $rejectedDto = CounterTrendAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: $coin->symbol,
                    rejectionReason: $analysis['rejection_reason'],
                    rejectionContext: $analysis['rejection_context'],
                    score: 0.0,
                    price: $coin->current_price,
                );
                $rejectedDtos[] = $rejectedDto;
                $failedCoins[] = [
                    'id' => $rejectedDto->coin_id,
                    'symbol' => $rejectedDto->symbol,
                    'reason' => $rejectedDto->rejection_reason,
                    'context' => $rejectedDto->rejection_context,
                    'price' => $rejectedDto->price,
                    'score' => $rejectedDto->score,
                ];

                Log::info('[CounterTrendService] Coin rejected by analysis', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                ]);

                continue;
            }

            $passedDto = CounterTrendAnalysisDTO::passed(
                executionId: $resolvedExecutionId,
                coinId: $coin->id,
                symbol: $coin->symbol,
                score: (float) $analysis['signal']['total_score'],
                price: $coin->current_price,
                signal: $analysis['signal'],
                components: $analysis['signal']['components'],
                metadata: $analysis['signal']['metadata'],
            );
            $passedDtos[] = $passedDto;
        }

        // Sort passed DTOs by score (descending)
        usort(
            $passedDtos,
            static fn (CounterTrendAnalysisDTO $left, CounterTrendAnalysisDTO $right): int => $right->score <=> $left->score,
        );

        // Convert top MAX_RESULTS DTOs back to arrays for output (maintaining backward compatibility)
        $topDtos = array_slice($passedDtos, 0, self::MAX_RESULTS);
        $ranked = array_values(array_map(
            static function (CounterTrendAnalysisDTO $dto, int $index): array {
                $serialized = $dto->signal ?? [];
                $serialized['rank'] = $index + 1;

                return $serialized;
            },
            $topDtos,
            array_keys($topDtos),
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
            'all_scored_results' => $ranked,
            'analysis_results' => array_values(array_map(
                static fn (CounterTrendAnalysisDTO $dto): array => $dto->toArray(),
                array_merge($passedDtos, $rejectedDtos),
            )),
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
    private function fetchAndStoreOhlcv(string $futuresSymbol, string $timeframe, int $limit): array
    {
        return $this->marketRegimeService->getFuturesOhlcvDataForCoin(
            $futuresSymbol,
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
            ->where('total_volume', '>=', self::MIN_VOLUME_USD)
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
        string $futuresSymbol,
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
                'score' => 0,
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
                'score' => 0,
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
                'score' => 0,
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
                'score' => 0,
            ];
        }

        $fvg = count($entryCandles) >= 5
            ? $this->detectFvgOrOrderBlock($entryCandles)
            : [
                'detected' => false,
                'zone_high' => null,
                'zone_low' => null,
            ];

        $oiData = $this->marketRegimeService->getCounterTrendOpenInterestHistoryForCoin(
            symbol: $futuresSymbol,
            interval: '1hour',
            limit: 24,
        );
        $fundingData = $this->marketRegimeService->getCounterTrendFundingRateHistoryForCoin(
            symbol: $futuresSymbol,
            limit: 10,
        );
        $coinalyzeAvailable = $oiData !== [] || $fundingData !== [];
        $derivativesSkipped = $oiData === [] && $fundingData === [];
        $oiDecline = $this->detectOiDecline($oiData);
        $fundingExtreme = $this->detectExtremeFundingRate($fundingData);
        $cvdMetrics = $this->marketRegimeService->getCvdMetricsForCoin($futuresSymbol, 500);
        $cvdPositive = ($cvdMetrics['cvd_slope'] ?? 0.0) > 0.0;

        $score = self::SCORE_SWEEP + self::SCORE_MSS;
        $score += $fvg['detected'] ? self::SCORE_ENTRY_ZONE : 0;
        $score += $oiDecline ? self::SCORE_OI : 0;
        $score += $fundingExtreme ? self::SCORE_FUNDING : 0;
        $score += $cvdPositive ? self::SCORE_CVD : 0;

        if ($derivativesSkipped) {
            $score *= self::DERIVATIVES_UNAVAILABLE_PENALTY;
        }

        $score = round($score, 2);

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
                    'cvd_positive' => $cvdPositive,
                    'derivatives_skipped' => $derivativesSkipped,
                ],
                'metadata' => [
                    'structure_timeframe' => strtoupper($structureTimeframe),
                    'entry_timeframe' => strtoupper($entryTimeframe),
                    'macro_timeframe' => strtoupper($macroTimeframe),
                    'strategy' => self::MODEL_NAME,
                    'macro_aligned' => $macroAligned,
                    'coinalyze_available' => $coinalyzeAvailable,
                    'ohlcv_source' => 'binance_futures',
                    'derivatives_penalty_applied' => $derivativesSkipped,
                    'derivatives_penalty_factor' => $derivativesSkipped ? self::DERIVATIVES_UNAVAILABLE_PENALTY : 1.0,
                    'oi_points' => count($oiData),
                    'funding_points' => count($fundingData),
                    'cvd_slope' => $cvdMetrics['cvd_slope'] ?? null,
                    'cvd' => $cvdMetrics['cvd'] ?? null,
                    'stop_loss' => $stopLoss,
                    'fvg_zone_15m' => $fvg['detected']
                        ? sprintf('%.6f–%.6f', $fvg['zone_low'], $fvg['zone_high'])
                        : null,
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
            'score' => $score,
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

    /**
     * @param  array<int, array{timestamp: int, open_interest: float}>  $history
     */
    private function detectOiDecline(array $history): bool
    {
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

    /**
     * @param  array<int, array{timestamp: int, funding_rate: float}>  $history
     */
    private function detectExtremeFundingRate(array $history): bool
    {
        if ($history === []) {
            return false;
        }

        $latestRate = $history[array_key_last($history)]['funding_rate'];

        return abs($latestRate) >= 0.001;
    }
}
