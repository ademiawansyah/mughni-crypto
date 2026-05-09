<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Models\CoinMarketData;
use App\Services\Market\DTO\PrePumpAnalysisDTO;
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

    /** 4H candles to fetch (~36 days). Python fetches 220. */
    private const STRUCTURE_LIMIT = 220;

    /** Minimum 4H candles required for analysis (Python: len < 180 → skip). */
    private const MIN_STRUCTURE_CANDLES = 180;

    /** Scoring weights — additive integer model matching Python exactly. */
    private const SCORE_FUNDING = 35;

    private const SCORE_OI_PRICE = 25;

    private const SCORE_ATR = 20;

    private const SCORE_CVD = 12;

    private const SCORE_RSI = 8;

    /** ATR short-period (spec: ATR 14 on 4H candles). */
    private const ATR_PERIOD = 14;

    /** Candle window for the 30-day ATR baseline (Python: candles[-180:], period=30). */
    private const ATR_BASELINE_WINDOW = 180;

    private const ATR_BASELINE_PERIOD = 30;

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Execute Model 2 (Pre-Pump) scanning pipeline.
     *
     * Fetches 4H OHLCV for each Layer 3 candidate, runs boolean gate checks,
     * accumulates additive scores, and persists top-10 results.
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

        // min_score=1 matches Python "if score <= 0: return None"
        $minimumScore = (int) config('models.pre_pump.min_score', 1);

        Log::info('[PrePumpService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'minimum_score' => $minimumScore,
        ]);

        $candidates = $this->filterCoins();
        $passedDtos = [];
        $rejectedDtos = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            $structureKlines = $this->fetchAndStoreOhlcv($coin, '4h', self::STRUCTURE_LIMIT);

            if ($structureKlines === []) {
                Log::warning('[PrePumpService] Skipped coin due to missing OHLCV', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                ]);

                $rejectedDto = PrePumpAnalysisDTO::rejected(
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

            $analysis = $this->analyzeCoin($coin, $structureKlines, $minimumScore);

            if ($analysis['signal'] === null) {
                $rejectedDto = PrePumpAnalysisDTO::rejected(
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

                Log::info('[PrePumpService] Coin rejected by analysis', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                ]);

                continue;
            }

            $passedDto = PrePumpAnalysisDTO::passed(
                executionId: $resolvedExecutionId,
                coinId: $coin->id,
                symbol: $coin->symbol,
                score: (float) $analysis['signal']['total_score'],
                price: (float) $coin->current_price,
                signal: $analysis['signal'],
                components: $analysis['signal']['components'],
                metadata: $analysis['signal']['metadata'],
            );

            $passedDtos[] = $passedDto;
        }

        usort(
            $passedDtos,
            static fn (PrePumpAnalysisDTO $left, PrePumpAnalysisDTO $right): int => $right->score <=> $left->score,
        );

        $limitedDtos = array_slice($passedDtos, 0, self::MAX_RESULTS);

        $ranked = array_values(array_map(
            static function (PrePumpAnalysisDTO $dto, int $index): array {
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
                static fn (PrePumpAnalysisDTO $dto): array => (array) ($dto->signal ?? []),
                $passedDtos,
            )),
            'analysis_results' => array_values(array_map(
                static fn (PrePumpAnalysisDTO $dto): array => $dto->toArray(),
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

        Log::info('[PrePumpService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
        ]);

        return $result;
    }

    /**
     * Layer 3 candidate selection for Model 2.
     *
     * Keeps coins with is_valid=true, market_cap_rank 20–150, volume >= $10M.
     * Matches Python filter_layer3() thresholds exactly.
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
     * Run the full Pre-Pump analysis pipeline for one coin.
     *
     * Gates applied in order:
     *   1. Minimum candle count (< 180 → skip)
     *   2. Funding gate — hard-reject if Coinalyze data available and funding is not negative
     *   3. Score gate — reject if total_score < minimum_score
     *
     * Component weights (additive):
     *   - persistent_negative_funding  : 35
     *   - oi_rising_price_sideways     : 25
     *   - low_atr_compression          : 20
     *   - cvd_quietly_rising           : 12
     *   - rsi_compression              :  8
     *
     * Volume drying is computed and stored in metadata but is NOT weighted in the score.
     *
     * @param  array<int, array<int, mixed>>  $structureKlines  Raw Binance 4H klines
     * @param  int  $minimumScore  Minimum total_score to pass (default 1 = any gate must fire)
     * @return array{signal: array<string,mixed>|null, rejection_reason: string|null, rejection_context: array<string,mixed>, score: int}
     */
    private function analyzeCoin(Coin $coin, array $structureKlines, int $minimumScore): array
    {
        $candles = $this->mapKlinesToCandles($structureKlines);

        if (count($candles) < self::MIN_STRUCTURE_CANDLES) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_structure_candles',
                'rejection_context' => [
                    'required' => self::MIN_STRUCTURE_CANDLES,
                    'actual' => count($candles),
                ],
                'score' => 0,
            ];
        }

        $symbol = strtoupper($coin->symbol);
        $pairSymbol = str_ends_with($symbol, 'USDT') ? $symbol : $symbol.'USDT';

        // --- Fetch Coinalyze derivative data and CoinGecko daily volumes ---
        $fundingData = $this->marketRegimeService->getPrePumpFundingRateHistory($pairSymbol, 12);
        $oiData = $this->marketRegimeService->getPrePumpOiHistory($pairSymbol, '4hour', 7);
        $dailyVolumes = $this->marketRegimeService->getPrePumpDailyVolumes((string) ($coin->coin_gecko_id ?? ''), 8);

        $coinalyzeAvailable = $fundingData !== [] || $oiData !== [];

        // --- Gate 1: Funding squeeze gate ---
        // If Coinalyze returned funding data and it is NOT persistently negative → hard-reject.
        // If Coinalyze is unavailable → bypass gate (funding_gate_bypassed = true).
        $fundingResult = $this->checkPersistentNegativeFunding($fundingData);
        $fundingOk = $fundingResult['ok'];
        $fundingGateBypassed = $fundingResult['bypassed'];

        if (! $fundingOk && ! $fundingGateBypassed) {
            return [
                'signal' => null,
                'rejection_reason' => 'funding_gate_failed',
                'rejection_context' => [
                    'funding_recent_8h' => $fundingResult['recent_8h'],
                ],
                'score' => 0,
            ];
        }

        // --- Component checks ---
        $priceRange24h = $this->calcPriceRange24h($candles);
        $sideways = $priceRange24h !== null && $priceRange24h < 0.03;

        $oiResult = $this->checkOiRising($oiData);
        $oiRisingPriceSidewaysOk = $oiResult['ok'] && $sideways;

        $atrResult = $this->checkLowAtr($candles);
        $atrOk = $atrResult['ok'];

        $cvdResult = $this->checkCvdRising($candles);
        $cvdOk = $cvdResult['ok'] && $sideways;

        $rsiResult = $this->checkRsiCompression($candles);
        $rsiOk = $rsiResult['ok'];

        // Volume drying — informational only, not included in score (matching Python spec §5.5)
        $volumeDryingResult = $this->checkVolumeDrying($coin, $dailyVolumes);

        // --- Additive score ---
        $totalScore = 0;
        $totalScore += $fundingOk ? self::SCORE_FUNDING : 0;
        $totalScore += $oiRisingPriceSidewaysOk ? self::SCORE_OI_PRICE : 0;
        $totalScore += $atrOk ? self::SCORE_ATR : 0;
        $totalScore += $cvdOk ? self::SCORE_CVD : 0;
        $totalScore += $rsiOk ? self::SCORE_RSI : 0;

        Log::info('[PrePumpService] Analyzed coin', [
            'symbol' => $pairSymbol,
            'total_score' => $totalScore,
            'components' => [
                'persistent_negative_funding' => $fundingOk,
                'funding_gate_bypassed' => $fundingGateBypassed,
                'oi_rising_price_sideways' => $oiRisingPriceSidewaysOk,
                'low_atr_compression' => $atrOk,
                'cvd_quietly_rising' => $cvdOk,
                'rsi_compression' => $rsiOk,
                'drying_volume' => $volumeDryingResult['ok'],
            ],
        ]);

        // Gate 2: Drop coins with no active signals (score > 0 required, matching Python)
        if ($totalScore < $minimumScore) {
            return [
                'signal' => null,
                'rejection_reason' => 'no_active_signals',
                'rejection_context' => [
                    'total_score' => $totalScore,
                    'minimum_score' => $minimumScore,
                ],
                'score' => $totalScore,
            ];
        }

        return [
            'signal' => [
                'symbol' => $pairSymbol,
                'price' => $coin->current_price,
                'total_score' => $totalScore,
                'components' => [
                    'persistent_negative_funding' => $fundingOk,
                    'funding_gate_bypassed' => $fundingGateBypassed,
                    'oi_rising_price_sideways' => $oiRisingPriceSidewaysOk,
                    'low_atr_compression' => $atrOk,
                    'cvd_quietly_rising' => $cvdOk,
                    'rsi_compression' => $rsiOk,
                    'drying_volume' => $volumeDryingResult['ok'],
                ],
                'metadata' => [
                    'structure_timeframe' => '4H',
                    'entry_timeframe' => '1H',
                    'strategy' => self::MODEL_NAME,
                    'coinalyze_available' => $coinalyzeAvailable,
                    'funding_recent_8h' => $fundingResult['recent_8h'],
                    'oi_24h_growth' => $oiResult['growth'],
                    'price_range_24h' => $priceRange24h,
                    'atr_14' => $atrResult['atr_14'],
                    'atr_30d_baseline' => $atrResult['atr_baseline'],
                    'atr_ratio' => $atrResult['atr_ratio'],
                    'cvd_slope_24h' => $cvdResult['slope'],
                    'rsi_recent_4h' => $rsiResult['recent'],
                    'volume_24h' => $coin->total_volume ?? $coin->volume_24h,
                    'volume_7d_avg' => $volumeDryingResult['baseline'],
                    'volume_ratio' => $volumeDryingResult['ratio'],
                    'oi_declining_check_reference' => $this->checkOiDeclining($oiData),
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
     * Check persistent negative funding using Coinalyze 4H data.
     *
     * Aggregates 4H funding rate pairs into 8H buckets (average), then checks
     * if the last 3 consecutive 8H periods are all below -0.0005.
     * Matches Python _persistent_negative_funding() and _aggregate_to_8h_funding().
     *
     * Bypass: if Coinalyze returned no data, gate is bypassed (coin not penalised).
     *
     * @param  array<int, array{timestamp: int, funding_rate: float}>  $fundingData4h
     * @return array{ok: bool, recent_8h: float[], bypassed: bool}
     */
    private function checkPersistentNegativeFunding(array $fundingData4h): array
    {
        if ($fundingData4h === []) {
            return ['ok' => false, 'recent_8h' => [], 'bypassed' => true];
        }

        $rates = array_column($fundingData4h, 'funding_rate');
        $count = count($rates);

        // Aggregate pairs of 4H rates into 8H buckets (Python: start=0 if even, else 1)
        $grouped = [];
        $start = ($count % 2 === 0) ? 0 : 1;

        for ($i = $start; $i < $count - 1; $i += 2) {
            $grouped[] = ($rates[$i] + $rates[$i + 1]) / 2.0;
        }

        if (count($grouped) < 3) {
            return ['ok' => false, 'recent_8h' => $grouped, 'bypassed' => false];
        }

        $recent3 = array_slice($grouped, -3);
        $allNegative = count(array_filter($recent3, static fn (float $r): bool => $r < -0.0005)) === 3;

        return ['ok' => $allNegative, 'recent_8h' => $recent3, 'bypassed' => false];
    }

    /**
     * Check whether OI rose more than 10% across the history window.
     *
     * Matches Python _oi_rising_24h(): (end - start) / start > 0.10.
     *
     * @param  array<int, array{timestamp: int, open_interest: float}>  $oiData
     * @return array{ok: bool, growth: float|null}
     */
    private function checkOiRising(array $oiData): array
    {
        if (count($oiData) < 2) {
            return ['ok' => false, 'growth' => null];
        }

        $start = $oiData[0]['open_interest'];
        $end = $oiData[array_key_last($oiData)]['open_interest'];

        if ($start <= 0.0) {
            return ['ok' => false, 'growth' => null];
        }

        $growth = ($end - $start) / $start;

        return ['ok' => $growth > 0.10, 'growth' => $growth];
    }

    /**
     * Calculate price range over the last 6 × 4H candles.
     *
     * Matches Python _price_sideways_24h(): (high - low) / avg_close on last 6 candles.
     * Returns null if fewer than 6 candles or average close is zero.
     *
     * @param  array<int, array<string, mixed>>  $candles  4H candles
     */
    private function calcPriceRange24h(array $candles): ?float
    {
        if (count($candles) < 6) {
            return null;
        }

        $window = array_slice($candles, -6);
        $low = min(array_column($window, 'low'));
        $high = max(array_column($window, 'high'));
        $avgClose = array_sum(array_column($window, 'close')) / 6.0;

        if ($avgClose <= 0.0) {
            return null;
        }

        return ($high - $low) / $avgClose;
    }

    /**
     * Check low ATR compression — ATR(14) on SMA basis is below the 30-day ATR baseline.
     *
     * Matches Python:
     *   atr_14       = _calc_atr(candles, period=14)        — SMA ATR on all candles
     *   atr_baseline = _calc_atr(candles[-180:], period=30) — SMA ATR on last 180 candles
     *   atr_ok       = atr_14 < atr_baseline
     *
     * @param  array<int, array<string, mixed>>  $candles  4H candles
     * @return array{ok: bool, atr_14: float|null, atr_baseline: float|null, atr_ratio: float|null}
     */
    private function checkLowAtr(array $candles): array
    {
        $atr14 = $this->calcAtrSma($candles, self::ATR_PERIOD);

        $baselineWindow = array_slice($candles, -self::ATR_BASELINE_WINDOW);
        $atrBaseline = $this->calcAtrSma($baselineWindow, self::ATR_BASELINE_PERIOD);

        if ($atr14 === null || $atrBaseline === null || $atrBaseline <= 0.0) {
            return ['ok' => false, 'atr_14' => $atr14, 'atr_baseline' => $atrBaseline, 'atr_ratio' => null];
        }

        $ratio = $atr14 / $atrBaseline;

        return [
            'ok' => $atr14 < $atrBaseline,
            'atr_14' => $atr14,
            'atr_baseline' => $atrBaseline,
            'atr_ratio' => $ratio,
        ];
    }

    /**
     * Check whether CVD slope is positive over the last 6 × 4H candles.
     *
     * Sideways check is applied in the caller (cvd_ok = cvd_up AND sideways).
     *
     * CVD delta per candle = (2 × taker_buy_volume) − total_volume.
     * slope = (cvd[-1] − cvd[0]) / (n − 1). Matches Python _cvd_slope_positive().
     *
     * Returns ok=false and slope=null if taker_buy_volume is unavailable.
     *
     * @param  array<int, array<string, mixed>>  $candles  4H candles (must have taker_buy_volume)
     * @return array{ok: bool, slope: float|null}
     */
    private function checkCvdRising(array $candles): array
    {
        $lookback = 6;

        if (count($candles) < $lookback) {
            return ['ok' => false, 'slope' => null];
        }

        $window = array_slice($candles, -$lookback);
        $cvd = [];
        $cumulative = 0.0;

        foreach ($window as $candle) {
            $takerBuy = $candle['taker_buy_volume'] ?? null;
            $total = $candle['volume'] ?? null;

            if ($takerBuy === null || $total === null) {
                return ['ok' => false, 'slope' => null];
            }

            $cumulative += (2.0 * (float) $takerBuy) - (float) $total;
            $cvd[] = $cumulative;
        }

        if (count($cvd) < 2) {
            return ['ok' => false, 'slope' => null];
        }

        $slope = ($cvd[count($cvd) - 1] - $cvd[0]) / (count($cvd) - 1);

        return ['ok' => $slope > 0.0, 'slope' => $slope];
    }

    /**
     * Check RSI compression — RSI(14) stays in the 45–55 band for the last 5 × 4H candles.
     *
     * Matches Python _rsi_compression(candles_4h, low=45.0, high=55.0, min_candles=5).
     *
     * @param  array<int, array<string, mixed>>  $candles  4H candles
     * @return array{ok: bool, recent: float[]}
     */
    private function checkRsiCompression(array $candles): array
    {
        $closes = array_map(static fn (array $c): float => (float) $c['close'], $candles);
        $rsiSeries = $this->calculateRsiSeries($closes, 14);

        if (count($rsiSeries) < 5) {
            return ['ok' => false, 'recent' => $rsiSeries];
        }

        $recent5 = array_slice($rsiSeries, -5);
        $allInBand = count(array_filter(
            $recent5,
            static fn (float $r): bool => $r >= 45.0 && $r <= 55.0,
        )) === 5;

        return ['ok' => $allInBand, 'recent' => $recent5];
    }

    /**
     * Check volume drying — current 24H volume < 50% of 7-day average.
     *
     * Informational only; not included in the score (matching Python spec §5.5).
     * Matches Python _volume_drying(): ratio = current_24h / baseline; ok = ratio < 0.5.
     *
     * @param  float[]  $dailyVolumes  Daily volumes from CoinGecko (8 days)
     * @return array{ok: bool, baseline: float|null, ratio: float|null}
     */
    private function checkVolumeDrying(Coin $coin, array $dailyVolumes): array
    {
        $current24h = (float) ($coin->total_volume ?? $coin->volume_24h ?? 0.0);

        if ($current24h <= 0.0 || count($dailyVolumes) < 7) {
            return ['ok' => false, 'baseline' => null, 'ratio' => null];
        }

        $last7 = array_slice($dailyVolumes, -7);
        $baseline = array_sum($last7) / 7.0;

        if ($baseline <= 0.0) {
            return ['ok' => false, 'baseline' => $baseline, 'ratio' => null];
        }

        $ratio = $current24h / $baseline;

        return ['ok' => $ratio < 0.5, 'baseline' => $baseline, 'ratio' => $ratio];
    }

    /**
     * Check if OI is declining (last value < first value).
     *
     * Used as informational reference in metadata (Python: check_oi_decline(oi_data)).
     *
     * @param  array<int, array{timestamp: int, open_interest: float}>  $oiData
     */
    private function checkOiDeclining(array $oiData): bool
    {
        if (count($oiData) < 2) {
            return false;
        }

        return $oiData[array_key_last($oiData)]['open_interest'] < $oiData[0]['open_interest'];
    }

    /**
     * Calculate ATR using SMA of True Ranges (matching Python _sma(_true_ranges(candles), period)).
     *
     * Python uses simple rolling SMA, NOT Wilder's exponential smoothing.
     * Returns the last value of the SMA series, or null if data is insufficient.
     *
     * @param  array<int, array<string, mixed>>  $candles
     */
    private function calcAtrSma(array $candles, int $period): ?float
    {
        if (count($candles) < $period + 1) {
            return null;
        }

        // True Range from index 1 onward
        $trValues = [];

        for ($i = 1; $i < count($candles); $i++) {
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];
            $prevClose = (float) $candles[$i - 1]['close'];

            $trValues[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        if (count($trValues) < $period) {
            return null;
        }

        // Rolling SMA — return only the last value (matching Python _sma()[-1])
        $rollingSum = array_sum(array_slice($trValues, 0, $period));
        $lastSma = $rollingSum / $period;

        for ($i = $period; $i < count($trValues); $i++) {
            $rollingSum += $trValues[$i] - $trValues[$i - $period];
            $lastSma = $rollingSum / $period;
        }

        return $lastSma;
    }

    /**
     * Calculate RSI series using Wilder's smoothing on a list of close prices.
     *
     * Matches Python _calc_rsi_series() logic exactly.
     *
     * @param  float[]  $closes
     * @return float[]
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

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
        $rsiValues = [];

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;

            if ($avgLoss == 0.0) {
                $rsiValues[] = 100.0;

                continue;
            }

            $rs = $avgGain / $avgLoss;
            $rsiValues[] = 100.0 - (100.0 / (1.0 + $rs));
        }

        return $rsiValues;
    }

    /**
     * Map raw Binance kline rows to structured candle arrays.
     *
     * Binance kline field indices:
     *   [0]  open_time
     *   [1]  open
     *   [2]  high
     *   [3]  low
     *   [4]  close
     *   [5]  volume (base asset)
     *   [9]  taker_buy_base_asset_volume
     *
     * taker_buy_volume is required by checkCvdRising() for accurate CVD computation.
     *
     * @param  array<int, array<int, mixed>>  $klines
     * @return array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float, taker_buy_volume: float|null}>
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
                'taker_buy_volume' => isset($row[9]) && is_numeric($row[9]) ? (float) $row[9] : null,
            ];
        }

        return $candles;
    }
}
