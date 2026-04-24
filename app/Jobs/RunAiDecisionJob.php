<?php

namespace App\Jobs;

use App\Models\AiDecision;
use App\Models\GeneralConfig;
use App\Models\MarketIndicator;
use App\Services\AI\AiAdvisorService;
use App\Services\Market\MarketContextPersistenceService;
use App\Services\MCP\McpResult;
use App\Services\MCP\MCPService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\DecisionFusionService;
use App\Services\Trading\DecisionGuardrailService;
use App\Services\Trading\FinalSignalDTO;
use App\Services\Trading\MTFContextService;
use App\Services\Trading\MTFDecisionService;
use App\Services\Trading\PositionSizingService;
use App\Services\Trading\SignalActivationService;
use App\Services\Trading\TradeLevelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RunAiDecisionJob
 *
 * Queued job that executes the post-ingestion decision pipeline for each coin:
 * 1. MCP pre-filter per timeframe (unchanged MCP logic).
 * 2. Deterministic MTF context scoring.
 * 3. AI raw decision generation (AI always runs when MCP trigger passes).
 * 4. Decision fusion (MTF confidence modulation only).
 * 5. Guardrail safety validation.
 * 6. Trade levels + position sizing.
 * 7. Persist + notify with idempotency checks.
 */
class RunAiDecisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted before it is marked as failed.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int>
     */
    public array $backoff = [60, 120];

    /**
     * @param  array<string>  $coins  Leave empty to read from GeneralConfig at runtime.
     * @param  array<string>  $timeframes  Leave empty to read from GeneralConfig at runtime.
     */
    public function __construct(
        private readonly array $coins = [],
        private readonly array $timeframes = [],
        private readonly string $executionId = '',
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AiAdvisorService $advisorService,
        NotificationService $notificationService,
        MCPService $mcpService,
        DecisionGuardrailService $guardrailService,
        DecisionFusionService $decisionFusionService,
        MTFContextService $mtfContextService,
        TradeLevelService $tradeLevelService,
        PositionSizingService $positionSizingService,
        MTFDecisionService $mtfDecisionService,
        MarketContextPersistenceService $marketContextPersistenceService,
        SignalActivationService $signalActivationService,
    ): void {
        Log::info('[RunAiDecisionJob] Execution started', [
            'execution_id' => $this->executionId,
        ]);

        $coins = $this->resolveCoins();
        $timeframes = $this->resolveTimeframes();

        foreach ($coins as $coin) {
            try {
                $this->processCoin(
                    advisorService: $advisorService,
                    notificationService: $notificationService,
                    mcpService: $mcpService,
                    guardrailService: $guardrailService,
                    decisionFusionService: $decisionFusionService,
                    mtfContextService: $mtfContextService,
                    tradeLevelService: $tradeLevelService,
                    positionSizingService: $positionSizingService,
                    mtfDecisionService: $mtfDecisionService,
                    marketContextPersistenceService: $marketContextPersistenceService,
                    signalActivationService: $signalActivationService,
                    coin: $coin,
                    timeframes: $timeframes,
                );
            } catch (Throwable $e) {
                Log::error('[RunAiDecisionJob] Unexpected failure — skipping coin', [
                    'execution_id' => $this->executionId,
                    'coin' => $coin,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('[RunAiDecisionJob] Execution completed', [
            'execution_id' => $this->executionId,
        ]);
    }

    /**
     * Process one coin through MCP -> MTF -> optional AI -> guardrail -> persist flow.
     *
     * @param  array<string>  $timeframes
     */
    private function processCoin(
        AiAdvisorService $advisorService,
        NotificationService $notificationService,
        MCPService $mcpService,
        DecisionGuardrailService $guardrailService,
        DecisionFusionService $decisionFusionService,
        MTFContextService $mtfContextService,
        TradeLevelService $tradeLevelService,
        PositionSizingService $positionSizingService,
        MTFDecisionService $mtfDecisionService,
        MarketContextPersistenceService $marketContextPersistenceService,
        SignalActivationService $signalActivationService,
        string $coin,
        array $timeframes,
    ): void {
        /** @var array<string, McpResult|null> $mcpResults */
        $mcpResults = [];

        foreach ($timeframes as $timeframe) {
            $mcpResults[$timeframe] = $mcpService->evaluate($coin, $timeframe, $this->executionId);
        }

        $mtfResult = $mtfDecisionService->evaluate($coin, $mcpResults, $timeframes, $this->executionId);
        $mtfContext = $mtfContextService->build($mtfResult);
        $mtfContextDto = $mtfContextService->buildDto($mtfResult);
        $marketContextPersistenceService->persist($mtfContextDto, $coin);
        $timeframeSummary = $mtfDecisionService->buildTimeframeSummary($mtfResult->timeframeSignals);

        $triggerTimeframe = $mtfResult->roleTimeframes['trigger'];
        $triggerMcpResult = $mcpResults[$triggerTimeframe] ?? null;

        if ($triggerMcpResult === null) {
            Log::info('[RunAiDecisionJob] AI skipped because trigger MCP did not pass', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'trigger_timeframe' => $triggerTimeframe,
            ]);

            return;
        }

        $triggerIndicator = $this->fetchLatestIndicator($coin, $triggerTimeframe);

        if ($triggerIndicator === null) {
            Log::warning('[RunAiDecisionJob] Missing 5m indicator — skipping coin', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'trigger_timeframe' => $triggerTimeframe,
            ]);

            return;
        }

        $aiResult = $advisorService->adviseWithMtfContext(
            coin: $coin,
            timeframe: $triggerTimeframe,
            mcpResult: $triggerMcpResult,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            executionId: $this->executionId,
        );

        $aiDecision = $aiResult['decision'] ?? [
            'action' => 'HOLD',
            'confidence' => 0,
            'risk_level' => 'HIGH',
            'reason' => 'AI decision unavailable',
            'flags' => ['ai_unavailable'],
        ];

        $activation = $signalActivationService->adjustFromConfig(
            mtfScore: (float) ($mtfContext['mtf_score'] ?? 0.0),
            aiConfidence: (int) ($aiDecision['confidence'] ?? 0),
            flags: (array) ($mtfContext['flags'] ?? []),
            executionId: $this->executionId,
            coin: $coin,
        );

        $mode = (string) ($mtfContext['mode'] ?? 'trend_follow');
        $passesActivationThreshold = abs((float) $activation['adjusted_score']) >= (float) $activation['trigger_threshold']
            || ($mode === 'trend_follow' && abs((float) $activation['adjusted_score']) >= 0.5);

        if ($passesActivationThreshold) {
            Log::info('[SignalActivation] Triggered', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'mtf_score' => $activation['adjusted_score'],
                'mode' => $mode,
                'mcp_score' => $triggerMcpResult->score,
                'confidence' => $activation['adjusted_confidence'],
            ]);
        }

        $mtfContext['mtf_score'] = $activation['adjusted_score'];
        $mtfContext['flags'] = $this->normalizeFlags(array_merge(
            (array) ($mtfContext['flags'] ?? []),
            ["signal_activation_{$activation['mode']}"],
        ));
        $mtfContext['trigger_threshold'] = $activation['trigger_threshold'];
        $aiDecision['confidence'] = $activation['adjusted_confidence'];

        $fusedDecision = $decisionFusionService->fuse($aiDecision, $mtfContext);
        $decision = $fusedDecision;

        $rawResponse = $aiResult['raw_response'] ?? null;
        $modelUsed = $aiResult['model_used'] ?? 'ai-unavailable';

        $decision = $guardrailService->apply(
            $decision,
            (float) $triggerIndicator->rsi,
            (string) $triggerIndicator->trend,
        );

        $guardrailAccepted = in_array($decision['action'], ['BUY', 'SELL'], true);
        $guardrailApplied = $decision['action'] !== ($fusedDecision['final_action'] ?? $fusedDecision['action']);

        $priceChange24h = $this->resolvePriceChange24h($triggerIndicator);

        $decision = $tradeLevelService->appendTradeLevels(
            $decision,
            (float) $triggerIndicator->price,
            $priceChange24h,
            $guardrailAccepted,
        );

        $decision = $positionSizingService->calculate($decision);

        $finalSignal = new FinalSignalDTO(
            action: (string) $decision['action'],
            confidence: (int) $decision['confidence'],
            riskLevel: (string) $decision['risk_level'],
            entry: isset($decision['entry']) ? (float) $decision['entry'] : null,
            takeProfit: isset($decision['take_profit']) ? (float) $decision['take_profit'] : null,
            stopLoss: isset($decision['stop_loss']) ? (float) $decision['stop_loss'] : null,
            positionSize: isset($decision['position_size']) ? (float) $decision['position_size'] : null,
            flags: $this->normalizeFlags($decision['flags'] ?? []),
            mtfScore: (float) ($fusedDecision['mtf_score'] ?? $mtfResult->mtfScore),
            mode: $mtfResult->mode,
        );

        $isDuplicate = AiDecision::query()
            ->where('coin', $coin)
            ->where('timeframe', $triggerTimeframe)
            ->where('timestamp', $triggerIndicator->timestamp)
            ->exists();

        if ($isDuplicate) {
            Log::info('[RunAiDecisionJob] Duplicate decision skipped', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'timeframe' => $triggerTimeframe,
                'timestamp' => $triggerIndicator->timestamp?->toIso8601String(),
            ]);

            return;
        }

        $startedAt = microtime(true);

        $persistedDecision = AiDecision::create([
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $triggerTimeframe,
            'timestamp' => $triggerIndicator->timestamp,
            'input_data' => [
                'price' => $triggerIndicator->price,
                'price_change_24h' => $priceChange24h,
                'rsi' => $triggerIndicator->rsi,
                'ema9' => $triggerIndicator->ema9,
                'ema21' => $triggerIndicator->ema21,
                'trend' => $triggerIndicator->trend,
                'timeframe' => $triggerTimeframe,
                'trigger_mcp_score' => $triggerMcpResult->score,
                'trigger_mcp_candidate' => $triggerMcpResult->actionCandidate->value,
                'ai_action' => $fusedDecision['ai_action'] ?? null,
                'ai_confidence' => $fusedDecision['ai_confidence'] ?? null,
                'mtf_score' => $mtfResult->mtfScore,
                'mtf_score_adjusted' => $fusedDecision['mtf_score'] ?? null,
                'mtf_alignment' => $fusedDecision['mtf_alignment'] ?? null,
                'mtf_context_bias' => $fusedDecision['context_bias'] ?? null,
                'confidence_delta' => $fusedDecision['confidence_delta'] ?? 0,
                'confidence_adjusted' => $fusedDecision['confidence_adjusted'] ?? null,
                'preliminary_action' => $mtfResult->preliminaryAction,
                'base_confidence' => $mtfResult->baseConfidence,
                'mode' => $mtfResult->mode,
                'role_timeframes' => $mtfResult->roleTimeframes,
                'flags' => $finalSignal->flags,
                'timeframe_summary' => $timeframeSummary,
                'timeframe_signals' => $mtfResult->timeframeSignals,
                'guardrail_applied' => $guardrailApplied,
                'entry' => $finalSignal->entry,
                'take_profit' => $finalSignal->takeProfit,
                'stop_loss' => $finalSignal->stopLoss,
            ],
            'action' => $finalSignal->action,
            'confidence' => $finalSignal->confidence,
            'is_trade_candidate' => $guardrailAccepted && in_array($finalSignal->action, ['BUY', 'SELL'], true),
            'risk_level' => $finalSignal->riskLevel,
            'reason' => (string) ($decision['reason'] ?? 'mtf_final'),
            'price_at_decision' => $triggerIndicator->price,
            'position_size' => $finalSignal->positionSize,
            'risk_amount' => isset($decision['risk_amount']) ? (float) $decision['risk_amount'] : null,
            'raw_response' => $rawResponse,
            'model_used' => $modelUsed,
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        EvaluateAiDecisionJob::dispatch($persistedDecision->id)
            ->delay(now()->addMinutes(5));

        EvaluateAiDecisionJob::dispatch($persistedDecision->id)
            ->delay(now()->addMinutes(15));

        if ($guardrailAccepted && in_array($finalSignal->action, ['BUY', 'SELL'], true)) {
            $notificationService->sendTradeSignal([
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'timeframe' => $triggerTimeframe,
                'action' => $finalSignal->action,
                'confidence' => $finalSignal->confidence,
                'risk_level' => $finalSignal->riskLevel,
                'reason' => (string) ($decision['reason'] ?? 'mtf_final'),
                'entry' => $finalSignal->entry,
                'take_profit' => $finalSignal->takeProfit,
                'stop_loss' => $finalSignal->stopLoss,
                'position_size' => $finalSignal->positionSize,
                'flags' => $finalSignal->flags,
            ]);
        }

        Log::info('[RunAiDecisionJob] Decision persisted', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'ai_action' => $fusedDecision['ai_action'] ?? null,
            'ai_confidence' => $fusedDecision['ai_confidence'] ?? null,
            'confidence_delta' => $fusedDecision['confidence_delta'] ?? 0,
            'action' => $finalSignal->action,
            'confidence' => $finalSignal->confidence,
            'mtf_score' => $finalSignal->mtfScore,
            'mode' => $finalSignal->mode,
            'guardrail_applied' => $guardrailApplied,
            'flags' => $finalSignal->flags,
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
        ]);
    }

    /**
     * Resolve the list of coins to process.
     *
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
     * Resolve the list of timeframes to process.
     *
     * @return array<string>
     */
    private function resolveTimeframes(): array
    {
        return ! empty($this->timeframes)
            ? $this->timeframes
            : GeneralConfig::getTimeframes();
    }

    private function fetchLatestIndicator(string $coin, string $timeframe): ?MarketIndicator
    {
        return MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();
    }

    private function resolvePriceChange24h(object $indicator): ?float
    {
        if (! method_exists($indicator, 'getAttributes')) {
            return null;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $indicator->getAttributes();
        $priceChange24h = $attributes['price_change_24h'] ?? null;

        return is_numeric($priceChange24h)
            ? (float) $priceChange24h
            : null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeFlags(mixed $flags): array
    {
        if (! is_array($flags)) {
            return [];
        }

        $normalized = [];

        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                continue;
            }

            $trimmed = trim($flag);

            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }
}
