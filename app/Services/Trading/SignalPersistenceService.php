<?php

namespace App\Services\Trading;

use App\Jobs\EvaluateAiDecisionJob;
use App\Models\AiDecision;
use App\Models\MarketIndicator;
use App\Services\MCP\McpResult;
use App\Services\Notification\NotificationService;
use App\Services\Trading\DTO\AiAdviceDTO;
use App\Services\Trading\DTO\FinalDecisionDTO;
use App\Services\Trading\DTO\FusionMetadataDTO;
use Illuminate\Support\Facades\Log;

/**
 * SignalPersistenceService
 *
 * Persists final decisions with idempotency checks and triggers notifications.
 */
class SignalPersistenceService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Persist the final decision and dispatch notification when accepted.
     */
    public function persist(
        string $executionId,
        string $coin,
        string $triggerTimeframe,
        MarketIndicator $triggerIndicator,
        McpResult $triggerMcpResult,
        FinalDecisionDTO $finalDecision,
        FusionMetadataDTO $fusionMetadata,
        MTFResultDTO $mtfResult,
        string $timeframeSummary,
        ?AiAdviceDTO $aiAdvice,
    ): void {
        $isDuplicate = AiDecision::query()
            ->where('coin', $coin)
            ->where('timeframe', $triggerTimeframe)
            ->where('timestamp', $triggerIndicator->timestamp)
            ->exists();

        if ($isDuplicate) {
            Log::info('[SignalPersistenceService] Duplicate decision skipped', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $triggerTimeframe,
                'timestamp' => $triggerIndicator->timestamp?->toIso8601String(),
            ]);

            return;
        }

        $guardrailAccepted = in_array($finalDecision->action, ['BUY', 'SELL'], true);
        $guardrailApplied = $finalDecision->action !== $fusionMetadata->finalAction;

        $startedAt = microtime(true);

        $persistedDecision = AiDecision::create([
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $triggerTimeframe,
            'timestamp' => $triggerIndicator->timestamp,
            'input_data' => [
                'price' => $triggerIndicator->price,
                'price_change_24h' => $this->resolvePriceChange24h($triggerIndicator),
                'rsi' => $triggerIndicator->rsi,
                'ema9' => $triggerIndicator->ema9,
                'ema21' => $triggerIndicator->ema21,
                'trend' => $triggerIndicator->trend,
                'timeframe' => $triggerTimeframe,
                'trigger_mcp_score' => $triggerMcpResult->score,
                'trigger_mcp_candidate' => $triggerMcpResult->actionCandidate->value,
                'ai_action' => $fusionMetadata->aiAction,
                'ai_confidence' => $fusionMetadata->aiConfidence,
                'mtf_score' => $mtfResult->mtfScore,
                'mtf_alignment' => $fusionMetadata->mtfAlignment,
                'mtf_context_bias' => $fusionMetadata->contextBias,
                'confidence_delta' => $fusionMetadata->confidenceDelta,
                'confidence_adjusted' => $fusionMetadata->confidenceAdjusted,
                'preliminary_action' => $mtfResult->preliminaryAction,
                'base_confidence' => $mtfResult->baseConfidence,
                'mode' => $mtfResult->mode,
                'role_timeframes' => $mtfResult->roleTimeframes,
                'flags' => $finalDecision->flags,
                'timeframe_summary' => $timeframeSummary,
                'timeframe_signals' => $mtfResult->timeframeSignals,
                'guardrail_applied' => $guardrailApplied,
                'entry' => $finalDecision->entry,
                'take_profit' => $finalDecision->takeProfit,
                'stop_loss' => $finalDecision->stopLoss,
            ],
            'action' => $finalDecision->action,
            'confidence' => $finalDecision->confidence,
            'is_trade_candidate' => $guardrailAccepted && in_array($finalDecision->action, ['BUY', 'SELL'], true),
            'risk_level' => $finalDecision->riskLevel,
            'reason' => $finalDecision->reason,
            'price_at_decision' => $triggerIndicator->price,
            'position_size' => $finalDecision->positionSize,
            'risk_amount' => $finalDecision->riskAmount,
            'raw_response' => $aiAdvice?->rawResponse,
            'model_used' => $aiAdvice?->modelUsed ?? 'ai-unavailable',
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        EvaluateAiDecisionJob::dispatch($persistedDecision->id)
            ->delay(now()->addMinutes(5));

        EvaluateAiDecisionJob::dispatch($persistedDecision->id)
            ->delay(now()->addMinutes(15));

        if ($guardrailAccepted && in_array($finalDecision->action, ['BUY', 'SELL'], true)) {
            $this->notificationService->sendTradeSignal([
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $triggerTimeframe,
                'action' => $finalDecision->action,
                'confidence' => $finalDecision->confidence,
                'risk_level' => $finalDecision->riskLevel,
                'reason' => $finalDecision->reason,
                'entry' => $finalDecision->entry,
                'take_profit' => $finalDecision->takeProfit,
                'stop_loss' => $finalDecision->stopLoss,
                'position_size' => $finalDecision->positionSize,
                'flags' => $finalDecision->flags,
            ]);
        }

        Log::info('[SignalPersistenceService] Decision persisted', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'action' => $finalDecision->action,
            'confidence' => $finalDecision->confidence,
            'mtf_score' => $finalDecision->mtfScore,
            'flags' => $finalDecision->flags,
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
        ]);
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
