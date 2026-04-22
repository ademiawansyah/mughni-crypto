<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Models\MarketIndicator;
use App\Services\AI\AiAdvisorService;
use App\Services\Indicator\IndicatorService;
use App\Services\Market\CandleBuilderService;
use App\Services\Market\FetchMarketDataService;
use App\Services\MCP\MCPService;
use App\Services\Trading\DecisionFusionService;
use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\IndicatorDTO;
use App\Services\Trading\GuardrailService;
use App\Services\Trading\MTFContextService;
use App\Services\Trading\MTFDecisionService;
use App\Services\Trading\RiskService;
use App\Services\Trading\SignalPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RunTradingCycleJob
 *
 * DTO-driven orchestration for trading cycle pipeline.
 */
class RunTradingCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @var array<int>
     */
    public array $backoff = [60, 120];

    /**
     * @param  array<string>  $coins
     * @param  array<string>  $timeframes
     */
    public function __construct(
        private readonly array $coins = [],
        private readonly array $timeframes = [],
        private readonly string $executionId = '',
    ) {}

    /**
     * Execute full trading cycle pipeline for configured coins/timeframes.
     */
    public function handle(
        FetchMarketDataService $fetchMarketDataService,
        CandleBuilderService $candleBuilderService,
        IndicatorService $indicatorService,
        MCPService $mcpService,
        MTFContextService $mtfContextService,
        AiAdvisorService $aiAdvisorService,
        DecisionFusionService $decisionFusionService,
        GuardrailService $guardrailService,
        RiskService $riskService,
        SignalPersistenceService $signalPersistenceService,
        MTFDecisionService $mtfDecisionService,
    ): void {
        Log::info('[RunTradingCycleJob] Execution started', [
            'execution_id' => $this->executionId,
        ]);

        $coins = $this->resolveCoins();
        $timeframes = $this->resolveTimeframes();

        foreach ($coins as $coin) {
            try {
                $this->processCoin(
                    coin: $coin,
                    timeframes: $timeframes,
                    fetchMarketDataService: $fetchMarketDataService,
                    candleBuilderService: $candleBuilderService,
                    indicatorService: $indicatorService,
                    mcpService: $mcpService,
                    mtfContextService: $mtfContextService,
                    aiAdvisorService: $aiAdvisorService,
                    decisionFusionService: $decisionFusionService,
                    guardrailService: $guardrailService,
                    riskService: $riskService,
                    signalPersistenceService: $signalPersistenceService,
                    mtfDecisionService: $mtfDecisionService,
                );
            } catch (Throwable $e) {
                Log::error('[RunTradingCycleJob] Unexpected failure — skipping coin', [
                    'execution_id' => $this->executionId,
                    'coin' => $coin,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('[RunTradingCycleJob] Execution completed', [
            'execution_id' => $this->executionId,
        ]);
    }

    /**
     * @param  array<string>  $timeframes
     */
    private function processCoin(
        string $coin,
        array $timeframes,
        FetchMarketDataService $fetchMarketDataService,
        CandleBuilderService $candleBuilderService,
        IndicatorService $indicatorService,
        MCPService $mcpService,
        MTFContextService $mtfContextService,
        AiAdvisorService $aiAdvisorService,
        DecisionFusionService $decisionFusionService,
        GuardrailService $guardrailService,
        RiskService $riskService,
        SignalPersistenceService $signalPersistenceService,
        MTFDecisionService $mtfDecisionService,
    ): void {
        $marketData = $fetchMarketDataService->fetch($coin);

        if ($marketData === null) {
            Log::warning('[RunTradingCycleJob] Market fetch returned null', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
            ]);

            return;
        }

        $candles = $candleBuilderService->build($marketData, $timeframes);
        $indicators = $indicatorService->calculateFromCandles($candles, $timeframes);

        Log::info('[RunTradingCycleJob] IndicatorDTO prepared', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'indicator_count' => count($indicators),
        ]);

        $timeframeSignals = $mcpService->filterSignals($coin, $timeframes, $indicators, $this->executionId);
        $mcpResults = $mcpService->lastMcpResults();

        $mtfResult = $mtfDecisionService->evaluate($coin, $mcpResults, $timeframes, $this->executionId);
        $mtfContext = $mtfContextService->buildDto($mtfResult);

        Log::info('[RunTradingCycleJob] MTFContextDTO produced', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'mtf_score' => $mtfContext->mtfScore,
            'alignment' => $mtfContext->alignment,
            'bias' => $mtfContext->bias,
            'timeframe_signals' => array_map(static fn ($signal): array => $signal->toArray(), $timeframeSignals),
        ]);

        $triggerTimeframe = $mtfResult->roleTimeframes['trigger'];
        $triggerMcpResult = $mcpResults[$triggerTimeframe] ?? null;

        if ($triggerMcpResult === null) {
            Log::info('[RunTradingCycleJob] AI skipped because trigger MCP did not pass', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'trigger_timeframe' => $triggerTimeframe,
            ]);

            return;
        }

        $triggerIndicator = $this->fetchLatestIndicator($coin, $triggerTimeframe);

        if ($triggerIndicator === null) {
            Log::warning('[RunTradingCycleJob] Missing trigger indicator — skipping coin', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'trigger_timeframe' => $triggerTimeframe,
            ]);

            return;
        }

        $entryIndicator = $this->toIndicatorDto($triggerIndicator);
        $timeframeSummary = $mtfDecisionService->buildTimeframeSummary($mtfResult->timeframeSignals);

        $aiAdvice = $aiAdvisorService->adviseFromContextDto(
            coin: $coin,
            entryTimeframe: $triggerTimeframe,
            entryIndicator: $entryIndicator,
            mtfContext: $mtfContext,
            triggerMcpResult: $triggerMcpResult,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            executionId: $this->executionId,
        );

        $aiDecision = $aiAdvice?->decision ?? new AiDecisionDTO(
            action: 'HOLD',
            confidence: 0,
            reason: 'AI decision unavailable',
        );

        Log::info('[RunTradingCycleJob] AiDecisionDTO produced', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'action' => $aiDecision->action,
            'confidence' => $aiDecision->confidence,
            'reason' => $aiDecision->reason,
        ]);

        $fusionOutcome = $decisionFusionService->fuseOutcomeDto($aiDecision, $mtfContext);
        $guardedDecision = $guardrailService->apply($fusionOutcome->decision, $entryIndicator);

        $guardrailAccepted = in_array($guardedDecision->action, ['BUY', 'SELL'], true);

        $finalDecision = $riskService->apply(
            decision: $guardedDecision,
            entryPrice: (float) $triggerIndicator->price,
            priceChange24h: $this->resolvePriceChange24h($triggerIndicator),
            isSignalConfirmed: $guardrailAccepted,
        );

        Log::info('[RunTradingCycleJob] FinalDecisionDTO produced', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'decision' => $finalDecision->toArray(),
        ]);

        $signalPersistenceService->persist(
            executionId: $this->executionId,
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
     * @return array<string>
     */
    private function resolveCoins(): array
    {
        $coins = ! empty($this->coins)
            ? $this->coins
            : GeneralConfig::getCoins();

        return array_values(array_unique($coins));
    }

    /**
     * @return array<string>
     */
    private function resolveTimeframes(): array
    {
        $timeframes = ! empty($this->timeframes)
            ? $this->timeframes
            : GeneralConfig::getTimeframes();

        return array_values(array_unique($timeframes));
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

    private function toIndicatorDto(MarketIndicator $indicator): IndicatorDTO
    {
        return new IndicatorDTO(
            timeframe: (string) $indicator->timeframe,
            rsi: (float) $indicator->rsi,
            trend: (string) $indicator->trend,
            volumeRatio: (float) ($indicator->volume_ratio ?? 0.0),
            price: (float) $indicator->price,
        );
    }
}
