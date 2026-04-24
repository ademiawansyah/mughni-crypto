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
use App\Services\MCP\MCPService;
use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\IndicatorDTO;
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
        private readonly MCPService $mcpService,
        private readonly MTFContextService $mtfContextService,
        private readonly MarketContextPersistenceService $marketContextPersistenceService,
        private readonly AiAdvisorService $aiAdvisorService,
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
        $triggerTimeframe = $mtfResult->roleTimeframes['trigger'] ?? ($timeframes[0] ?? '5m');
        $triggerMcpResult = $mcpResults[$triggerTimeframe] ?? null;
        $timeframeSummary = $this->mtfDecisionService->buildTimeframeSummary($mtfResult->timeframeSignals);

        $baseSnapshot = [
            'execution_id' => $executionId,
            'mcp_passed' => $triggerMcpResult !== null,
            'mcp_score' => $triggerMcpResult?->score,
            'mcp_candidate' => $triggerMcpResult?->actionCandidate->value,
            'mcp_timeframe' => $triggerTimeframe,
            'mcp_reason' => $triggerMcpResult === null ? 'no_trigger_candidate' : null,
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

        if ($triggerMcpResult === null) {
            $this->marketContextPersistenceService->persist($mtfContext, $coin, array_merge($baseSnapshot, [
                'final_action' => 'HOLD',
                'final_confidence' => 0,
                'decision_status' => 'skipped_mcp',
            ]));

            Log::info('[TradingCycleService] AI skipped because trigger MCP did not pass', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'trigger_timeframe' => $triggerTimeframe,
            ]);

            return;
        }

        $triggerIndicator = $this->fetchLatestIndicator($coin, $triggerTimeframe);

        if ($triggerIndicator === null) {
            $this->marketContextPersistenceService->persist($mtfContext, $coin, array_merge($baseSnapshot, [
                'final_action' => 'HOLD',
                'final_confidence' => 0,
                'decision_status' => 'missing_trigger_indicator',
            ]));

            Log::warning('[TradingCycleService] Missing trigger indicator', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'trigger_timeframe' => $triggerTimeframe,
            ]);

            return;
        }

        $entryIndicator = new IndicatorDTO(
            timeframe: (string) $triggerIndicator->timeframe,
            rsi: (float) $triggerIndicator->rsi,
            trend: (string) $triggerIndicator->trend,
            volumeRatio: (float) ($triggerIndicator->volume_ratio ?? 0.0),
            price: (float) $triggerIndicator->price,
        );

        $aiAdvice = $this->aiAdvisorService->adviseFromContextDto(
            coin: $coin,
            entryTimeframe: $triggerTimeframe,
            entryIndicator: $entryIndicator,
            mtfContext: $mtfContext,
            triggerMcpResult: $triggerMcpResult,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            executionId: $executionId,
        );

        $aiDecision = $aiAdvice?->decision ?? new AiDecisionDTO(
            action: 'HOLD',
            confidence: 0,
            reason: 'AI decision unavailable',
        );

        $fusionOutcome = $this->decisionFusionService->fuseOutcomeDto($aiDecision, $mtfContext);
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
            triggerTimeframe: $triggerTimeframe,
            triggerIndicator: $triggerIndicator,
            triggerMcpResult: $triggerMcpResult,
            finalDecision: $finalDecision,
            fusionMetadata: $fusionOutcome->metadata,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            aiAdvice: $aiAdvice,
        );
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
