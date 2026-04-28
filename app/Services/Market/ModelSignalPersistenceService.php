<?php

namespace App\Services\Market;

use App\Models\AiDecision;
use App\Models\MarketIndicator;
use App\Services\Market\Models\ModelSignalDTO;
use Illuminate\Support\Facades\Log;

/**
 * ModelSignalPersistenceService
 *
 * Persists per-model trading signals to the ai_decisions table.
 *
 * Each model (Counter-Trend, Pre-Pump, Momentum) generates independent signals
 * that are stored with:
 * - model: Identifies which model generated the signal
 * - market_regime: Global market context at signal generation time
 * - ai_decision: Optional AI layer refinement (if enabled for model)
 *
 * This service prevents duplicate signals (by coin + model + timestamp)
 * and logs all persistence operations for traceability.
 */
class ModelSignalPersistenceService
{
    /**
     * Persist a per-model signal to the database.
     *
     * Checks for duplicates before persisting to prevent re-notifying
     * identical signals within the same execution window.
     *
     * @param  ModelSignalDTO  $signal  The signal from a trading model
     * @param  array  $marketRegime  Global market context
     * @param  array  $aiDecision  Optional AI refinement: {action, confidence, reasoning, agreement, ai_enabled, ai_response}
     * @param  string  $executionId  Pipeline execution ID for traceability
     * @return AiDecision|null The persisted record, or null if duplicate
     */
    public function persist(
        ModelSignalDTO $signal,
        array $marketRegime,
        array $aiDecision,
        string $executionId = '',
    ): ?AiDecision {
        // Check for duplicate: same model + coin + timestamp
        $isDuplicate = AiDecision::query()
            ->where('model', $signal->model)
            ->where('coin', $signal->coin)
            ->where('timestamp', now())
            ->exists();

        if ($isDuplicate) {
            Log::debug('[ModelSignalPersistenceService] Duplicate signal skipped', [
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'timestamp' => now()->toIso8601String(),
            ]);

            return null;
        }

        // Determine final action and confidence based on AI decision
        $finalAction = $aiDecision['action'] ?? $signal->action;
        $finalConfidence = $aiDecision['confidence'] ?? $signal->score;

        try {
            $record = AiDecision::create([
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'market_regime' => $marketRegime,
                'ai_decision' => $aiDecision,
                'timeframe' => $signal->primaryTimeframe,
                'timestamp' => now(),
                'action' => $finalAction,
                'confidence' => $finalConfidence,
                'reason' => $aiDecision['reasoning'] ?? implode(', ', $signal->reasons),
                'price_at_decision' => $this->getCurrentPrice($signal->coin),
                'input_data' => [
                    'signal' => $signal->toArray(),
                    'component_scores' => $signal->componentScores,
                    'market_context' => $signal->context,
                    'ai_enabled' => $aiDecision['ai_enabled'] ?? false,
                    'ai_agreement' => $aiDecision['agreement'] ?? true,
                ],
                'raw_response' => $aiDecision['ai_response'],
            ]);

            Log::info('[ModelSignalPersistenceService] Signal persisted', [
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'action' => $finalAction,
                'confidence' => $finalConfidence,
                'ai_enabled' => $aiDecision['ai_enabled'] ?? false,
            ]);

            return $record;
        } catch (\Throwable $e) {
            Log::error('[ModelSignalPersistenceService] Failed to persist signal', [
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the current price for a coin from the latest market indicator.
     *
     * @param  string  $coin  CoinGecko coin ID
     */
    private function getCurrentPrice(string $coin): ?float
    {
        $indicator = MarketIndicator::query()
            ->where('coin', $coin)
            ->orderByDesc('timestamp')
            ->first();

        return $indicator?->price;
    }
}
