<?php

namespace App\Services\Trading;

use App\Services\MCP\McpResult;
use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\FinalDecisionDTO;
use App\Services\Trading\DTO\FusionMetadataDTO;
use App\Services\Trading\DTO\FusionOutcomeDTO;
use App\Services\Trading\DTO\MTFContextDTO;
use Illuminate\Support\Facades\Log;

/**
 * DecisionFusionService
 *
 * Signal-first fusion algorithm that combines MCP signals, MTF context, and optional AI refinement
 * into a final decision. MCP signal existence drives the pipeline; AI refines confidence only.
 *
 * Algorithm (6 steps):
 *   1. Detect signal existence: any(mcpResults where score >= 1) → if false, return HOLD
 *   2. Select best trigger: sort by score DESC, timeframe DESC → first qualifying result
 *   3. Base decision: final_action = trigger->actionCandidate, base_confidence = 55
 *   4. MTF conflict check: if mtf_direction != trigger_direction AND abs(mtf_score) >= 1.5 → confidence -= 15
 *   5. AI refinement (if available):
 *      - IF ai_action == HOLD AND trigger_action != HOLD → discard AI, use base_confidence
 *      - ELSE IF ai_action == final_action → confidence = max(base_confidence, ai_confidence)
 *      - ELSE → use base_confidence
 *   6. Guardrail: if confidence < 45 → HOLD
 */
class DecisionFusionService
{
    /**
     * Fuse MCP results, MTF context, and optional AI decision into final decision + metadata.
     *
     * @param  array<string, McpResult|null>  $mcpResults  Per-timeframe MCP evaluation results
     * @param  MTFContextDTO  $mtfContext  Multi-timeframe aggregated context
     * @param  AiDecisionDTO|null  $aiDecision  Optional AI recommendation (null when AI unavailable)
     * @return FusionOutcomeDTO Decision + metadata DTOs
     */
    public function fuseOutcomeDto(array $mcpResults, MTFContextDTO $mtfContext, ?AiDecisionDTO $aiDecision): FusionOutcomeDTO
    {
        $fused = $this->fuse($mcpResults, $mtfContext, $aiDecision);

        return new FusionOutcomeDTO(
            decision: new FinalDecisionDTO(
                action: (string) $fused['action'],
                confidence: (int) $fused['confidence'],
                riskLevel: (string) $fused['risk_level'],
                entry: null,
                takeProfit: null,
                stopLoss: null,
                positionSize: null,
                riskAmount: null,
                flags: is_array($fused['flags'] ?? null) ? $fused['flags'] : [],
                mtfScore: (float) $fused['mtf_score'],
                reason: (string) $fused['reason'],
                triggerTimeframe: (string) ($fused['trigger_timeframe'] ?? 'unknown'),
                triggerScore: is_int($fused['trigger_score'] ?? null) ? $fused['trigger_score'] : null,
            ),
            metadata: new FusionMetadataDTO(
                aiAction: (string) ($fused['ai_action'] ?? 'none'),
                aiConfidence: (int) ($fused['ai_confidence'] ?? 0),
                mtfScore: (float) $fused['mtf_score'],
                mtfAlignment: (string) ($fused['mtf_alignment'] ?? 'unknown'),
                contextBias: (string) $fused['context_bias'],
                confidenceDelta: (int) ($fused['confidence_delta'] ?? 0),
                confidenceAdjusted: (int) $fused['confidence'],
                finalAction: (string) $fused['action'],
                aiAgreement: isset($fused['ai_agreement']) ? (bool) $fused['ai_agreement'] : null,
            ),
        );
    }

