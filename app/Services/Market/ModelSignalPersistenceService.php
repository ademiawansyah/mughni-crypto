<?php

namespace App\Services\Market;

use App\Models\AiDecision;
use App\Models\MarketIndicator;
use App\Services\Market\Models\ModelSignalDTO;
use Illuminate\Support\Carbon;
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
     * Persist a model-level HOLD decision when no candidates pass deterministic filtering.
     *
     * This preserves execution traceability and keeps dashboard visibility explicit
     * for runs where models intentionally skip AI evaluation.
     */
    public function persistNoSignalDecision(
        string $model,
        array $marketRegime,
        string $executionId,
        string $timeframe = '1h',
    ): AiDecision {
        return AiDecision::create([
            'execution_id' => $executionId,
            'model' => $model,
            'coin' => 'market',
            'market_regime' => $marketRegime,
            'ai_decision' => [
                'action' => 'HOLD',
                'confidence' => 0,
                'reasoning' => 'No candidates passed deterministic pre-filter',
                'ai_enabled' => false,
                'agreement' => true,
            ],
            'timeframe' => $timeframe,
            'timestamp' => now(),
            'action' => 'HOLD',
            'confidence' => 0,
            'is_trade_candidate' => false,
            'risk_level' => 'LOW',
            'reason' => 'No candidates passed deterministic pre-filter',
            'price_at_decision' => 0.0,
            'input_data' => [
                'signal' => [
                    'model' => $model,
                    'coin' => 'market',
                    'action' => 'HOLD',
                    'total_score' => 0,
                    'component_scores' => [],
                    'primary_timeframe' => $timeframe,
                    'reasons' => ['no_candidates_passed'],
                ],
                'component_scores' => [],
                'market_context' => $marketRegime,
                'ai_enabled' => false,
                'ai_agreement' => true,
            ],
            'raw_response' => null,
        ]);
    }

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
        $snapshot = $this->getLatestIndicatorSnapshot($signal->coin, $signal->primaryTimeframe);
        $decisionTimestamp = $snapshot['timestamp'];

        if ($decisionTimestamp === null) {
            Log::warning('[ModelSignalPersistenceService] Missing indicator timestamp, falling back to now()', [
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'timeframe' => $signal->primaryTimeframe,
            ]);

            $decisionTimestamp = now();
        }

        // Check for duplicate: same model + coin + timeframe + market timestamp
        $isDuplicate = AiDecision::query()
            ->where('model', $signal->model)
            ->where('coin', $signal->coin)
            ->where('timeframe', $signal->primaryTimeframe)
            ->where('timestamp', $decisionTimestamp)
            ->exists();

        if ($isDuplicate) {
            Log::debug('[ModelSignalPersistenceService] Duplicate signal skipped', [
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'timeframe' => $signal->primaryTimeframe,
                'timestamp' => $decisionTimestamp->toIso8601String(),
            ]);

            return null;
        }

        // Determine final action and confidence based on AI decision
        $finalAction = $aiDecision['action'] ?? $signal->action;
        $finalConfidence = $aiDecision['confidence'] ?? $signal->score;
        $riskLevel = strtoupper((string) ($aiDecision['risk_level'] ?? 'MEDIUM'));

        if (! in_array($riskLevel, ['LOW', 'MEDIUM', 'HIGH'], true)) {
            $riskLevel = 'MEDIUM';
        }

        $priceAtDecision = is_numeric($snapshot['price']) ? (float) $snapshot['price'] : 0.0;

        if (! is_numeric($snapshot['price'])) {
            Log::warning('[ModelSignalPersistenceService] Missing indicator price, defaulting to 0', [
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'timeframe' => $signal->primaryTimeframe,
            ]);
        }

        try {
            $record = AiDecision::create([
                'execution_id' => $executionId,
                'model' => $signal->model,
                'coin' => $signal->coin,
                'market_regime' => $marketRegime,
                'ai_decision' => $aiDecision,
                'timeframe' => $signal->primaryTimeframe,
                'timestamp' => $decisionTimestamp,
                'action' => $finalAction,
                'confidence' => $finalConfidence,
                'is_trade_candidate' => in_array($finalAction, ['BUY', 'SELL'], true),
                'risk_level' => $riskLevel,
                'reason' => $aiDecision['reasoning'] ?? implode(', ', $signal->reasons),
                'price_at_decision' => $priceAtDecision,
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
     * @param  string  $timeframe  Signal timeframe key
     * @return array{price: float|null, timestamp: Carbon|null}
     */
    private function getLatestIndicatorSnapshot(string $coin, string $timeframe): array
    {
        $indicator = MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();

        return [
            'price' => $indicator?->price,
            'timestamp' => $indicator?->timestamp,
        ];
    }
}
