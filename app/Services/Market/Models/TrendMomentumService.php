<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Services\Market\MarketRegimeService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TrendMomentumService
{
    private const MODEL_NAME = 'momentum';

    private const MODEL_VERSION = '2.0';

    private const MIN_VOLUME_USD = 50_000_000;

    private const MAX_RESULTS = 10;

    private const TREND_LIMIT = 260;

    private const ENTRY_LIMIT = 160;

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
    ) {}

    /**
     * Execute Model 3 (Trend Momentum) scanning pipeline.
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
        $minimumScore = (float) config('models.momentum.min_score', 55);

        Log::info('[TrendMomentumService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'minimum_score' => $minimumScore,
        ]);

        $candidates = $this->filterCoins();
        $signals = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            $trendKlines = $this->fetchAndStoreOhlcv($coin, '1d', self::TREND_LIMIT);
            $entryKlines = $this->fetchAndStoreOhlcv($coin, '4h', self::ENTRY_LIMIT);

            if ($trendKlines === [] || $entryKlines === []) {
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

            $analysis = $this->analyzeCoin($coin, $trendKlines, $entryKlines, $minimumScore);

            if ($analysis['signal'] === null) {
                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => $coin->symbol,
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                    'score' => $analysis['score'],
                    'price' => $coin->current_price,
                ];

                continue;
            }

            $signals[] = $analysis['signal'];
        }

        usort(
            $signals,
            static fn (array $left, array $right): int => $right['total_score'] <=> $left['total_score'],
        );

        $limitedSignals = array_slice($signals, 0, self::MAX_RESULTS);

        $ranked = array_values(array_map(
            static fn (array $signal, int $index): array => array_merge($signal, ['rank' => $index + 1]),
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

        Log::info('[TrendMomentumService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
        ]);

        return $result;
    }

    /**
     * Layer 3 candidate selection for Model 3.
     */
    private function filterCoins()
    {
        return Coin::query()
            ->where('is_valid', true)
            ->where(function ($query) {
                $query->where(function ($subQuery) {
                    $subQuery->whereRaw("COALESCE((raw_data->>'market_cap_rank')::int, 999999) BETWEEN ? AND ?", [1, 50])
                        ->where(function ($volumeQuery) {
                            $volumeQuery->where('total_volume', '>=', self::MIN_VOLUME_USD)
                                ->orWhere('volume_24h', '>=', self::MIN_VOLUME_USD);
                        });
                })->orWhereRaw('LOWER(symbol) IN (?, ?)', ['btc', 'eth']);
            })
            ->get();
    }

    /**
     * Fetch OHLCV from MarketRegimeService.
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
     * @param  array<int, array<int, mixed>>  $trendKlines
     * @param  array<int, array<int, mixed>>  $entryKlines
     * @return array{
     *   signal: array<string, mixed>|null,
     *   rejection_reason: string|null,
     *   rejection_context: array<string, mixed>,
     *   score: float
     * }
     */
    private function analyzeCoin(Coin $coin, array $trendKlines, array $entryKlines, float $minimumScore): array
    {
        $trendCandles = $this->mapKlinesToCandles($trendKlines);
        $entryCandles = $this->mapKlinesToCandles($entryKlines);

        if (count($trendCandles) < 210) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_trend_candles',
                'rejection_context' => [
                    'required' => 210,
                    'actual' => count($trendCandles),
                ],
                'score' => 0,
            ];
        }

        if (count($entryCandles) < 50) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_entry_candles',
                'rejection_context' => [
                    'required' => 50,
                    'actual' => count($entryCandles),
                ],
                'score' => 0,
            ];
        }

        $trendScore = $this->scoreEmaTrend($trendCandles);

        if (! $trendScore['gate_passed']) {
            return [
                'signal' => null,
                'rejection_reason' => 'ema_gate_failed',
                'rejection_context' => [
                    'close' => $trendScore['close'],
                    'ema50' => $trendScore['ema50'],
                    'ema200' => $trendScore['ema200'],
                    'ema50_slope_positive_days' => $trendScore['ema50_slope_positive_days'],
                ],
                'score' => 0,
            ];
        }

        $futuresSymbol = strtoupper($coin->symbol).'USDT';

        $macd = $this->scoreMacd($entryCandles);
        $rsi = $this->scoreRsi($entryCandles);
        $bos = $this->scoreBos($entryCandles);
        $oi = $this->detectOiRising($futuresSymbol);
        $cvd = $this->detectPositiveCvd($futuresSymbol);

        $weights = [
            'ema' => (float) config('models.momentum.scoring.ema', 0.25),
            'macd' => (float) config('models.momentum.scoring.macd', 0.20),
            'rsi' => (float) config('models.momentum.scoring.rsi', 0.15),
            'bos' => (float) config('models.momentum.scoring.bos', 0.10),
            'oi' => (float) config('models.momentum.scoring.oi', 0.20),
            'cvd' => (float) config('models.momentum.scoring.cvd', 0.10),
        ];

        $totalScore =
            ($trendScore['score'] * $weights['ema']) +
            ($macd['score'] * $weights['macd']) +
            ($rsi['score'] * $weights['rsi']) +
            ($bos['score'] * $weights['bos']) +
            ($oi['score'] * $weights['oi']) +
            ($cvd['score'] * $weights['cvd']);

        if ($totalScore < $minimumScore) {
            return [
                'signal' => null,
                'rejection_reason' => 'below_minimum_score',
                'rejection_context' => [
                    'minimum' => $minimumScore,
                    'actual' => round($totalScore, 2),
                ],
                'score' => round($totalScore, 2),
            ];
        }

        $latestPrice = (float) ($entryCandles[count($entryCandles) - 1]['close'] ?? $coin->current_price ?? 0);

        return [
            'signal' => [
                'symbol' => strtoupper((string) $coin->symbol).'USDT',
                'price' => $latestPrice,
                'total_score' => round($totalScore, 2),
                'components' => [
                    'ema_filter' => [
                        'score' => $trendScore['score'],
                        'passed' => $trendScore['gate_passed'],
                        'close' => $trendScore['close'],
                        'ema50' => $trendScore['ema50'],
                        'ema200' => $trendScore['ema200'],
                    ],
                    'macd' => $macd,
                    'rsi' => $rsi,
                    'bos' => $bos,
                    'oi' => $oi,
                    'cvd' => $cvd,
                ],
                'metadata' => [
                    'entry_timeframe' => '4h',
                    'trend_timeframe' => '1d',
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
            'score' => round($totalScore, 2),
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $klines
     * @return array<int, array{open: float, high: float, low: float, close: float, volume: float, open_time: int}>
     */
    private function mapKlinesToCandles(array $klines): array
    {
        return array_values(array_map(static function (array $kline): array {
            return [
                'open_time' => (int) ($kline[0] ?? 0),
                'open' => (float) ($kline[1] ?? 0),
                'high' => (float) ($kline[2] ?? 0),
                'low' => (float) ($kline[3] ?? 0),
                'close' => (float) ($kline[4] ?? 0),
                'volume' => (float) ($kline[5] ?? 0),
            ];
        }, $klines));
    }

    /**
     * @param  array<int, array{close: float}>  $candles
     * @return array{score: float, gate_passed: bool, close: float, ema50: float, ema200: float, ema50_slope_positive_days: int}
     */
    private function scoreEmaTrend(array $candles): array
    {
        $closes = array_values(array_map(static fn (array $candle): float => $candle['close'], $candles));
        $ema50Series = $this->calculateEmaSeries($closes, 50);
        $ema200Series = $this->calculateEmaSeries($closes, 200);

        $close = $closes[count($closes) - 1] ?? 0.0;
        $ema50 = $ema50Series[count($ema50Series) - 1] ?? 0.0;
        $ema200 = $ema200Series[count($ema200Series) - 1] ?? 0.0;

        $slopePositiveDays = 0;
        for ($i = count($ema50Series) - 1; $i > max(0, count($ema50Series) - 4); $i--) {
            $current = $ema50Series[$i] ?? 0.0;
            $previous = $ema50Series[$i - 1] ?? 0.0;
            if ($current > $previous) {
                $slopePositiveDays++;
            }
        }

        $gatePassed = $close > $ema50 && $ema50 > $ema200 && $slopePositiveDays >= 3;

        return [
            'score' => $gatePassed ? 100.0 : 0.0,
            'gate_passed' => $gatePassed,
            'close' => round($close, 8),
            'ema50' => round($ema50, 8),
            'ema200' => round($ema200, 8),
            'ema50_slope_positive_days' => $slopePositiveDays,
        ];
    }

    /**
     * @param  array<int, array{close: float}>  $candles
     * @return array{score: float, macd: float, signal: float, histogram: float}
     */
    private function scoreMacd(array $candles): array
    {
        $closes = array_values(array_map(static fn (array $candle): float => $candle['close'], $candles));
        $ema12 = $this->calculateEmaSeries($closes, 12);
        $ema26 = $this->calculateEmaSeries($closes, 26);

        $macdSeries = [];
        for ($i = 0; $i < count($closes); $i++) {
            $macdSeries[] = ($ema12[$i] ?? 0.0) - ($ema26[$i] ?? 0.0);
        }

        $signalSeries = $this->calculateEmaSeries($macdSeries, 9);

        $macd = $macdSeries[count($macdSeries) - 1] ?? 0.0;
        $signal = $signalSeries[count($signalSeries) - 1] ?? 0.0;
        $histogram = $macd - $signal;

        $previousHistogram = count($macdSeries) > 1
            ? (($macdSeries[count($macdSeries) - 2] ?? 0.0) - ($signalSeries[count($signalSeries) - 2] ?? 0.0))
            : 0.0;

        $isPositiveZone = $macd > $signal && $signal > 0;
        $isExpanding = $histogram > 0 && $histogram >= $previousHistogram;

        $score = $isPositiveZone && $isExpanding ? 100.0 : ($isPositiveZone ? 60.0 : 0.0);

        return [
            'score' => $score,
            'macd' => round($macd, 8),
            'signal' => round($signal, 8),
            'histogram' => round($histogram, 8),
        ];
    }

    /**
     * @param  array<int, array{close: float}>  $candles
     * @return array{score: float, value: float}
     */
    private function scoreRsi(array $candles): array
    {
        $closes = array_values(array_map(static fn (array $candle): float => $candle['close'], $candles));
        $rsi = $this->calculateRsi($closes, 14);

        $score = match (true) {
            $rsi >= 50 && $rsi <= 65 => 100.0,
            $rsi >= 45 && $rsi < 70 => 60.0,
            default => 0.0,
        };

        return [
            'score' => $score,
            'value' => round($rsi, 4),
        ];
    }

    /**
     * @param  array<int, array{high: float, close: float}>  $candles
     * @return array{score: float, passed: bool}
     */
    private function scoreBos(array $candles): array
    {
        if (count($candles) < 25) {
            return ['score' => 0.0, 'passed' => false];
        }

        $latestClose = $candles[count($candles) - 1]['close'];
        $recentHighs = array_map(
            static fn (array $candle): float => $candle['high'],
            array_slice($candles, -21, 20),
        );
        $previousSwingHigh = max($recentHighs);

        $passed = $latestClose > $previousSwingHigh;

        return [
            'score' => $passed ? 100.0 : 0.0,
            'passed' => $passed,
        ];
    }

    /**
     * Detect whether OI is rising over recent periods, indicating new money entering trend.
     *
     * Uses Binance Futures /fapi/v1/openInterest endpoint.
     * Returns gracefully with zero score when futures data is unavailable.
     *
     * @return array{score: float, oi_change_percent: float, rising: bool}
     */
    private function detectOiRising(string $futuresSymbol): array
    {
        $history = $this->marketRegimeService->getOpenInterestHistoryForCoin(
            symbol: $futuresSymbol,
            period: '4h',
            limit: 6,
        );

        if ($history === null || count($history) < 2) {
            return [
                'score' => 0.0,
                'oi_change_percent' => 0.0,
                'rising' => false,
            ];
        }

        $earliest = $history[0]['sumOpenInterest'];
        $latest = $history[array_key_last($history)]['sumOpenInterest'];

        if ($earliest <= 0.0) {
            return [
                'score' => 0.0,
                'oi_change_percent' => 0.0,
                'rising' => false,
            ];
        }

        $oiChangePercent = (($latest - $earliest) / $earliest) * 100;

        // OI rising >5% in 24H indicates new money entering = healthy trend
        $isRising = $oiChangePercent > 5.0;
        $score = $isRising ? 100.0 : 0.0;

        return [
            'score' => $score,
            'oi_change_percent' => round($oiChangePercent, 2),
            'rising' => $isRising,
        ];
    }

    /**
     * Detect whether CVD (Cumulative Volume Delta) is positive, indicating buyer aggression.
     *
     * Uses Binance Futures /fapi/v1/aggTrades endpoint to fetch recent trades and calculate CVD.
     * CVD = sum(buy_volume) - sum(sell_volume) over recent trades.
     * Returns gracefully with zero score when futures data is unavailable.
     *
     * @return array{score: float, cvd: float, positive: bool}
     */
    private function detectPositiveCvd(string $futuresSymbol): array
    {
        $cvdMetrics = $this->marketRegimeService->getCvdMetricsForCoin(
            symbol: $futuresSymbol,
            limit: 1000,
        );

        if ($cvdMetrics === null) {
            return [
                'score' => 0.0,
                'cvd' => 0.0,
                'positive' => false,
            ];
        }

        $cvd = $cvdMetrics['cvd'] ?? 0.0;

        $isPositive = $cvd > 0;
        $score = $isPositive ? 100.0 : 0.0;

        return [
            'score' => $score,
            'cvd' => round($cvd, 4),
            'positive' => $isPositive,
        ];
    }

    /**
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function calculateEmaSeries(array $values, int $period): array
    {
        if ($values === []) {
            return [];
        }

        $multiplier = 2 / ($period + 1);
        $series = [];
        $ema = (float) $values[0];

        foreach ($values as $value) {
            $ema = ((float) $value - $ema) * $multiplier + $ema;
            $series[] = $ema;
        }

        return $series;
    }

    /**
     * @param  array<int, float>  $closes
     */
    private function calculateRsi(array $closes, int $period): float
    {
        if (count($closes) <= $period) {
            return 50.0;
        }

        $gains = 0.0;
        $losses = 0.0;

        for ($i = count($closes) - $period; $i < count($closes); $i++) {
            if (! isset($closes[$i - 1])) {
                continue;
            }

            $change = $closes[$i] - $closes[$i - 1];
            if ($change >= 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }

        if ($losses == 0.0) {
            return 100.0;
        }

        $rs = ($gains / $period) / ($losses / $period);

        return 100 - (100 / (1 + $rs));
    }
}