    /**
     * Fuse MCP results, MTF context, and optional AI decision using signal-first algorithm.
     *
     * @param  array<string, McpResult|null>  $mcpResults  Per-timeframe MCP results
     * @param  MTFContextDTO  $mtfContext  MTF context
     * @param  AiDecisionDTO|null  $aiDecision  Optional AI decision (null when unavailable)
     * @return array{
     *   action: string,
     *   confidence: int,
     *   risk_level: string,
     *   reason: string,
     *   flags: array<int, string>,
     *   ai_action: string|null,
     *   ai_confidence: int|null,
     *   mtf_score: float,
     *   mtf_alignment: string,
     *   context_bias: string,
     *   confidence_delta: int,
     *   trigger_timeframe: string|null
     * }
     */
    public function fuse(array $mcpResults, MTFContextDTO $mtfContext, ?AiDecisionDTO $aiDecision): array
    {
        $flags = array_values(array_unique($mtfContext->flags));

        // --- STEP 1: Detect signal existence ---
        $signalExists = false;
        foreach ($mcpResults as $mcpResult) {
            if ($mcpResult !== null && $mcpResult->score >= 1) {
                $signalExists = true;
                break;
            }
        }

        if (! $signalExists) {
            Log::info('[DecisionFusionService] No signal detected (all MCP scores < 1)', [
                'mtf_score' => $mtfContext->mtfScore,
            ]);

            return $this->buildHoldDecision(
                reason: 'No signal detected',
                mtfScore: $mtfContext->mtfScore,
                contextBias: $mtfContext->bias,
                flags: $flags,
            );
        }

        // --- STEP 2: Find best trigger (highest score, highest timeframe when equal) ---
        $triggerMcpResult = $this->selectBestTrigger($mcpResults);
        $triggerTimeframe = $triggerMcpResult?->timeframe;

        if ($triggerMcpResult === null) {
            return $this->buildHoldDecision(
                reason: 'No valid trigger timeframe',
                mtfScore: $mtfContext->mtfScore,
                contextBias: $mtfContext->bias,
                flags: $flags,
            );
        }

        // --- STEP 3: Base decision from trigger ---
        $finalAction = $triggerMcpResult->actionCandidate->value;
        $baseConfidence = 55;
        $flags[] = "trigger_{$triggerTimeframe}";

        // --- STEP 4: MTF conflict check ---
        $mtfDirection = $this->resolveMtfDirection($mtfContext->bias);
        $confidenceDelta = 0;

        if ($mtfDirection !== 'HOLD' && $mtfDirection !== $finalAction && abs($mtfContext->mtfScore) >= 1.5) {
            $confidenceDelta = -15;
            $flags[] = 'mtf_conflict_strong';
            Log::info('[DecisionFusionService] MTF strong conflict detected', [
                'mtf_direction' => $mtfDirection,
                'trigger_action' => $finalAction,
                'mtf_score' => $mtfContext->mtfScore,
            ]);
        }

        $currentConfidence = $baseConfidence + $confidenceDelta;

        // --- STEP 5: AI refinement (if available) ---
        $aiAction = null;
        $aiConfidence = null;
        $aiAgreement = null; // null = AI not used
        $aiReasonForRejection = 'AI unavailable';

        if ($aiDecision !== null && $aiDecision->action !== null) {
            $aiAction = $aiDecision->action;
            $aiConfidence = $aiDecision->confidence ?? 0;

            // If AI says HOLD but trigger says BUY/SELL, discard AI confidence
            if ($aiAction === 'HOLD' && $finalAction !== 'HOLD') {
                $aiAgreement = false;
                $aiReasonForRejection = 'AI_says_HOLD_but_trigger_says_'.$finalAction;
                // Keep currentConfidence as-is (base_confidence or adjusted by MTF)
                Log::info('[DecisionFusionService] AI says HOLD, discarding confidence', [
                    'trigger_action' => $finalAction,
                    'current_confidence' => $currentConfidence,
                ]);
            } // If AI agrees with trigger action, use max(base, ai_confidence)
            elseif ($aiAction === $finalAction) {
                $aiAgreement = true;
                $newConfidence = max($baseConfidence, $aiConfidence);
                $confidenceDelta = $newConfidence - $baseConfidence;
                $currentConfidence = $newConfidence;
                $flags[] = 'ai_aligned';
                Log::info('[DecisionFusionService] AI aligned with trigger', [
                    'trigger_action' => $finalAction,
                    'ai_confidence' => $aiConfidence,
                    'adjusted_confidence' => $currentConfidence,
                ]);
            } // AI disagrees: discard AI confidence, keep base
            else {
                $aiAgreement = false;
                $aiReasonForRejection = 'AI_action_'.$aiAction.'_conflicts_with_trigger_'.$finalAction;
                // Keep currentConfidence as-is
                $flags[] = 'ai_conflict';
                Log::info('[DecisionFusionService] AI conflicts with trigger, discarding', [
                    'trigger_action' => $finalAction,
                    'ai_action' => $aiAction,
                ]);
            }
        }

        // --- STEP 6: Guardrail (confidence < 45 → HOLD) ---
        $currentConfidence = max(0, min(100, $currentConfidence));

        if ($currentConfidence < 45) {
            Log::info('[DecisionFusionService] Guardrail: confidence too low', [
                'confidence' => $currentConfidence,
                'reason' => 'guardrail_low_confidence',
            ]);

            // Guardrail converts action to HOLD and resets confidence to 0 (no trade signal)
            return [
                'action' => 'HOLD',
                'confidence' => 0,
                'risk_level' => 'HIGH',
                'reason' => 'Guardrail: confidence below 45',
                'flags' => $this->normalizeFlags(array_merge($flags, ['guardrail_low_confidence'])),
                'ai_action' => $aiDecision?->action,
                'ai_confidence' => $aiDecision?->confidence,
                'mtf_score' => $mtfContext->mtfScore,
                'mtf_alignment' => $this->resolveMtfAlignment('HOLD', $finalAction),
                'context_bias' => $mtfContext->bias,
                'confidence_delta' => 0 - 55,
                'trigger_timeframe' => $triggerTimeframe,
            ];
        }

        $flags = $this->normalizeFlags($flags);

        Log::info('[DecisionFusionService] Decision computed (signal-first)', [
            'trigger_timeframe' => $triggerTimeframe,
            'trigger_score' => $triggerMcpResult->score,
            'final_action' => $finalAction,
            'base_confidence' => $baseConfidence,
            'confidence_delta' => $confidenceDelta,
            'current_confidence' => $currentConfidence,
            'ai_action' => $aiAction,
            'ai_agreement' => $aiAgreement,
            'mtf_direction' => $mtfDirection,
            'mtf_score' => $mtfContext->mtfScore,
            'mtf_raw_score' => $mtfContext->mtfRawScore,
            'flags' => $flags,
        ]);

        return [
            'action' => $finalAction,
            'confidence' => $currentConfidence,
            'risk_level' => $this->resolveRiskLevel($currentConfidence),
            'reason' => 'Signal-first fusion',
            'flags' => $flags,
            'ai_action' => $aiAction,
            'ai_confidence' => $aiConfidence,
            'ai_agreement' => $aiAgreement,
            'mtf_score' => $mtfContext->mtfScore,
            'mtf_alignment' => $this->resolveMtfAlignment($mtfDirection, $finalAction),
            'context_bias' => $mtfContext->bias,
            'confidence_delta' => $confidenceDelta,
            'trigger_timeframe' => $triggerTimeframe,
            'trigger_score' => $triggerMcpResult->score,
        ];
    }

