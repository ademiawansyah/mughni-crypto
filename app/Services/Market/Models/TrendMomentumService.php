<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Services\Market\DTO\TrendMomentumAnalysisDTO;
use App\Services\Market\MarketRegimeService;
use App\Services\Notification\NotificationService;
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

    private const ENTRY_LIMIT = 220;

    private const MIN_TREND_CANDLES = 220;

    private const MIN_ENTRY_CANDLES = 120;

    // Scoring weights — fixed integers matching Python (max 100)
    private const SCORE_EMA = 30;

    private const SCORE_MACD = 25;

    private const SCORE_RSI = 20;

    private const SCORE_BOS = 15;

    private const SCORE_DERIVATIVES = 10;

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
        private readonly NotificationService $notificationService,
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
        $passedDtos = [];
        $rejectedDtos = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            $trendKlines = $this->fetchAndStoreOhlcv($coin, '1d', self::TREND_LIMIT);
            $entryKlines = $this->fetchAndStoreOhlcv($coin, '4h', self::ENTRY_LIMIT);

            if ($trendKlines === [] || $entryKlines === []) {
                $rejectedDto = TrendMomentumAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: $coin->symbol,
                    rejectionReason: 'missing_ohlcv',
                    rejectionContext: [],
                    score: 0,
                    price: $coin->current_price,
                );
                $rejectedDtos[] = $rejectedDto;

                $failedCoins[] = [
                    'id' => $rejectedDto->coin_id,
                    'symbol' => $rejectedDto->symbol,
                    'reason' => $rejectedDto->rejection_reason,
                    'context' => $rejectedDto->rejection_context,
                    'score' => $rejectedDto->score,
                    'price' => $rejectedDto->price,
                ];

                continue;
            }

            $analysis = $this->analyzeCoin($coin, $trendKlines, $entryKlines, $minimumScore);

            if ($analysis['signal'] === null) {
                $rejectedDto = TrendMomentumAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: $coin->symbol,
                    rejectionReason: (string) $analysis['rejection_reason'],
                    rejectionContext: $analysis['rejection_context'],
                    score: (float) $analysis['score'],
                    price: $coin->current_price,
                );
                $rejectedDtos[] = $rejectedDto;

                $failedCoins[] = [
                    'id' => $rejectedDto->coin_id,
                    'symbol' => $rejectedDto->symbol,
                    'reason' => $rejectedDto->rejection_reason,
                    'context' => $rejectedDto->rejection_context,
                    'score' => $rejectedDto->score,
                    'price' => $rejectedDto->price,
                ];

                continue;
            }

            $passedDto = TrendMomentumAnalysisDTO::passed(
                executionId: $resolvedExecutionId,
                coinId: $coin->id,
                symbol: $coin->symbol,
                score: (float) $analysis['signal']['total_score'],
                price: (float) ($analysis['signal']['price'] ?? $coin->current_price),
                signal: $analysis['signal'],
                components: $analysis['signal']['components'],
                metadata: $analysis['signal']['metadata'],
            );

            $passedDtos[] = $passedDto;
        }

        usort(
            $passedDtos,
            static fn (TrendMomentumAnalysisDTO $left, TrendMomentumAnalysisDTO $right): int => $right->score <=> $left->score,
        );

        $limitedDtos = array_slice($passedDtos, 0, self::MAX_RESULTS);

        $ranked = array_values(array_map(
            static function (TrendMomentumAnalysisDTO $dto, int $index): array {
                $signal = $dto->signal ?? [];
                $signal['rank'] = $index + 1;

                return $signal;
            },
            $limitedDtos,
            array_keys($limitedDtos),
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
            'all_scored_results' => array_values(array_map(
                static fn (TrendMomentumAnalysisDTO $dto): array => (array) ($dto->signal ?? []),
                $passedDtos,
            )),
            'analysis_results' => array_values(array_map(
                static fn (TrendMomentumAnalysisDTO $dto): array => $dto->toArray(),
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
     *   score: int
     * }
     */
    private function analyzeCoin(Coin $coin, array $trendKlines, array $entryKlines, float $minimumScore): array
    {
        $trendCandles = $this->mapKlinesToCandles($trendKlines);
        $entryCandles = $this->mapKlinesToCandles($entryKlines);

        if (count($trendCandles) < self::MIN_TREND_CANDLES) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_trend_candles',
                'rejection_context' => [
                    'required' => self::MIN_TREND_CANDLES,
                    'actual' => count($trendCandles),
                ],
                'score' => 0,
            ];
        }

        if (count($entryCandles) < self::MIN_ENTRY_CANDLES) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_entry_candles',
                'rejection_context' => [
                    'required' => self::MIN_ENTRY_CANDLES,
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
                    'ema_spread' => $trendScore['ema_spread'],
                    'ema50_slope_last3' => $trendScore['ema50_slope_last3'],
                ],
                'score' => 0,
            ];
        }

        $futuresSymbol = strtoupper($coin->symbol).'USDT';

        $macd = $this->scoreMacd($entryCandles);
        $rsi = $this->scoreRsi($entryCandles);
        $bos = $this->scoreBos($entryCandles);
        $derivatives = $this->detectDerivatives($futuresSymbol, $entryCandles);

        // Fixed integer scoring matching Python: EMA=30, MACD=25, RSI=20, BOS=15, Derivatives=10
        $totalScore = self::SCORE_EMA;
        $totalScore += $macd['macd_ok'] ? self::SCORE_MACD : 0;
        $totalScore += $rsi['rsi_ok'] ? self::SCORE_RSI : 0;
        $totalScore += $bos['bos_ok'] ? self::SCORE_BOS : 0;
        $totalScore += $derivatives['oi_cvd_ok'] ? self::SCORE_DERIVATIVES : 0;

        if ($totalScore < $minimumScore) {
            return [
                'signal' => null,
                'rejection_reason' => 'below_minimum_score',
                'rejection_context' => [
                    'minimum' => $minimumScore,
                    'actual' => $totalScore,
                ],
                'score' => $totalScore,
            ];
        }

        $latestPrice = (float) ($entryCandles[count($entryCandles) - 1]['close'] ?? $coin->current_price ?? 0);

        // Stop loss: last confirmed swing high, or second-to-last candle low as fallback
        $stopLoss = $bos['last_swing_high'] ?? ($entryCandles[count($entryCandles) - 2]['low'] ?? null);

        return [
            'signal' => [
                'symbol' => strtoupper((string) $coin->symbol).'USDT',
                'price' => $latestPrice,
                'total_score' => $totalScore,
                'components' => [
                    'ema_gate' => $trendScore['ema_gate_ok'],
                    'macd_positive_zone' => $macd['macd_ok'],
                    'rsi_momentum_zone' => $rsi['rsi_ok'],
                    'bos_confirmed' => $bos['bos_ok'],
                    'oi_cvd_positive' => $derivatives['oi_cvd_ok'],
                    'derivatives_skipped' => $derivatives['derivatives_skipped'],
                ],
                'metadata' => [
                    'structure_timeframe' => '1D',
                    'entry_timeframe' => '4H',
                    'strategy' => self::MODEL_NAME,
                    'stop_loss' => $stopLoss,
                    // EMA gate metadata
                    'close' => $trendScore['close'],
                    'ema50' => $trendScore['ema50'],
                    'ema200' => $trendScore['ema200'],
                    'ema_spread' => $trendScore['ema_spread'],
                    'ema50_slope_last3' => $trendScore['ema50_slope_last3'],
                    'ema_gate_ok' => $trendScore['ema_gate_ok'],
                    // MACD metadata
                    'macd' => $macd['macd'],
                    'signal' => $macd['signal'],
                    'histogram' => $macd['histogram'],
                    'histogram_prev' => $macd['histogram_prev'],
                    'macd_ok' => $macd['macd_ok'],
                    // RSI metadata
                    'rsi' => $rsi['rsi'],
                    'rsi_ok' => $rsi['rsi_ok'],
                    // BOS metadata
                    'last_close' => $bos['last_close'],
                    'last_swing_high' => $bos['last_swing_high'],
                    'bos_ok' => $bos['bos_ok'],
                    // Derivatives metadata
                    'oi_growth' => $derivatives['oi_growth'],
                    'price_growth' => $derivatives['price_growth'],
                    'oi_price_ok' => $derivatives['oi_price_ok'],
                    'cvd_slope' => $derivatives['cvd_slope'],
                    'cvd_ok' => $derivatives['cvd_ok'],
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
            'score' => $totalScore,
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
     * EMA gate: close > EMA50 > EMA200, EMA50 slope positive 3 consecutive days,
     * and EMA50-EMA200 spread widening (Python-aligned).
     *
     * @param  array<int, array{close: float}>  $candles
     * @return array{score: int, gate_passed: bool, close: float|null, ema50: float|null, ema200: float|null, ema_spread: float|null, ema50_slope_last3: array<int, float>, ema_gate_ok: bool}
     */
    private function scoreEmaTrend(array $candles): array
    {
        $closes = array_values(array_map(static fn (array $candle): float => $candle['close'], $candles));
        $ema50Series = $this->calculateEmaSeries($closes, 50);
        $ema200Series = $this->calculateEmaSeries($closes, 200);

        $lastClose = $closes[count($closes) - 1] ?? null;

        if ($ema50Series === [] || $ema200Series === []) {
            return [
                'score' => 0,
                'gate_passed' => false,
                'close' => $lastClose,
                'ema50' => null,
                'ema200' => null,
                'ema_spread' => null,
                'ema50_slope_last3' => [],
                'ema_gate_ok' => false,
            ];
        }

        // Align EMA50 to EMA200's timeline (latest portion only)
        $alignedEma50 = array_values(array_slice($ema50Series, -count($ema200Series)));
        $n50 = count($alignedEma50);
        $n200 = count($ema200Series);

        $ema50Now = $alignedEma50[$n50 - 1];
        $ema200Now = $ema200Series[$n200 - 1];

        $spread = $ema50Now - $ema200Now;
        $spreadPrev = $n200 >= 2
            ? ($alignedEma50[$n50 - 2] - $ema200Series[$n200 - 2])
            : null;
        $spreadWidening = $spreadPrev !== null && $spread > $spreadPrev;

        // Slope: 3 consecutive positive differences in aligned EMA50
        $slope3 = [];
        if ($n50 >= 4) {
            $slope3 = [
                $alignedEma50[$n50 - 3] - $alignedEma50[$n50 - 4],
                $alignedEma50[$n50 - 2] - $alignedEma50[$n50 - 3],
                $alignedEma50[$n50 - 1] - $alignedEma50[$n50 - 2],
            ];
        }
        $slopeOk = count($slope3) === 3 && min($slope3) > 0;

        $gatePassed = ($lastClose ?? 0.0) > $ema50Now
            && $ema50Now > $ema200Now
            && $spreadWidening
            && $slopeOk;

        return [
            'score' => $gatePassed ? self::SCORE_EMA : 0,
            'gate_passed' => $gatePassed,
            'close' => $lastClose !== null ? round($lastClose, 8) : null,
            'ema50' => round($ema50Now, 8),
            'ema200' => round($ema200Now, 8),
            'ema_spread' => round($spread, 8),
            'ema50_slope_last3' => $slope3,
            'ema_gate_ok' => $gatePassed,
        ];
    }

    /**
     * MACD confirmation on 4H: MACD > Signal > 0, histogram positive and expanding (Python-aligned).
     * EMA12 is aligned to EMA26's timeline; MACD line is aligned to signal's timeline.
     *
     * @param  array<int, array{close: float}>  $candles
     * @return array{score: int, macd: float|null, signal: float|null, histogram: float|null, histogram_prev: float|null, macd_ok: bool}
     */
    private function scoreMacd(array $candles): array
    {
        $empty = ['score' => 0, 'macd' => null, 'signal' => null, 'histogram' => null, 'histogram_prev' => null, 'macd_ok' => false];

        $closes = array_values(array_map(static fn (array $candle): float => $candle['close'], $candles));
        $ema12 = $this->calculateEmaSeries($closes, 12);
        $ema26 = $this->calculateEmaSeries($closes, 26);

        if ($ema12 === [] || $ema26 === []) {
            return $empty;
        }

        // Align EMA12 to EMA26's timeline (latest portion only)
        $alignedEma12 = array_values(array_slice($ema12, -count($ema26)));

        $macdLine = [];
        foreach (array_values($ema26) as $i => $e26) {
            $macdLine[] = ($alignedEma12[$i] ?? 0.0) - $e26;
        }

        $signalSeries = $this->calculateEmaSeries($macdLine, 9);
        if ($signalSeries === []) {
            return array_merge($empty, ['macd' => end($macdLine) ?: null]);
        }

        // Align MACD line to signal's timeline
        $macdTail = array_values(array_slice($macdLine, -count($signalSeries)));

        $histogram = [];
        foreach (array_values($signalSeries) as $i => $sig) {
            $histogram[] = ($macdTail[$i] ?? 0.0) - $sig;
        }

        if (count($histogram) < 2) {
            return array_merge($empty, [
                'macd' => end($macdTail) ?: null,
                'signal' => end($signalSeries) ?: null,
                'histogram' => end($histogram) ?: null,
            ]);
        }

        $macdNow = $macdTail[count($macdTail) - 1];
        $signalNow = $signalSeries[count($signalSeries) - 1];
        $histNow = $histogram[count($histogram) - 1];
        $histPrev = $histogram[count($histogram) - 2];

        $macdOk = $macdNow > $signalNow && $signalNow > 0 && $histNow > 0 && $histNow > $histPrev;

        return [
            'score' => $macdOk ? self::SCORE_MACD : 0,
            'macd' => round($macdNow, 8),
            'signal' => round($signalNow, 8),
            'histogram' => round($histNow, 8),
            'histogram_prev' => round($histPrev, 8),
            'macd_ok' => $macdOk,
        ];
    }

    /**
     * RSI momentum zone on 4H: RSI(14) in 50–65 (Python-aligned, binary).
     *
     * @param  array<int, array{close: float}>  $candles
     * @return array{score: int, rsi: float|null, rsi_ok: bool}
     */
    private function scoreRsi(array $candles): array
    {
        $closes = array_values(array_map(static fn (array $candle): float => $candle['close'], $candles));
        $rsiSeries = $this->calculateRsiSeries($closes, 14);

        if ($rsiSeries === []) {
            return ['score' => 0, 'rsi' => null, 'rsi_ok' => false];
        }

        $rsi = $rsiSeries[count($rsiSeries) - 1];
        $rsiOk = $rsi >= 50.0 && $rsi <= 65.0;

        return [
            'score' => $rsiOk ? self::SCORE_RSI : 0,
            'rsi' => round($rsi, 4),
            'rsi_ok' => $rsiOk,
        ];
    }

    /**
     * BOS confirmation on 4H: close breaks prior confirmed swing high (Python-aligned).
     * Uses proper swing high detection with lookback=5.
     *
     * @param  array<int, array{high: float, close: float}>  $candles
     * @return array{score: int, last_close: float|null, last_swing_high: float|null, bos_ok: bool}
     */
    private function scoreBos(array $candles): array
    {
        if (count($candles) < 30) {
            return ['score' => 0, 'last_close' => null, 'last_swing_high' => null, 'bos_ok' => false];
        }

        // Exclude latest candle from swing reference to avoid self-referential level
        $reference = array_slice($candles, 0, -1);
        $swingHighs = $this->findSwingHighs($reference, 5);

        $lastClose = $candles[count($candles) - 1]['close'];

        if ($swingHighs === []) {
            return [
                'score' => 0,
                'last_close' => $lastClose,
                'last_swing_high' => null,
                'bos_ok' => false,
            ];
        }

        $lastSwingHigh = $swingHighs[count($swingHighs) - 1]['price'];
        $bosOk = $lastClose > $lastSwingHigh;

        return [
            'score' => $bosOk ? self::SCORE_BOS : 0,
            'last_close' => $lastClose,
            'last_swing_high' => $lastSwingHigh,
            'bos_ok' => $bosOk,
        ];
    }

    /**
     * Find swing highs from OHLCV candles.
     * A swing high is a candle whose high is strictly the highest in the surrounding
     * window of $lookback candles on each side (matches Python's find_swing_points).
     *
     * @param  array<int, array{high: float}>  $candles
     * @param  int  $lookback  Candles on each side required to confirm a swing
     * @return array<int, array{index: int, price: float}>
     */
    private function findSwingHighs(array $candles, int $lookback = 5): array
    {
        $swingHighs = [];
        $n = count($candles);

        for ($i = $lookback; $i < $n - $lookback; $i++) {
            $high = $candles[$i]['high'];
            $isSwingHigh = true;

            for ($j = $i - $lookback; $j <= $i + $lookback; $j++) {
                if ($j !== $i && ($candles[$j]['high'] ?? 0.0) >= $high) {
                    $isSwingHigh = false;
                    break;
                }
            }

            if ($isSwingHigh) {
                $swingHighs[] = ['index' => $i, 'price' => $high];
            }
        }

        return $swingHighs;
    }

    /**
     * Derivatives health: OI and price both up >5% in 24H AND CVD slope positive (Python-aligned).
     * Combines OI+price check and CVD into a single derivatives gate, mirroring Python's
     * `derivatives_ok = oi_price_ok and cvd_ok`.
     *
     * @param  array<int, array{close: float}>  $entryCandles  4H candles for price-growth calculation
     * @return array{
     *   score: int,
     *   oi_cvd_ok: bool,
     *   derivatives_skipped: bool,
     *   oi_growth: float|null,
     *   price_growth: float|null,
     *   oi_price_ok: bool,
     *   cvd_slope: float|null,
     *   cvd_ok: bool,
     * }
     */
    private function detectDerivatives(string $futuresSymbol, array $entryCandles): array
    {
        $noData = [
            'score' => 0,
            'oi_cvd_ok' => false,
            'derivatives_skipped' => true,
            'oi_growth' => null,
            'price_growth' => null,
            'oi_price_ok' => false,
            'cvd_slope' => null,
            'cvd_ok' => false,
        ];

        $history = $this->marketRegimeService->getOpenInterestHistoryForCoin(
            symbol: $futuresSymbol,
            period: '4h',
            limit: 6,
        );

        if ($history === null || count($history) < 2) {
            return $noData;
        }

        $earliest = (float) $history[0]['sumOpenInterest'];
        $latest = (float) $history[array_key_last($history)]['sumOpenInterest'];

        if ($earliest <= 0.0) {
            return array_merge($noData, ['derivatives_skipped' => false]);
        }

        $oiGrowth = ($latest - $earliest) / $earliest;

        // Price growth over the same ~24H window (last 6 × 4H candles)
        $priceGrowth = null;
        $oiPriceOk = false;
        if (count($entryCandles) >= 6) {
            $priceStart = $entryCandles[count($entryCandles) - 6]['close'];
            $priceEnd = $entryCandles[count($entryCandles) - 1]['close'];
            if ($priceStart > 0.0) {
                $priceGrowth = ($priceEnd - $priceStart) / $priceStart;
                $oiPriceOk = $oiGrowth > 0.05 && $priceGrowth > 0.05;
            }
        }

        // CVD slope from aggTrades
        $cvdMetrics = $this->marketRegimeService->getCvdMetricsForCoin(
            symbol: $futuresSymbol,
            limit: 1000,
        );

        $cvdSlope = null;
        $cvdOk = false;
        if ($cvdMetrics !== null) {
            $cvdSlope = $cvdMetrics['cvd_slope'] ?? 0.0;
            $cvdOk = $cvdSlope > 0;
        }

        $derivativesOk = $oiPriceOk && $cvdOk;

        return [
            'score' => $derivativesOk ? self::SCORE_DERIVATIVES : 0,
            'oi_cvd_ok' => $derivativesOk,
            'derivatives_skipped' => false,
            'oi_growth' => $oiGrowth !== null ? round($oiGrowth, 4) : null,
            'price_growth' => $priceGrowth !== null ? round($priceGrowth, 4) : null,
            'oi_price_ok' => $oiPriceOk,
            'cvd_slope' => $cvdSlope !== null ? round($cvdSlope, 4) : null,
            'cvd_ok' => $cvdOk,
        ];
    }

    /**
     * Calculate EMA series using SMA seed (Python-aligned).
     * Returns a series of length count($values) - $period + 1.
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private function calculateEmaSeries(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }

        $multiplier = 2.0 / ($period + 1);

        // Seed with SMA of the first $period values (matches Python's approach)
        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $series = [$seed];

        foreach (array_slice($values, $period) as $value) {
            $series[] = ((float) $value * $multiplier) + ($series[count($series) - 1] * (1.0 - $multiplier));
        }

        return $series; // length = count($values) - $period + 1
    }

    /**
     * Calculate RSI series using Wilder's smoothing (Python-aligned).
     * Returns a series of length count($closes) - $period - 1.
     *
     * @param  array<int, float>  $closes
     * @return array<int, float>
     */
    private function calculateRsiSeries(array $closes, int $period = 14): array
    {
        if (count($closes) <= $period) {
            return [];
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($closes); $i++) {
            $delta = $closes[$i] - $closes[$i - 1];
            $gains[] = max($delta, 0.0);
            $losses[] = abs(min($delta, 0.0));
        }

        // Seed with SMA of first $period gains/losses
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        $out = [];

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;

            if ($avgLoss == 0.0) {
                $out[] = 100.0;

                continue;
            }

            $rs = $avgGain / $avgLoss;
            $out[] = 100.0 - (100.0 / (1.0 + $rs));
        }

        return $out;
    }
}
