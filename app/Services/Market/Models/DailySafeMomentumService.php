<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Services\Market\DTO\DailySafeMomentumAnalysisDTO;
use App\Services\Market\MarketRegimeService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DailySafeMomentumService
{
    private const MODEL_NAME = 'daily_safe_momentum';

    private const MODEL_VERSION = '1.0';

    private const MAX_RESULTS = 10;

    private const DAILY_LIMIT = 260;

    private const TREND_LIMIT = 220;

    private const ENTRY_LIMIT = 120;

    private const MIN_TREND_CANDLES = 210;

    private const MIN_ENTRY_CANDLES = 40;

    /** @var array<int, string> */
    private const PREFERRED_SYMBOLS = ['btc', 'eth', 'sol', 'bnb', 'xrp'];

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Execute Model 5B (Daily Safe Momentum) scanning pipeline.
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
        $minimumScore = (float) config('models.daily_safe_momentum.min_score', 75);

        Log::info('[DailySafeMomentumService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'minimum_score' => $minimumScore,
        ]);

        $candidates = $this->filterCoins();
        $btcSafetyGate = $this->evaluateBtcSafetyGate();

        $passedDtos = [];
        $rejectedDtos = [];
        $failedCoins = [];

        foreach ($candidates as $coin) {
            if (! $btcSafetyGate['passed']) {
                $rejectedDto = DailySafeMomentumAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: strtoupper((string) $coin->symbol),
                    rejectionReason: 'btc_market_safety_gate_failed',
                    rejectionContext: $btcSafetyGate['context'],
                    score: 0,
                    price: (float) ($coin->current_price ?? 0),
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

            $trendKlines = $this->marketRegimeService->getOhlcvDataForCoin(
                (string) $coin->symbol,
                '4h',
                self::TREND_LIMIT,
            );

            $entryKlines = $this->marketRegimeService->getOhlcvDataForCoin(
                (string) $coin->symbol,
                '1h',
                self::ENTRY_LIMIT,
            );

            if ($trendKlines === [] || $entryKlines === []) {
                $rejectedDto = DailySafeMomentumAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: strtoupper((string) $coin->symbol),
                    rejectionReason: 'missing_ohlcv',
                    rejectionContext: [
                        'trend_timeframe' => '4h',
                        'entry_timeframe' => '1h',
                    ],
                    score: 0,
                    price: (float) ($coin->current_price ?? 0),
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

            $analysis = $this->analyzeCandidate(
                coin: $coin,
                trendKlines: $trendKlines,
                entryKlines: $entryKlines,
                minimumScore: $minimumScore,
            );

            if ($analysis['signal'] === null) {
                $rejectedDto = DailySafeMomentumAnalysisDTO::rejected(
                    executionId: $resolvedExecutionId,
                    coinId: $coin->id,
                    symbol: strtoupper((string) $coin->symbol),
                    rejectionReason: (string) $analysis['rejection_reason'],
                    rejectionContext: $analysis['rejection_context'],
                    score: (float) $analysis['score'],
                    price: (float) ($coin->current_price ?? 0),
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

            $passedDto = DailySafeMomentumAnalysisDTO::passed(
                executionId: $resolvedExecutionId,
                coinId: $coin->id,
                symbol: strtoupper((string) $coin->symbol),
                score: (float) $analysis['signal']['total_score'],
                price: (float) $analysis['signal']['price'],
                signal: $analysis['signal'],
                components: $analysis['signal']['components'],
                metadata: $analysis['signal']['metadata'],
            );

            $passedDtos[] = $passedDto;
        }

        usort(
            $passedDtos,
            static fn (DailySafeMomentumAnalysisDTO $left, DailySafeMomentumAnalysisDTO $right): int => $right->score <=> $left->score,
        );

        $limitedDtos = array_slice($passedDtos, 0, self::MAX_RESULTS);
        $ranked = array_values(array_map(
            static function (DailySafeMomentumAnalysisDTO $dto, int $index): array {
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
            'btc_safety_gate' => $btcSafetyGate,
            'all_scored_results' => array_values(array_map(
                static fn (DailySafeMomentumAnalysisDTO $dto): array => (array) ($dto->signal ?? []),
                $passedDtos,
            )),
            'analysis_results' => array_values(array_map(
                static fn (DailySafeMomentumAnalysisDTO $dto): array => $dto->toArray(),
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

        $this->notificationService->sendModelExecutionResult([
            'execution_id' => $resolvedExecutionId,
            'model' => self::MODEL_NAME,
            'evaluated' => $supportingData['evaluated'],
            'shortlisted' => $supportingData['shortlisted'],
            'results' => $ranked,
        ]);

        $result = array_merge($standardOutput, [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $supportingData['evaluated'],
            'shortlisted' => $supportingData['shortlisted'],
        ]);

        Log::info('[DailySafeMomentumService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
            'btc_gate_passed' => $btcSafetyGate['passed'],
        ]);

        return $result;
    }

    /**
     * Layer 3 candidate selection for Model 5B.
     *
     * @return Collection<int, Coin>
     */
    private function filterCoins(): Collection
    {
        return Coin::query()
            ->where('is_valid', true)
            ->whereIn('symbol', self::PREFERRED_SYMBOLS)
            ->where(function ($query) {
                $query->where('total_volume', '>=', 20_000_000)
                    ->orWhere('volume_24h', '>=', 20_000_000);
            })
            ->where('current_price', '>', 0)
            ->whereRaw("COALESCE((raw_data->>'market_cap_rank')::int, 999999) BETWEEN ? AND ?", [1, 30])
            ->get();
    }

    /**
     * @return array{passed: bool, context: array<string, mixed>}
     */
    private function evaluateBtcSafetyGate(): array
    {
        $dailyKlines = $this->marketRegimeService->getOhlcvDataForCoin('btc', '1d', self::DAILY_LIMIT);
        $h4Klines = $this->marketRegimeService->getOhlcvDataForCoin('btc', '4h', 120);

        if ($dailyKlines === [] || $h4Klines === []) {
            return [
                'passed' => false,
                'context' => [
                    'reason' => 'missing_btc_ohlcv',
                    'daily_present' => $dailyKlines !== [],
                    'h4_present' => $h4Klines !== [],
                ],
            ];
        }

        $dailyCandles = $this->mapKlinesToCandles($dailyKlines);
        $h4Candles = $this->mapKlinesToCandles($h4Klines);

        if (count($dailyCandles) < 210 || count($h4Candles) < 60) {
            return [
                'passed' => false,
                'context' => [
                    'reason' => 'insufficient_btc_candles',
                    'daily_actual' => count($dailyCandles),
                    'h4_actual' => count($h4Candles),
                ],
            ];
        }

        $dailyCloses = array_column($dailyCandles, 'close');
        $h4Closes = array_column($h4Candles, 'close');

        $btcClose = $dailyCloses[array_key_last($dailyCloses)];
        $ema50 = $this->calculateEma($dailyCloses, 50);
        $ema200 = $this->calculateEma($dailyCloses, 200);
        $dailyRsi = $this->calculateRsi($dailyCloses, 14);

        $lastDaily = $dailyCandles[array_key_last($dailyCandles)];
        $dailyBody = $lastDaily['close'] - $lastDaily['open'];
        $dailyRange = max($lastDaily['high'] - $lastDaily['low'], 0.00000001);
        $isLargeBearCandle = $dailyBody < 0 && (abs($dailyBody) / $dailyRange) >= 0.6;

        $dailyReturns = [];

        for ($index = max(1, count($dailyCloses) - 21); $index < count($dailyCloses); $index++) {
            $previous = $dailyCloses[$index - 1];

            if ($previous <= 0) {
                continue;
            }

            $dailyReturns[] = ($dailyCloses[$index] - $previous) / $previous;
        }

        $volatilityStd = $this->standardDeviation($dailyReturns);
        $isExtremeVolatility = $volatilityStd > 0.08;

        $ema20H4 = $this->calculateEma($h4Closes, 20);
        $h4LatestClose = $h4Closes[array_key_last($h4Closes)];
        $h4BullishOrNeutral = $h4LatestClose >= ($ema20H4 * 0.995);

        $passed =
            $btcClose > $ema50 &&
            $ema50 > $ema200 &&
            $dailyRsi >= 50 && $dailyRsi <= 68 &&
            ! $isLargeBearCandle &&
            ! $isExtremeVolatility &&
            $h4BullishOrNeutral;

        return [
            'passed' => $passed,
            'context' => [
                'btc_close' => round($btcClose, 8),
                'ema50' => round($ema50, 8),
                'ema200' => round($ema200, 8),
                'rsi_daily' => round($dailyRsi, 4),
                'large_bear_candle' => $isLargeBearCandle,
                'volatility_std' => round($volatilityStd, 6),
                'extreme_volatility' => $isExtremeVolatility,
                'h4_latest_close' => round($h4LatestClose, 8),
                'h4_ema20' => round($ema20H4, 8),
                'h4_bullish_or_neutral' => $h4BullishOrNeutral,
            ],
        ];
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
    private function analyzeCandidate(Coin $coin, array $trendKlines, array $entryKlines, float $minimumScore): array
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

        $trendCloses = array_column($trendCandles, 'close');
        $entryCloses = array_column($entryCandles, 'close');

        $close = $trendCloses[array_key_last($trendCloses)];
        $ema20 = $this->calculateEma($trendCloses, 20);
        $ema50 = $this->calculateEma($trendCloses, 50);
        $ema200 = $this->calculateEma($trendCloses, 200);
        $rsiTrend = $this->calculateRsi($trendCloses, 14);
        $rsiEntry = $this->calculateRsi($entryCloses, 14);

        $macd = $this->calculateMacd($trendCloses);
        $histogramPct = $close > 0 ? abs($macd['histogram']) / $close * 100 : 0;

        $emaGate = $close > $ema20 && $ema20 > $ema50;
        $rsiGate = $rsiTrend >= 55 && $rsiTrend <= 65;
        $macdGate = $macd['macd'] > $macd['signal'] && $macd['signal'] > 0 && $macd['histogram'] > 0 && $histogramPct <= 0.8;

        if (! $emaGate || ! $rsiGate || ! $macdGate) {
            return [
                'signal' => null,
                'rejection_reason' => 'trend_validation_failed',
                'rejection_context' => [
                    'ema_gate' => $emaGate,
                    'rsi_gate' => $rsiGate,
                    'macd_gate' => $macdGate,
                    'close' => round($close, 8),
                    'ema20' => round($ema20, 8),
                    'ema50' => round($ema50, 8),
                    'ema200' => round($ema200, 8),
                    'rsi_trend' => round($rsiTrend, 4),
                    'macd' => round($macd['macd'], 8),
                    'signal' => round($macd['signal'], 8),
                    'histogram' => round($macd['histogram'], 8),
                ],
                'score' => 0,
            ];
        }

        $recent = array_slice($entryCandles, -12);
        $latest = $recent[array_key_last($recent)];
        $previous = $recent[count($recent) - 2] ?? $latest;

        $referenceCandles = array_slice($recent, 0, max(count($recent) - 3, 1));
        $swingHigh = max(array_column($referenceCandles, 'high'));
        $pullbackDepthPct = $swingHigh > 0 ? (($swingHigh - $latest['close']) / $swingHigh) * 100 : 0;

        $consecutiveRed = 0;
        for ($index = count($recent) - 2; $index >= 0; $index--) {
            if ($recent[$index]['close'] < $recent[$index]['open']) {
                $consecutiveRed++;

                continue;
            }

            break;
        }

        $redStartIndex = max(0, (count($recent) - 1) - $consecutiveRed);
        $breakoutIndex = max(0, $redStartIndex - 1);
        $breakoutVolume = (float) ($recent[$breakoutIndex]['volume'] ?? 0);

        $pullbackVolumes = [];
        for ($index = $redStartIndex; $index < count($recent) - 1; $index++) {
            $pullbackVolumes[] = (float) ($recent[$index]['volume'] ?? 0);
        }
        $pullbackAvgVolume = $this->average($pullbackVolumes);

        $ema20Entry = $this->calculateEma($entryCloses, 20);
        $bullishReclaim =
            $latest['close'] > $latest['open'] &&
            $latest['close'] > $previous['close'] &&
            $latest['close'] >= $ema20Entry;

        $pullbackGate =
            $pullbackDepthPct >= 1.0 && $pullbackDepthPct <= 3.0 &&
            $consecutiveRed >= 1 && $consecutiveRed <= 3 &&
            $pullbackAvgVolume > 0 && $breakoutVolume > 0 && $pullbackAvgVolume < $breakoutVolume &&
            $bullishReclaim;

        if (! $pullbackGate) {
            return [
                'signal' => null,
                'rejection_reason' => 'pullback_validation_failed',
                'rejection_context' => [
                    'pullback_depth_pct' => round($pullbackDepthPct, 4),
                    'pullback_duration' => $consecutiveRed,
                    'pullback_avg_volume' => round($pullbackAvgVolume, 4),
                    'breakout_volume' => round($breakoutVolume, 4),
                    'bullish_reclaim' => $bullishReclaim,
                ],
                'score' => 0,
            ];
        }

        $rawData = is_array($coin->raw_data) ? $coin->raw_data : [];
        $gain24h = (float) ($rawData['price_change_percentage_24h'] ?? 0);

        $entryRecent = array_slice($entryCandles, -20);
        $avgVolume20 = $this->average(array_column($entryRecent, 'volume'));
        $rangePct = $latest['open'] > 0 ? (($latest['high'] - $latest['low']) / $latest['open']) * 100 : 0;
        $verticalMove3 = 0.0;

        if (count($entryCandles) >= 4) {
            $fourBackClose = $entryCandles[count($entryCandles) - 4]['close'];
            if ($fourBackClose > 0) {
                $verticalMove3 = (($latest['close'] - $fourBackClose) / $fourBackClose) * 100;
            }
        }

        $antiEuphoria = [
            'gain_too_large' => $gain24h > 15,
            'rsi_overbought' => $rsiTrend > 70 || $rsiEntry > 70,
            'huge_candle_expansion' => $rangePct > 7,
            'volume_explosion' => $avgVolume20 > 0 ? $latest['volume'] > ($avgVolume20 * 2.8) : false,
            'vertical_price_move' => $verticalMove3 > 8,
        ];

        if (in_array(true, $antiEuphoria, true)) {
            return [
                'signal' => null,
                'rejection_reason' => 'anti_euphoria_filter_triggered',
                'rejection_context' => array_merge($antiEuphoria, [
                    'gain_24h' => round($gain24h, 4),
                    'rsi_trend' => round($rsiTrend, 4),
                    'rsi_entry' => round($rsiEntry, 4),
                    'range_pct' => round($rangePct, 4),
                    'vertical_move_3h' => round($verticalMove3, 4),
                ]),
                'score' => 0,
            ];
        }

        $rank = (int) ($rawData['market_cap_rank'] ?? 999);
        $volume24h = (float) ($coin->total_volume ?? $coin->volume_24h ?? 0);

        $emaScore = $ema50 > $ema200 ? 100.0 : 82.0;
        $rsiScore = max(0.0, min(100.0, 100 - (abs($rsiTrend - 60) * 10)));
        $macdScore = max(0.0, min(100.0, 100 - ($histogramPct * 25)));

        $depthScore = max(0.0, min(100.0, 100 - (abs($pullbackDepthPct - 2.0) * 50)));
        $durationScore = max(0.0, min(100.0, 100 - (abs($consecutiveRed - 2) * 40)));
        $volumeScore = $pullbackAvgVolume < $breakoutVolume ? 100.0 : 60.0;
        $reclaimScore = $bullishReclaim ? 100.0 : 40.0;
        $pullbackScore = ($depthScore + $durationScore + $volumeScore + $reclaimScore) / 4;

        $rankScore = match (true) {
            $rank <= 10 => 100.0,
            $rank <= 20 => 90.0,
            $rank <= 30 => 80.0,
            default => 60.0,
        };

        $liquidityVolumeScore = match (true) {
            $volume24h >= 100_000_000 => 100.0,
            $volume24h >= 50_000_000 => 90.0,
            $volume24h >= 20_000_000 => 80.0,
            default => 60.0,
        };

        $liquidityScore = ($rankScore + $liquidityVolumeScore) / 2;

        $weights = [
            'btc_market_safety' => (float) config('models.daily_safe_momentum.scoring.btc_market_safety', 0.25),
            'ema_alignment' => (float) config('models.daily_safe_momentum.scoring.ema_alignment', 0.25),
            'rsi_health' => (float) config('models.daily_safe_momentum.scoring.rsi_health', 0.15),
            'macd_confirmation' => (float) config('models.daily_safe_momentum.scoring.macd_confirmation', 0.15),
            'pullback_quality' => (float) config('models.daily_safe_momentum.scoring.pullback_quality', 0.15),
            'liquidity_quality' => (float) config('models.daily_safe_momentum.scoring.liquidity_quality', 0.05),
        ];

        $totalScore =
            (100.0 * $weights['btc_market_safety']) +
            ($emaScore * $weights['ema_alignment']) +
            ($rsiScore * $weights['rsi_health']) +
            ($macdScore * $weights['macd_confirmation']) +
            ($pullbackScore * $weights['pullback_quality']) +
            ($liquidityScore * $weights['liquidity_quality']);

        $confidenceGrade = $this->resolveConfidenceGrade($totalScore);

        if (! in_array($confidenceGrade, ['A', 'B'], true)) {
            return [
                'signal' => null,
                'rejection_reason' => 'confidence_grade_not_allowed',
                'rejection_context' => [
                    'grade' => $confidenceGrade,
                    'score' => round($totalScore, 2),
                    'required' => ['A', 'B'],
                ],
                'score' => round($totalScore, 2),
            ];
        }

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

        $pullbackWindow = array_slice($recent, -max($consecutiveRed + 1, 2));
        $pullbackLow = min(array_column($pullbackWindow, 'low'));
        $stopLoss = $pullbackLow * 0.995;

        return [
            'signal' => [
                'symbol' => strtoupper((string) $coin->symbol).'USDT',
                'price' => round((float) ($coin->current_price ?? $latest['close']), 8),
                'total_score' => round($totalScore, 2),
                'components' => [
                    'btc_market_safety_score' => 100,
                    'ema_alignment_score' => round($emaScore, 2),
                    'rsi_health_score' => round($rsiScore, 2),
                    'macd_confirmation_score' => round($macdScore, 2),
                    'pullback_quality_score' => round($pullbackScore, 2),
                    'liquidity_quality_score' => round($liquidityScore, 2),
                    'anti_euphoria_passed' => true,
                ],
                'metadata' => [
                    'structure_timeframe' => '4H',
                    'entry_timeframe' => '1H',
                    'market_filter_timeframe' => '1D',
                    'strategy' => self::MODEL_NAME,
                    'confidence_grade' => $confidenceGrade,
                    'trade_direction' => 'LONG_ONLY',
                    'leverage_allowed' => false,
                    'pullback_depth_pct' => round($pullbackDepthPct, 4),
                    'pullback_duration' => $consecutiveRed,
                    'pullback_avg_volume' => round($pullbackAvgVolume, 4),
                    'breakout_volume' => round($breakoutVolume, 4),
                    'ema20' => round($ema20, 8),
                    'ema50' => round($ema50, 8),
                    'ema200' => round($ema200, 8),
                    'rsi_trend' => round($rsiTrend, 4),
                    'macd' => round($macd['macd'], 8),
                    'signal' => round($macd['signal'], 8),
                    'histogram' => round($macd['histogram'], 8),
                    'stop_loss' => round($stopLoss, 8),
                    'entry_point' => round((float) ($coin->current_price ?? $latest['close']), 8),
                    'take_profit_hint_pct' => '1-3',
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
            'score' => round($totalScore, 2),
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $klines
     * @return array<int, array{open: float, high: float, low: float, close: float, volume: float}>
     */
    private function mapKlinesToCandles(array $klines): array
    {
        return array_values(array_map(static function (array $kline): array {
            return [
                'open' => (float) ($kline[1] ?? 0),
                'high' => (float) ($kline[2] ?? 0),
                'low' => (float) ($kline[3] ?? 0),
                'close' => (float) ($kline[4] ?? 0),
                'volume' => (float) ($kline[5] ?? 0),
            ];
        }, $klines));
    }

    /**
     * @param  array<int, float>  $values
     */
    private function calculateEma(array $values, int $period): float
    {
        if ($values === []) {
            return 0.0;
        }

        if (count($values) < $period) {
            return (float) end($values);
        }

        $multiplier = 2 / ($period + 1);
        $ema = $this->average(array_slice($values, 0, $period));

        for ($index = $period; $index < count($values); $index++) {
            $ema = (($values[$index] - $ema) * $multiplier) + $ema;
        }

        return $ema;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function calculateRsi(array $values, int $period = 14): float
    {
        if (count($values) <= $period) {
            return 50.0;
        }

        $gains = [];
        $losses = [];

        for ($index = 1; $index < count($values); $index++) {
            $delta = $values[$index] - $values[$index - 1];
            $gains[] = max($delta, 0);
            $losses[] = abs(min($delta, 0));
        }

        $avgGain = $this->average(array_slice($gains, 0, $period));
        $avgLoss = $this->average(array_slice($losses, 0, $period));

        for ($index = $period; $index < count($gains); $index++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$index]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$index]) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $relativeStrength = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $relativeStrength));
    }

    /**
     * @param  array<int, float>  $values
     * @return array{macd: float, signal: float, histogram: float}
     */
    private function calculateMacd(array $values): array
    {
        if ($values === []) {
            return ['macd' => 0.0, 'signal' => 0.0, 'histogram' => 0.0];
        }

        $macdSeries = [];

        for ($index = 0; $index < count($values); $index++) {
            $slice = array_slice($values, 0, $index + 1);
            $emaFast = $this->calculateEma($slice, 12);
            $emaSlow = $this->calculateEma($slice, 26);
            $macdSeries[] = $emaFast - $emaSlow;
        }

        $macd = (float) end($macdSeries);
        $signal = $this->calculateEma($macdSeries, 9);

        return [
            'macd' => $macd,
            'signal' => $signal,
            'histogram' => $macd - $signal,
        ];
    }

    /**
     * @param  array<int, float|int>  $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function standardDeviation(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = $this->average($values);
        $varianceSum = 0.0;

        foreach ($values as $value) {
            $varianceSum += ($value - $mean) ** 2;
        }

        return sqrt($varianceSum / count($values));
    }

    private function resolveConfidenceGrade(float $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 75 => 'B',
            $score >= 65 => 'C',
            default => 'D',
        };
    }
}