    /**
     * Select best trigger MCP result by score DESC, then timeframe DESC (higher timeframes preferred).
     * Only considers results with score >= 1 (valid signals).
     *
     * @param  array<string, McpResult|null>  $mcpResults  MCP results keyed by timeframe
     * @return McpResult|null Best trigger result, or null if no valid signal
     */
    private function selectBestTrigger(array $mcpResults): ?McpResult
    {
        $validResults = array_filter(
            $mcpResults,
            fn (?McpResult $result) => $result !== null && $result->score >= 1,
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
     * @return array<string>
     */
    private function sortTimeframesAscending(array $timeframes): array
    {
        $sorted = array_values(array_unique($timeframes));

        usort($sorted, fn (string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return $sorted;
    }

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

    private function resolveMtfDirection(string $contextBias): string
    {
        return match ($contextBias) {
            'bullish' => 'BUY',
            'bearish' => 'SELL',
            default => 'HOLD',
        };
    }

    private function resolveMtfAlignment(string $mtfDirection, string $triggerAction): string
    {
        if ($mtfDirection === $triggerAction) {
            return 'aligned';
        }

        if ($mtfDirection === 'HOLD' || $triggerAction === 'HOLD') {
            return 'neutral';
        }

        return 'conflict';
    }

    private function resolveRiskLevel(int $confidence): string
    {
        if ($confidence >= 70) {
            return 'LOW';
        }

        if ($confidence >= 40) {
            return 'MEDIUM';
        }

        return 'HIGH';
    }

    /**
     * @param  array<int, string>  $flags
     * @return array{
     *   action: string,
     *   confidence: int,
     *   risk_level: string,
     *   reason: string,
     *   flags: array<int, string>,
     *   ai_action: null,
     *   ai_confidence: null,
     *   mtf_score: float,
     *   mtf_alignment: string,
     *   context_bias: string,
     *   confidence_delta: int,
     *   trigger_timeframe: null
     * }
     */
    private function buildHoldDecision(string $reason, float $mtfScore, string $contextBias, array $flags): array
    {
        return [
            'action' => 'HOLD',
            'confidence' => 0,
            'risk_level' => 'HIGH',
            'reason' => $reason,
            'flags' => $this->normalizeFlags($flags),
            'ai_action' => null,
            'ai_confidence' => null,
            'mtf_score' => $mtfScore,
            'mtf_alignment' => 'neutral',
            'context_bias' => $contextBias,
            'confidence_delta' => 0,
            'trigger_timeframe' => null,
        ];
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

    /**
     * Legacy method for backward compatibility.
     *
     * @deprecated Use fuseOutcomeDto() with new signature instead.
     */
    public function fuseDto(AiDecisionDTO $aiDecision, MTFContextDTO $mtfContext): FinalDecisionDTO
    {
        // Legacy compatibility: convert old signature to new one
        $mcpResults = [];
        $outcome = $this->fuseOutcomeDto($mcpResults, $mtfContext, $aiDecision);

        return $outcome->decision;
    }
}
