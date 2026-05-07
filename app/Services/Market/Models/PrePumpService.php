<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Models\CoinMarketData;
use App\Services\Market\MarketRegimeService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PrePumpService
{
    private const MODEL_NAME = 'pre_pump';

    private const MODEL_VERSION = '2.0';

    private const MIN_VOLUME_USD = 10_000_000;

    private const MAX_RESULTS = 10;

    /**
     * 4H candles to fetch: ~33 days of data for ATR 30-day baseline.
     */
    private const STRUCTURE_LIMIT = 200;

    /**
     * 1H candles to fetch: ~5 days for entry signals (RS, CVD).
     */
    private const ENTRY_LIMIT = 120;

    /**
     * ATR short-period (spec: ATR 14).
     */
    private const ATR_PERIOD = 14;

    /**
     * ATR baseline look-back window (up to 90 ATR values ≈ 30 days on 4H).
     */
    private const ATR_BASELINE_PERIOD = 90;

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Execute Model 2 (Pre-Pump) scanning pipeline.
     *
     * Fetches 4H OHLCV (structure) and 1H OHLCV (entry signals) for each
     * candidate coin, runs five indicator-based component scorers, and
     * persists the top-10 results via ModelOutputStoreService.
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
    public function execute(?string $executionId = null): array
    {
        $resolvedExecutionId = $executionId ?: Str::uuid()->toString();
        $minimumScore = (float) config('models.pre_pump.min_score', 65);

        Log::info('[PrePumpService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'minimum_score' => $minimumScore,
        ]);

        $candidates = $this->filterCoins();
        $signals = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            $structureKlines = $this->fetchAndStoreOhlcv($coin, '4h', self::STRUCTURE_LIMIT);
            $entryKlines = $this->fetchAndStoreOhlcv($coin, '1h', self::ENTRY_LIMIT);

            if ($structureKlines === [] || $entryKlines === []) {
                Log::warning('[PrePumpService] Skipped coin due to missing OHLCV', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                ]);

                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => $coin->symbol,
                    'reason' => 'missing_ohlcv',
                    'context' => [],
                    'score' => 0,
                    'price' => $coin->current_price,
                ];

                continue;
            }

            $analysis = $this->analyzeCoin($coin, $structureKlines, $entryKlines, $minimumScore);

            if ($analysis['signal'] === null) {
                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => $coin->symbol,
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                    'score' => $analysis['score'],
                    'price' => $coin->current_price,
                ];

                Log::info('[PrePumpService] Coin rejected by analysis', [
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

        $limitedSignals = array_slice($signals, 0, self::MAX_RESULTS);

        $ranked = array_values(array_map(
            static fn(array $signal, int $index): array => array_merge($signal, ['rank' => $index + 1]),
            $limitedSignals,
            array_keys($limitedSignals),
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
            'minimum_score' => $minimumScore,
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

        Log::info('[PrePumpService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
        ]);

        return $result;
    }

    /**
     * Layer 3 candidate selection for Model 2.
     */
    private function filterCoins()
    {
        return Coin::query()
            ->where('is_valid', true)
            ->whereRaw("COALESCE((raw_data->>'market_cap_rank')::int, 999999) BETWEEN ? AND ?", [20, 150])
            ->where(function ($query) {
                $query->where('total_volume', '>=', self::MIN_VOLUME_USD)
                    ->orWhere('volume_24h', '>=', self::MIN_VOLUME_USD);
            })
            ->get();
    }

    /**
     * Analyze a coin using real OHLCV-based Pre-Pump indicators.
     *
     * Components (config keys):
     *   - funding (0.35): Persistent negative funding proxy from 4H candle sentiment
     *   - atr_compression (0.25): ATR 14 vs 30-day baseline — volatility compression
     *   - oi (0.20): OI accumulation proxy — volume rising while price sideways
     *   - rs (0.10): Relative strength proxy — coin trend vs own prior trend
     *   - cvd (0.10): CVD divergence — buy pressure rising while price flat/declining
     *
     * @param  array<int, array<int, mixed>>  $structureKlines  4H klines
     * @param  array<int, array<int, mixed>>  $entryKlines  1H klines
     * @return array{
     *   signal: array<string, mixed>|null,
     *   rejection_reason: string|null,
     *   rejection_context: array<string, mixed>,
     *   score: float
     * }
     */
    private function analyzeCoin(Coin $coin, array $structureKlines, array $entryKlines, float $minimumScore): array
    {
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
                'score' => 0,
            ];
        }

        if (count($entryCandles) < 24) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_entry_candles',
                'rejection_context' => [
                    'required' => 24,
                    'actual' => count($entryCandles),
                ],
                'score' => 0,
            ];
        }

        // Component scores (0–100 each), weighted per config
        $fundingScore = $this->scoreFunding($structureCandles);
        $atrCompressionScore = $this->scoreAtrCompression($structureCandles);
        $oiScore = $this->scoreOiAccumulation($structureCandles);
        $rsScore = $this->scoreRelativeStrength($entryCandles);
        $cvdScore = $this->scoreCvdDivergence($entryCandles);

        $weights = [
            'funding' => (float) config('models.pre_pump.scoring.funding', 0.35),
            'atr_compression' => (float) config('models.pre_pump.scoring.atr_compression', 0.25),
            'oi' => (float) config('models.pre_pump.scoring.oi', 0.20),
            'rs' => (float) config('models.pre_pump.scoring.rs', 0.10),
            'cvd' => (float) config('models.pre_pump.scoring.cvd', 0.10),
        ];

        $componentScores = [
            'funding' => $fundingScore,
            'atr_compression' => $atrCompressionScore,
            'oi' => $oiScore,
            'rs' => $rsScore,
            'cvd' => $cvdScore,
        ];

        $totalScore = round(
            ($fundingScore * $weights['funding'])
                + ($atrCompressionScore * $weights['atr_compression'])
                + ($oiScore * $weights['oi'])
                + ($rsScore * $weights['rs'])
                + ($cvdScore * $weights['cvd']),
            2,
        );

        Log::info('[PrePumpService] Analyzed coin', [
            'symbol' => $coin->symbol,
            'total_score' => $totalScore,
            'components' => $componentScores,
        ]);

        if ($totalScore < $minimumScore) {
            return [
                'signal' => null,
                'rejection_reason' => 'below_minimum_score',
                'rejection_context' => [
                    'minimum_score' => $minimumScore,
                    'total_score' => $totalScore,
                    'components' => $componentScores,
                ],
                'score' => $totalScore,
            ];
        }

        $lastCandle = $entryCandles[array_key_last($entryCandles)];
        $currentPrice = (float) $lastCandle['close'];

        $symbol = strtoupper($coin->symbol);
        $pairSymbol = str_ends_with($symbol, 'USDT') ? $symbol : $symbol . 'USDT';

        return [
            'signal' => [
                'symbol' => $pairSymbol,
                'price' => round($currentPrice, 8),
                'total_score' => $totalScore,
                'components' => $componentScores,
                'metadata' => [
                    'entry_point' => round($currentPrice, 8),
                    'stop_loss' => round($currentPrice * 0.97, 8),
                    'entry_timeframe' => '1H',
                    'structure_timeframe' => '4H',
                    'strategy' => 'pre_pump',
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
            'score' => $totalScore,
        ];
    }

    /**
     * Fetch OHLCV from Binance and persist the latest payload per coin + interval.
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

        if ($klines === []) {
            return [];
        }

        CoinMarketData::query()->updateOrCreate(
            [
                'coin_id' => $coin->id,
                'data_type' => 'ohlcv',
                'source' => 'binance',
                'interval' => $timeframe,
            ],
            ['data' => $klines],
        );

        return $klines;
    }

    /**
     * Score funding rate proxy (0–100).
     *
     * Persistent negative funding = short sellers dominate = squeeze potential.
     * Proxied from 4H OHLCV: the last 9 candles cover roughly 3 × 8H periods
     * (spec: Funding < -0.05% per 8H for 3 consecutive periods).
     *
     * A high proportion of bearish candles (close < open) on 4H signals
     * short-side dominance and negative funding pressure.
     *
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  4H candles
     */
    private function scoreFunding(array $candles): float
    {
        // 9 × 4H ≈ 3 × 8H periods (spec threshold window)
        $lookback = min(9, count($candles));
        $recentCandles = array_slice($candles, -$lookback);

        $bearishCount = 0;
        $totalPriceChangeRatio = 0.0;

        foreach ($recentCandles as $candle) {
            $range = $candle['open'] > 0
                ? ($candle['close'] - $candle['open']) / $candle['open']
                : 0.0;

            $totalPriceChangeRatio += $range;

            if ($candle['close'] < $candle['open']) {
                $bearishCount++;
            }
        }

        $total = count($recentCandles);
        $bearishRatio = $total > 0 ? $bearishCount / $total : 0.0;
        $avgPriceChange = $total > 0 ? $totalPriceChangeRatio / $total : 0.0;

        // 6+ of 9 candles bearish = persistent short pressure = high squeeze potential
        if ($bearishRatio >= 0.67) {
            return min(100.0, 75.0 + abs(min($avgPriceChange, 0.0)) * 1000);
        }

        if ($avgPriceChange < -0.005) {
            // Price declining > 0.5% per candle on average
            return 72.0;
        }

        if ($avgPriceChange < 0.0) {
            // Slight negative bias — mild short pressure
            return 65.0;
        }

        if ($avgPriceChange < 0.005) {
            // Sideways — neutral funding
            return 55.0;
        }

        // Price rising = positive funding (unfavourable for squeeze)
        return max(20.0, 50.0 - min($avgPriceChange, 0.05) * 400);
    }

    /**
     * Score ATR compression (0–100).
     *
     * Low ATR relative to 30-day baseline = volatility compression = pre-explosion setup.
     * Spec: ATR 14-period below 30-day rolling average (4H OHLCV).
     * Higher score = more compressed = stronger signal.
     *
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  4H candles
     */
    private function scoreAtrCompression(array $candles): float
    {
        if (count($candles) < self::ATR_PERIOD + 1) {
            return 50.0;
        }

        $atrValues = $this->calculateAtr($candles, self::ATR_PERIOD);

        if ($atrValues === []) {
            return 50.0;
        }

        $currentAtr = (float) end($atrValues);

        // Baseline = average of up to ATR_BASELINE_PERIOD most-recent ATR values
        $baselineCount = min(self::ATR_BASELINE_PERIOD, count($atrValues));
        $baselineSlice = array_slice($atrValues, -$baselineCount);
        $baselineAtr = array_sum($baselineSlice) / $baselineCount;

        if ($baselineAtr <= 0.0) {
            return 50.0;
        }

        $compressionRatio = $currentAtr / $baselineAtr;

        return match (true) {
            $compressionRatio <= 0.50 => 100.0,
            $compressionRatio <= 0.70 => 90.0,
            $compressionRatio <= 0.85 => 80.0,
            $compressionRatio <= 1.00 => 65.0,
            $compressionRatio <= 1.20 => 45.0,
            default => max(10.0, 45.0 - ($compressionRatio - 1.2) * 100),
        };
    }

    /**
     * Score OI accumulation proxy (0–100).
     *
     * OI rising while price is sideways = smart money building positions.
     * Spec: OI rises >10% in 24H, price in range <3% (4H OHLCV).
     * Proxied via volume trend (recent 6 candles = 24H on 4H) vs prior 6 candles.
     *
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  4H candles
     */
    private function scoreOiAccumulation(array $candles): float
    {
        if (count($candles) < 12) {
            return 50.0;
        }

        $recent = array_slice($candles, -6);
        $prior = array_slice($candles, -12, 6);

        $recentAvgVolume = array_sum(array_column($recent, 'volume')) / 6;
        $priorAvgVolume = array_sum(array_column($prior, 'volume')) / 6;

        if ($priorAvgVolume <= 0.0) {
            return 50.0;
        }

        $volumeGrowth = ($recentAvgVolume - $priorAvgVolume) / $priorAvgVolume;

        $recentAvgClose = array_sum(array_column($recent, 'close')) / 6;
        $priceRange = $recentAvgClose > 0
            ? (max(array_column($recent, 'high')) - min(array_column($recent, 'low'))) / $recentAvgClose
            : 0.1;

        $volumeScore = match (true) {
            $volumeGrowth >= 0.20 => 100.0,
            $volumeGrowth >= 0.10 => 85.0,
            $volumeGrowth >= 0.00 => 65.0,
            $volumeGrowth >= -0.15 => 45.0,
            default => 25.0,
        };

        $priceRangeScore = match (true) {
            $priceRange <= 0.02 => 100.0,
            $priceRange <= 0.03 => 90.0,
            $priceRange <= 0.05 => 70.0,
            $priceRange <= 0.08 => 50.0,
            default => 25.0,
        };

        return round(($volumeScore * 0.6) + ($priceRangeScore * 0.4), 2);
    }

    /**
     * Score Relative Strength vs market (0–100).
     *
     * Coin holding or gaining price while the broader market sells = institutional interest.
     * Proxied from 1H klines: compares coin's recent 24H trend vs its prior 48H trend.
     *
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  1H candles
     */
    private function scoreRelativeStrength(array $candles): float
    {
        if (count($candles) < 48) {
            return 50.0;
        }

        $recent = array_slice($candles, -24);
        $prior = array_slice($candles, -72, 48);

        $recentStart = (float) $recent[0]['close'];
        $recentEnd = (float) $recent[array_key_last($recent)]['close'];
        $priorStart = (float) $prior[0]['close'];
        $priorEnd = (float) $prior[array_key_last($prior)]['close'];

        if ($recentStart <= 0.0 || $priorStart <= 0.0) {
            return 50.0;
        }

        $recentChange = ($recentEnd - $recentStart) / $recentStart;
        $priorChange = ($priorEnd - $priorStart) / $priorStart;
        $rsDiff = $recentChange - $priorChange;

        return match (true) {
            $rsDiff >= 0.05 => 100.0,
            $rsDiff >= 0.02 => 85.0,
            $rsDiff >= 0.00 => 70.0,
            $rsDiff >= -0.02 => 55.0,
            $rsDiff >= -0.05 => 40.0,
            default => 20.0,
        };
    }

    /**
     * Score CVD divergence (0–100).
     *
     * CVD rising quietly while price is flat = hidden accumulation (spec).
     * CVD = cumulative sum of (buy candle volume - sell candle volume) over 24H (1H klines).
     *
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  1H candles
     */
    private function scoreCvdDivergence(array $candles): float
    {
        $lookback = min(24, count($candles));
        $recentCandles = array_slice($candles, -$lookback);

        $cvdValues = [];
        $cvd = 0.0;

        foreach ($recentCandles as $candle) {
            if ($candle['close'] >= $candle['open']) {
                $cvd += $candle['volume'];
            } else {
                $cvd -= $candle['volume'];
            }

            $cvdValues[] = $cvd;
        }

        if ($cvdValues === []) {
            return 50.0;
        }

        $midpoint = (int) floor(count($cvdValues) / 2);
        $firstHalfAvg = array_sum(array_slice($cvdValues, 0, $midpoint)) / max(1, $midpoint);
        $secondHalfAvg = array_sum(array_slice($cvdValues, $midpoint)) / max(1, count($cvdValues) - $midpoint);

        $priceStart = (float) $recentCandles[0]['close'];
        $priceEnd = (float) $recentCandles[array_key_last($recentCandles)]['close'];
        $priceChange = $priceStart > 0 ? ($priceEnd - $priceStart) / $priceStart : 0.0;

        $cvdRising = ($secondHalfAvg - $firstHalfAvg) > 0;
        $priceSideways = abs($priceChange) < 0.03;
        $priceDeclining = $priceChange < -0.01;

        if ($cvdRising && $priceDeclining) {
            return 100.0;
        }

        if ($cvdRising && $priceSideways) {
            return 85.0;
        }

        if ($cvdRising) {
            return 65.0;
        }

        if (! $cvdRising && $priceSideways) {
            return 40.0;
        }

        return 25.0;
    }

    /**
     * Calculate ATR (Average True Range) using Wilder's smoothing method.
     *
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return float[]
     */
    private function calculateAtr(array $candles, int $period): array
    {
        if (count($candles) < $period + 1) {
            return [];
        }

        $trValues = [];

        for ($i = 1; $i < count($candles); $i++) {
            $high = $candles[$i]['high'];
            $low = $candles[$i]['low'];
            $prevClose = $candles[$i - 1]['close'];

            $trValues[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        $initialAtr = array_sum(array_slice($trValues, 0, $period)) / $period;
        $atrValues = [$initialAtr];
        $currentAtr = $initialAtr;

        for ($i = $period; $i < count($trValues); $i++) {
            $currentAtr = (($currentAtr * ($period - 1)) + $trValues[$i]) / $period;
            $atrValues[] = $currentAtr;
        }

        return $atrValues;
    }

    /**
     * Map raw Binance kline rows to structured candle arrays.
     *
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

            if (
                ! is_numeric($row[0])
                || ! is_numeric($row[1])
                || ! is_numeric($row[2])
                || ! is_numeric($row[3])
                || ! is_numeric($row[4])
                || ! is_numeric($row[5])
            ) {
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
}
