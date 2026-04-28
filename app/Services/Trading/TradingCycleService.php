<?php

namespace App\Services\Trading;

use App\Models\MarketIndicator;
use App\Models\MarketRaw;
use App\Services\AI\AiAdvisorService;
use App\Services\Indicator\IndicatorService;
use App\Services\Market\CandleBuilderService;
use App\Services\Market\CandlePersistenceService;
use App\Services\Market\FetchMarketDataService;
use App\Services\Market\MarketContextPersistenceService;
use App\Services\Trading\DTO\IndicatorDTO;
use App\Services\Trading\DTO\MTFContextDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TradingCycleService
 *
 * Orchestrates the full trading cycle pipeline:
 * raw -> candles -> indicators -> MTF -> AI.
 */
class TradingCycleService
{
    private const INDICATOR_LOOKBACK = 200;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly TimeframeParser $timeframeParser,
        private readonly FetchMarketDataService $fetchMarketDataService,
        private readonly CandleBuilderService $candleBuilderService,
        private readonly CandlePersistenceService $candlePersistenceService,
        private readonly IndicatorService $indicatorService,
        private readonly SignalPreFilterService $mcpService,
        private readonly MTFContextService $mtfContextService,
        private readonly MarketContextPersistenceService $marketContextPersistenceService,
        private readonly AiAdvisorService $aiAdvisorService,
        private readonly SignalActivationService $signalActivationService,
        private readonly DecisionFusionService $decisionFusionService,
        private readonly GuardrailService $guardrailService,
        private readonly RiskService $riskService,
        private readonly SignalPersistenceService $signalPersistenceService,
        private readonly MTFDecisionService $mtfDecisionService,
    ) {}

    /**
     * @param  array<string>  $coins
     * @param  array<string>  $timeframes
     */
    public function run(string $executionId, array $coins = [], array $timeframes = []): void
    {
        $resolvedCoins = $coins !== []
            ? array_values(array_unique($coins))
            : $this->configService->getCoins();

        $resolvedTimeframes = $this->resolveTimeframes($timeframes);

        if ($resolvedCoins === [] || $resolvedTimeframes === []) {
            Log::warning('[TradingCycleService] Execution skipped due to missing config', [
                'execution_id' => $executionId,
                'coins_count' => count($resolvedCoins),
                'timeframes' => $resolvedTimeframes,
            ]);

            return;
        }

        foreach ($resolvedCoins as $coin) {
            try {
                $this->processCoin($coin, $resolvedTimeframes, $executionId);
            } catch (Throwable $exception) {
                Log::error('[TradingCycleService] Unexpected coin processing failure', [
                    'execution_id' => $executionId,
                    'coin' => $coin,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string>  $timeframes
     */
    private function processCoin(string $coin, array $timeframes, string $executionId): void
    {
        $marketData = $this->fetchMarketDataService->fetch($coin);

        if ($marketData === null) {
            Log::warning('[TradingCycleService] Market data unavailable', [
                'execution_id' => $executionId,
                'coin' => $coin,
            ]);

            return;
        }

        $this->persistRawData($coin, $marketData->requestParams, $marketData->rawResponse, $marketData->timestamps, $executionId);

        $candles = $this->candleBuilderService->build(
            coin: $coin,
            prices: (array) ($marketData->rawResponse['prices'] ?? $marketData->prices),
            volumes: (array) ($marketData->rawResponse['total_volumes'] ?? $marketData->volumes),
            timeframes: $timeframes,
        );

        if ($candles === []) {
            Log::warning('[TradingCycleService] No candles built', [
                'execution_id' => $executionId,
                'coin' => $coin,
            ]);

            return;
        }

        $this->candlePersistenceService->upsert($candles, $executionId);

        $indicatorDtos = $this->updateIndicatorsFromCandles($coin, $timeframes, $executionId);

        if ($indicatorDtos === []) {
            Log::warning('[TradingCycleService] No indicators available after candle update', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframes' => $timeframes,
            ]);

            return;
        }

        $timeframeSignals = $this->mcpService->filterSignals($coin, $timeframes, $indicatorDtos, $executionId);
        $mcpResults = $this->mcpService->lastMcpResults();

        $mtfResult = $this->mtfDecisionService->evaluate($coin, $mcpResults, $timeframes, $executionId);
        $mtfContext = $this->mtfContextService->buildDto($mtfResult);
        $timeframeSummary = $this->mtfDecisionService->buildTimeframeSummary($mtfResult->timeframeSignals);

        $baseSnapshot = [
            'execution_id' => $executionId,
            'mtf_score' => round($mtfResult->mtfScore, 4),
            'preliminary_action' => $mtfResult->preliminaryAction,
            'base_confidence' => $mtfResult->baseConfidence,
            'role_timeframes' => $mtfResult->roleTimeframes,
            'timeframe_summary' => $timeframeSummary,
        ];

        $this->marketContextPersistenceService->persist($mtfContext, $coin, $baseSnapshot);

        Log::info('[TradingCycleService] MTF context persisted', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'mtf_score' => $mtfContext->mtfScore,
            'alignment' => $mtfContext->alignment,
            'bias' => $mtfContext->bias,
            'timeframe_signals' => array_map(static fn($signal): array => $signal->toArray(), $timeframeSignals),
        ]);

        // --- Signal-first pipeline ---
        // Step 1: Determine if any signal exists (score >= 1 in any timeframe)
        $signalExists = false;
        foreach ($mcpResults as $mcpResult) {
            if ($mcpResult !== null && $mcpResult->score >= 1) {
                $signalExists = true;
                break;
            }
        }

        // Step 2: Call AI only if signal exists
        $aiDecision = null;
        $aiAdvice = null;
        if ($signalExists) {
            // Find best MCP result (highest score, highest timeframe when equal) for AI context
            $bestMcpResult = $this->selectBestTriggerMcp($mcpResults);

            if ($bestMcpResult !== null) {
                $entryIndicator = $this->buildEntryIndicator($coin, $timeframes);

                if ($entryIndicator !== null) {
                    $timeframeSummary = $this->mtfDecisionService->buildTimeframeSummary($mtfResult->timeframeSignals);

                    $aiAdvice = $this->aiAdvisorService->adviseFromContextDto(
                        coin: $coin,
                        entryTimeframe: $bestMcpResult->timeframe,
                        entryIndicator: $entryIndicator,
                        mtfContext: $mtfContext,
                        triggerMcpResult: $bestMcpResult,
                        mtfResult: $mtfResult,
                        timeframeSummary: $timeframeSummary,
                        executionId: $executionId,
                    );

                    $aiDecision = $aiAdvice?->decision ?? null;
                }
            }
        } else {
            Log::info('[SignalFlow] AI skipped: no signal exists', [
                'execution_id' => $executionId,
                'coin' => $coin,
            ]);
        }

        // Step 3: Adjust MTF score for activation (flags only, no confidence change)
        $activation = $this->signalActivationService->adjustFromConfig(
            mtfScore: $mtfContext->mtfScore,
            flags: $mtfContext->flags,
            executionId: $executionId,
            coin: $coin,
        );

        $activatedMtfContext = new MTFContextDTO(
            mtfScore: $activation['adjusted_score'],
            mtfRawScore: $mtfContext->mtfRawScore,
            direction: $mtfContext->direction,
            mode: $mtfContext->mode,
            alignment: $mtfContext->alignment,
            bias: $mtfContext->bias,
            timeframeSignals: $mtfContext->timeframeSignals,
            flags: $activation['flags'],
        );

        // Step 4: Fusion - ONLY place that determines: trigger_timeframe, final_action, confidence
        $fusionOutcome = $this->decisionFusionService->fuseOutcomeDto($mcpResults, $activatedMtfContext, $aiDecision);

        Log::info('[SignalFlow]', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'signal_exists' => $signalExists,
            'trigger_timeframe' => $fusionOutcome->decision->triggerTimeframe,
            'trigger_score' => $fusionOutcome->decision->triggerScore,
            'mtf_raw_score' => $activatedMtfContext->mtfRawScore,
            'mtf_effective_score' => $activatedMtfContext->mtfScore,
            'mtf_direction' => $activatedMtfContext->direction,
            'alignment' => $activatedMtfContext->alignment,
            'mode' => $activatedMtfContext->mode,
            'ai_used' => $aiAdvice !== null,
            'ai_agreement' => $fusionOutcome->metadata->aiAgreement,
            'final_action' => $fusionOutcome->decision->action,
            'final_confidence' => $fusionOutcome->decision->confidence,
        ]);

        // Step 5: Get trigger indicator from fusion outcome
        $triggerIndicator = $this->fetchLatestIndicator($coin, $fusionOutcome->decision->triggerTimeframe);

        if ($triggerIndicator === null) {
            Log::warning('[TradingCycleService] Trigger indicator not available', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'trigger_timeframe' => $fusionOutcome->decision->triggerTimeframe,
            ]);

            return;
        }

        // Step 6: Apply guardrails and risk
        $entryIndicator = new IndicatorDTO(
            timeframe: (string) $triggerIndicator->timeframe,
            rsi: (float) $triggerIndicator->rsi,
            trend: (string) $triggerIndicator->trend,
            volumeRatio: (float) ($triggerIndicator->volume_ratio ?? 0.0),
            price: (float) $triggerIndicator->price,
        );

        $guardedDecision = $this->guardrailService->apply($fusionOutcome->decision, $entryIndicator);

        $guardrailAccepted = in_array($guardedDecision->action, ['BUY', 'SELL'], true);

        $finalDecision = $this->riskService->apply(
            decision: $guardedDecision,
            entryPrice: (float) $triggerIndicator->price,
            priceChange24h: $this->resolvePriceChange24h($triggerIndicator),
            isSignalConfirmed: $guardrailAccepted,
        );

        $this->marketContextPersistenceService->persist($mtfContext, $coin, array_merge($baseSnapshot, [
            'fusion_ai_action' => $fusionOutcome->metadata->aiAction,
            'fusion_ai_confidence' => $fusionOutcome->metadata->aiConfidence,
            'fusion_final_action' => $fusionOutcome->metadata->finalAction,
            'fusion_confidence_adjusted' => $fusionOutcome->metadata->confidenceAdjusted,
            'final_action' => $finalDecision->action,
            'final_confidence' => $finalDecision->confidence,
            'decision_status' => in_array($finalDecision->action, ['BUY', 'SELL'], true) ? 'accepted' : 'rejected',
        ]));

        $this->signalPersistenceService->persist(
            executionId: $executionId,
            coin: $coin,
            triggerTimeframe: $fusionOutcome->decision->triggerTimeframe,
            triggerIndicator: $triggerIndicator,
            triggerMcpResult: null,
            finalDecision: $finalDecision,
            fusionMetadata: $fusionOutcome->metadata,
            mtfResult: $mtfResult,
            activatedMtfContext: $activatedMtfContext,
            timeframeSummary: $timeframeSummary,
            aiAdvice: $aiAdvice,
        );
    }

    /**
     * Build entry indicator - use best available timeframe indicator
     */
    private function buildEntryIndicator(string $coin, array $timeframes): ?IndicatorDTO
    {
        foreach ($timeframes as $timeframe) {
            $indicator = $this->fetchLatestIndicator($coin, $timeframe);
            if ($indicator !== null) {
                return new IndicatorDTO(
                    timeframe: (string) $indicator->timeframe,
                    rsi: (float) $indicator->rsi,
                    trend: (string) $indicator->trend,
                    volumeRatio: (float) ($indicator->volume_ratio ?? 0.0),
                    price: (float) $indicator->price,
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function resolveTimeframes(array $timeframes = []): array
    {
        $resolved = $timeframes !== [] ? $timeframes : $this->configService->getTimeframes();
        $valid = [];

        foreach ($resolved as $timeframe) {
            try {
                $this->timeframeParser->toSeconds($timeframe);
                $valid[] = trim($timeframe);
            } catch (\InvalidArgumentException) {
                Log::warning('[TradingCycleService] Ignoring unsupported timeframe', [
                    'timeframe' => $timeframe,
                ]);
            }
        }

        return $this->timeframeParser->sortUnique($valid);
    }

    /**
     * @return array<int, IndicatorDTO>
     */
    private function updateIndicatorsFromCandles(string $coin, array $timeframes, string $executionId): array
    {
        $results = [];

        foreach ($timeframes as $timeframe) {
            $rows = MarketIndicator::query()
                ->where('coin', $coin)
                ->where('timeframe', $timeframe)
                ->orderByDesc('timestamp')
                ->limit(self::INDICATOR_LOOKBACK)
                ->get(['id', 'price', 'volume', 'timestamp'])
                ->sortBy('timestamp')
                ->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $prices = $rows
                ->pluck('price')
                ->map(static fn(mixed $price): float => (float) $price)
                ->values()
                ->all();

            $computed = $this->indicatorService->calculateFromPrices($prices);

            if ($computed === null) {
                continue;
            }

            /** @var MarketIndicator $latest */
            $latest = $rows->last();

            MarketIndicator::query()
                ->whereKey($latest->id)
                ->update([
                    'execution_id' => $executionId,
                    'rsi' => $computed['rsi'],
                    'ema9' => $computed['ema9'],
                    'ema21' => $computed['ema21'],
                    'trend' => $computed['trend'],
                ]);

            $results[] = new IndicatorDTO(
                timeframe: $timeframe,
                rsi: (float) $computed['rsi'],
                trend: (string) $computed['trend'],
                volumeRatio: 0.0,
                price: (float) $computed['price'],
            );
        }

        Log::info('[TradingCycleService] Indicators updated from candles', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'indicator_count' => count($results),
        ]);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $requestParams
     * @param  array<string, mixed>  $rawResponse
     * @param  array<int, int>  $timestamps
     */
    private function persistRawData(
        string $coin,
        array $requestParams,
        array $rawResponse,
        array $timestamps,
        string $executionId,
    ): void {
        $lastTimestamp = $timestamps !== []
            ? (int) end($timestamps)
            : 0;

        if ($lastTimestamp > 0 && $lastTimestamp > 1000000000000) {
            $lastTimestamp = (int) floor($lastTimestamp / 1000);
        }

        $recordedAt = $lastTimestamp > 0
            ? CarbonImmutable::createFromTimestampUTC($lastTimestamp)
            : now()->toImmutable();

        $marketRaw = new MarketRaw;
        $marketRaw->execution_id = $executionId;
        $marketRaw->coin = $coin;
        $marketRaw->endpoint = 'market_chart';
        $marketRaw->timestamp = $recordedAt;
        $marketRaw->request_params = $requestParams;
        $marketRaw->response_json = $rawResponse;
        $marketRaw->source = 'coingecko';
        $marketRaw->save();
    }

    private function fetchLatestIndicator(string $coin, string $timeframe): ?MarketIndicator
    {
        return MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();
    }

    /**
     * Select best MCP result by score DESC, then timeframe DESC (higher timeframes preferred).
     * Only considers results with score >= 1 (valid signals).
     *
     * @param  array<string, McpResult|null>  $mcpResults  MCP results keyed by timeframe
     * @return McpResult|null Best trigger result, or null if no valid signal
     */
    private function selectBestTriggerMcp(array $mcpResults): ?McpResult
    {
        $validResults = array_filter(
            $mcpResults,
            fn(?McpResult $result) => $result !== null && $result->score >= 1,
        );

        if (empty($validResults)) {
            return null;
        }

        // Sort by: score DESC (higher scores first), timeframe DESC (higher timeframes when equal scores)
        usort($validResults, function (McpResult $a, McpResult $b): int {
            $scoreCompare = $b->score <=> $a->score; // DESC: higher scores first
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }
            // When scores are equal, prefer higher timeframe
            $aMinutes = $this->timeframeToMinutes($a->timeframe);
            $bMinutes = $this->timeframeToMinutes($b->timeframe);

            return $bMinutes <=> $aMinutes; // DESC: higher timeframes first
        });

        return reset($validResults);
    }

    /**
     * Convert timeframe string (e.g., '5m', '1h') to minutes for comparison.
     *
     * @param  string  $timeframe  Timeframe string (e.g., '5m', '15m', '1h')
     * @return int Minutes value, or PHP_INT_MAX for unknown formats
     */
    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        return PHP_INT_MAX;
    }

    private function resolvePriceChange24h(MarketIndicator $indicator): ?float
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $indicator->getAttributes();
        $priceChange24h = $attributes['price_change_24h'] ?? null;

        return is_numeric($priceChange24h)
            ? (float) $priceChange24h
            : null;
    }
}
