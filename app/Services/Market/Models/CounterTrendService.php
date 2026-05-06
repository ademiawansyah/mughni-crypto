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

    private const STRUCTURE_LIMIT = 120;

    private const ENTRY_LIMIT = 120;

    private const SCORE_SWEEP = 40;

    private const SCORE_MSS = 30;

    private const SCORE_ENTRY_ZONE = 15;

    private const SCORE_OI = 8;

    private const SCORE_FUNDING = 7;

    private const SCORE_MAX = 100;

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
    ): array {
        $resolvedExecutionId = $executionId ?: Str::uuid()->toString();

        Log::info('[CounterTrendService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'structure_timeframe' => $structureTimeframe,
            'entry_timeframe' => $entryTimeframe,
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

            $entryKlines = $this->fetchAndStoreOhlcv(
                coin: $coin,
                timeframe: $entryTimeframe,
                limit: self::ENTRY_LIMIT,
            );

            if ($structureKlines === [] || $entryKlines === []) {
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
                entryKlines: $entryKlines,
                structureTimeframe: $structureTimeframe,
                entryTimeframe: $entryTimeframe,
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
        $klines = $this->marketRegimeService->getOhlcvDataForCoin(
            $coin->symbol,
            $timeframe,
            $limit,
        );

        return $klines;
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
     * @param  array<int, array<int, mixed>>  $entryKlines
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
        string $structureTimeframe,
        string $entryTimeframe,
    ): array {
        $structureCandles = $this->mapKlinesToCandles($structureKlines);
        $entryCandles = $this->mapKlinesToCandles($entryKlines);

        if (count($structureCandles) < 30) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_structure_candles',
                'rejection_context' => [
                    'required' => 30,
                    'actual' => count($structureCandles),
                ],
            ];
        }

        if (count($entryCandles) < 30) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_entry_candles',
                'rejection_context' => [
                    'required' => 30,
                    'actual' => count($entryCandles),
                ],
            ];
        }

        $sweep = $this->detectLiquiditySweep($structureCandles);

        if (! $sweep['confirmed']) {
            return [
                'signal' => null,
                'rejection_reason' => 'sweep_not_confirmed',
                'rejection_context' => [
                    'direction' => $sweep['direction'],
                ],
            ];
        }

        $mss = $this->detectMarketStructureShift($entryCandles, $sweep['direction']);

        Log::info('[CounterTrendService] Analyzing coin', [
            'symbol' => $symbol,
            'sweep_confirmed' => $sweep['confirmed'],
            'sweep_direction' => $sweep['direction'],
            'mss_confirmed' => $mss,
        ]);

        if (! $mss) {
            return [
                'signal' => null,
                'rejection_reason' => 'mss_not_confirmed',
                'rejection_context' => [
                    'direction' => $sweep['direction'],
                ],
            ];
        }

        $hasEntryZone = $this->hasEntryZoneFromFvgOrOrderBlock($entryCandles, $sweep['direction']);

        $futuresSymbol = strtoupper($symbol) . 'USDT';
        $oiDecline = $this->detectOiDecline($futuresSymbol);
        $fundingExtreme = $this->detectExtremeFundingRate($futuresSymbol);

        $sweepScore = self::SCORE_SWEEP;
        $mssScore = self::SCORE_MSS;
        $entryZoneScore = $hasEntryZone ? self::SCORE_ENTRY_ZONE : 0;
        $oiScore = $oiDecline ? self::SCORE_OI : 0;
        $fundingScore = $fundingExtreme ? self::SCORE_FUNDING : 0;
        $totalScoreRaw = $sweepScore + $mssScore + $entryZoneScore + $oiScore + $fundingScore;
        $totalScoreNormalized = (int) round(($totalScoreRaw / self::SCORE_MAX) * 100);

        $lastCandle = $entryCandles[array_key_last($entryCandles)];
        $currentPrice = (float) $lastCandle['close'];

        return [
            'signal' => [
                'symbol' => $futuresSymbol,
                'price' => round($currentPrice, 8),
                'total_score' => $totalScoreNormalized,
                'components' => [
                    'liquidity_sweep' => $sweep['confirmed'],
                    'mss' => $mss,
                    'fvg_or_ob' => $hasEntryZone,
                    'oi_decline' => $oiDecline,
                    'funding_extreme' => $fundingExtreme,
                    'score_breakdown' => [
                        'sweep' => $sweepScore,
                        'mss' => $mssScore,
                        'fvg_or_ob' => $entryZoneScore,
                        'oi' => $oiScore,
                        'funding' => $fundingScore,
                        'total_raw' => $totalScoreRaw,
                        'total_normalized' => $totalScoreNormalized,
                        'max_score' => self::SCORE_MAX,
                    ],
                ],
                'metadata' => [
                    'strategy' => 'counter_trend',
                    'direction' => $sweep['direction'],
                    'entry_timeframe' => strtoupper($entryTimeframe),
                    'structure_timeframe' => strtoupper($structureTimeframe),
                    'reason' => sprintf(
                        'Sweep %s + MSS confirmed%s',
                        $sweep['direction'],
                        $hasEntryZone ? ' + entry zone' : ''
                    ),
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
        ];
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
     * @return array{confirmed: bool, direction: string}
     */
    private function detectLiquiditySweep(array $candles): array
    {
        $lastIndex = array_key_last($candles);

        if ($lastIndex === null || $lastIndex < 21) {
            return ['confirmed' => false, 'direction' => 'none'];
        }

        $last = $candles[$lastIndex];
        $lookback = array_slice($candles, -22, 21);

        $recentHigh = max(array_column($lookback, 'high'));
        $recentLow = min(array_column($lookback, 'low'));

        $sweptHigh = $last['high'] > $recentHigh && $last['close'] < $recentHigh;
        $sweptLow = $last['low'] < $recentLow && $last['close'] > $recentLow;

        if ($sweptLow) {
            return ['confirmed' => true, 'direction' => 'bullish'];
        }

        if ($sweptHigh) {
            return ['confirmed' => true, 'direction' => 'bearish'];
        }

        return ['confirmed' => false, 'direction' => 'none'];
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     */
    private function detectMarketStructureShift(array $candles, string $direction): bool
    {
        $lastIndex = array_key_last($candles);

        if ($lastIndex === null || $lastIndex < 21) {
            return false;
        }

        $last = $candles[$lastIndex];
        $lookback = array_slice($candles, -22, 21);
        $recentHigh = max(array_column($lookback, 'high'));
        $recentLow = min(array_column($lookback, 'low'));

        if ($direction === 'bullish') {
            return $last['close'] > $recentHigh;
        }

        if ($direction === 'bearish') {
            return $last['close'] < $recentLow;
        }

        return false;
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     */
    private function hasEntryZoneFromFvgOrOrderBlock(array $candles, string $direction): bool
    {
        if (count($candles) < 6) {
            return false;
        }

        $latest = $candles[array_key_last($candles)];
        $currentClose = (float) $latest['close'];

        $fvgFound = false;
        for ($i = count($candles) - 1; $i >= max(2, count($candles) - 15); $i--) {
            $left = $candles[$i - 2];
            $right = $candles[$i];

            if ($direction === 'bullish' && $left['high'] < $right['low']) {
                $fvgFound = true;

                break;
            }

            if ($direction === 'bearish' && $left['low'] > $right['high']) {
                $fvgFound = true;

                break;
            }
        }

        $obFound = false;
        $recentCandles = array_slice($candles, -10);

        foreach ($recentCandles as $candle) {
            $body = abs($candle['close'] - $candle['open']);
            $range = max(0.00000001, $candle['high'] - $candle['low']);
            $bodyRatio = $body / $range;

            if ($direction === 'bullish' && $candle['close'] < $candle['open'] && $bodyRatio >= 0.5) {
                if ($currentClose >= $candle['low'] && $currentClose <= $candle['high']) {
                    $obFound = true;

                    break;
                }
            }

            if ($direction === 'bearish' && $candle['close'] > $candle['open'] && $bodyRatio >= 0.5) {
                if ($currentClose >= $candle['low'] && $currentClose <= $candle['high']) {
                    $obFound = true;

                    break;
                }
            }
        }

        return $fvgFound || $obFound;
    }

    /**
     * Detect whether open interest declined ≥5% over the recent period,
     * indicating exhaustion concurrent with the liquidity sweep.
     *
     * Uses Binance Futures /fapi/v1/openInterestHist (1H period, last 5 snapshots).
     * Returns false when futures data is unavailable (graceful degradation).
     */
    private function detectOiDecline(string $futuresSymbol): bool
    {
        $history = $this->marketRegimeService->getOpenInterestHistoryForCoin(
            symbol: $futuresSymbol,
            period: '1h',
            limit: 5,
        );

        if ($history === null || count($history) < 2) {
            return false;
        }

        $earliest = $history[0]['sumOpenInterest'];
        $latest = $history[array_key_last($history)]['sumOpenInterest'];

        if ($earliest <= 0.0) {
            return false;
        }

        // Require ≥5% OI decline to qualify as exhaustion confirmation
        return ($earliest - $latest) / $earliest >= 0.05;
    }

    /**
     * Detect whether the current funding rate is extreme (< -0.1% or > +0.1%),
     * indicating one-sided positioning that supports a reversal.
     *
     * Uses Binance Futures /fapi/v1/fundingRate.
     * Returns false when futures data is unavailable (graceful degradation).
     */
    private function detectExtremeFundingRate(string $futuresSymbol): bool
    {
        $data = $this->marketRegimeService->getLatestFundingRateForCoin($futuresSymbol);

        if ($data === null) {
            return false;
        }

        // Threshold: |funding rate| > 0.001 (0.1% per 8H)
        return abs($data['funding_rate']) > 0.001;
    }
}
