<?php

namespace App\Services\Trading;

use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\FinalDecisionDTO;
use App\Services\Trading\DTO\FusionMetadataDTO;
use App\Services\Trading\DTO\FusionOutcomeDTO;
use App\Services\Trading\DTO\MTFContextDTO;
use Illuminate\Support\Facades\Log;

/**
 * DecisionFusionService
 *
 * Combines AI recommendation with MTF context into a single final decision.
 *
 * Fusion tiers (based on abs(mtf_score)):
 *   ≥ 2.5  — Strong MTF: MTF direction overrides AI, confidence floored at 70.
 *   1.0–2.5  — Medium MTF: AI action primary; ±confidence adjusted by MTF alignment.
 *   < 1.0  — Weak MTF: AI action primary; confidence reduced by 10.
 *
 * Decision conflicts (AI action ≠ MTF direction) are always flagged explicitly.
 */
class DecisionFusionService
{
    /**
     * Fuse AI decision and MTF context and return decision + metadata DTOs.
     */
    public function fuseOutcomeDto(AiDecisionDTO $aiDecision, MTFContextDTO $mtfContext): FusionOutcomeDTO
    {
        $fused = $this->fuse(
            aiDecision: [
                'action' => $aiDecision->action,
                'confidence' => $aiDecision->confidence,
                'risk_level' => 'HIGH',
                'reason' => $aiDecision->reason,
                'flags' => $aiDecision->validationFlags,
            ],
            mtfContext: [
                'mtf_score' => $mtfContext->mtfScore,
                'alignment' => $mtfContext->alignment,
                'context_bias' => $mtfContext->bias,
                'mode' => $mtfContext->mode,
                'base_confidence' => 50,
                'flags' => $mtfContext->flags,
                'trigger_threshold' => $this->resolveTriggerThresholdFromFlags($mtfContext->flags),
            ],
        );

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
            ),
            metadata: new FusionMetadataDTO(
                aiAction: (string) $fused['ai_action'],
                aiConfidence: (int) $fused['ai_confidence'],
                mtfScore: (float) $fused['mtf_score'],
                mtfAlignment: (string) $fused['mtf_alignment'],
                contextBias: (string) $fused['context_bias'],
                confidenceDelta: (int) $fused['confidence_delta'],
                confidenceAdjusted: (int) $fused['confidence_adjusted'],
                finalAction: (string) $fused['final_action'],
            ),
        );
    }

    /**
     * Fuse AI decision and MTF context and return DTO output.
     */
    public function fuseDto(AiDecisionDTO $aiDecision, MTFContextDTO $mtfContext): FinalDecisionDTO
    {
        return $this->fuseOutcomeDto($aiDecision, $mtfContext)->decision;
    }

    /**
     * Merge AI decision and MTF context into a fused decision payload.
     *
     * MTF score drives which tier applies. AI action is preserved in medium/weak
     * tiers; MTF direction dominates in the strong tier.
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string, flags?: array<int, string>}  $aiDecision
     * @param  array{mtf_score: float, alignment: string, context_bias: string, mode: string, base_confidence: int, flags: array<int, string>, trigger_threshold?: float}  $mtfContext
     * @return array{
     *   action: string,
     *   confidence: int,
     *   risk_level: string,
     *   reason: string,
     *   flags: array<int, string>,
     *   ai_action: string,
     *   ai_confidence: int,
     *   mtf_score: float,
     *   mtf_alignment: string,
     *   context_bias: string,
     *   confidence_delta: int,
     *   confidence_adjusted: int,
     *   final_action: string
     * }
     */
    public function fuse(array $aiDecision, array $mtfContext): array
    {
        $aiAction = strtoupper((string) $aiDecision['action']);
        $aiConfidence = max(0, min(100, (int) $aiDecision['confidence']));

        $mtfScore = (float) $mtfContext['mtf_score'];
        $mtfAlignment = strtolower((string) ($mtfContext['alignment'] ?? 'mixed'));
        $contextBias = (string) $mtfContext['context_bias'];
        $mode = strtolower((string) ($mtfContext['mode'] ?? 'trend_follow'));
        $triggerThreshold = (float) ($mtfContext['trigger_threshold'] ?? 1.0);
        $absScore = abs($mtfScore);

        // Derive MTF direction from context bias (bullish → BUY, bearish → SELL)
        $mtfDirection = match ($contextBias) {
            'bullish' => 'BUY',
            'bearish' => 'SELL',
            default => 'HOLD',
        };

        $flags = $this->normalizeFlags(array_merge(
            (array) ($aiDecision['flags'] ?? []),
            (array) ($mtfContext['flags'] ?? []),
        ));

        // Conflict detection — always explicit regardless of fusion tier
        if ($aiAction !== 'HOLD' && $mtfDirection !== 'HOLD' && $aiAction !== $mtfDirection) {
            $flags[] = 'decision_conflict';
        }

        $finalAction = $aiAction;
        $finalConfidence = $aiConfidence;
        $confidenceDelta = 0;

        if ($absScore >= 2.5) {
            // Tier 1: Strong MTF — MTF direction is authoritative
            $finalAction = $mtfDirection !== 'HOLD' ? $mtfDirection : $aiAction;
            $finalConfidence = max($aiConfidence, 70);
            $confidenceDelta = $finalConfidence - $aiConfidence;
            $flags[] = 'mtf_dominant';
        } elseif ($absScore >= $triggerThreshold) {
            // Tier 2: Medium MTF — AI action primary, alignment-based adjustment
            $finalAction = $aiAction;

            if ($aiAction !== 'HOLD' && $mtfAlignment === 'aligned') {
                $confidenceDelta = 10;
            } elseif ($aiAction !== 'HOLD' && $mtfAlignment === 'conflict') {
                $confidenceDelta = -10;
            }

            $finalConfidence = max(0, min(100, $aiConfidence + $confidenceDelta));
            $flags[] = "mtf_alignment_{$mtfAlignment}";
        } else {
            // Tier 3: Weak MTF — AI action with reduced confidence
            $finalAction = $aiAction;
            $confidenceDelta = -10;
            $finalConfidence = max(0, min(100, $aiConfidence + $confidenceDelta));
            $flags[] = 'mtf_weak';
        }

        if ($mode === 'trend_follow' && $absScore >= 0.5 && $finalAction === 'HOLD' && $mtfDirection !== 'HOLD') {
            $finalAction = $mtfDirection;
            $finalConfidence = max($finalConfidence, 45);
            $confidenceDelta = $finalConfidence - $aiConfidence;
            $flags[] = 'trend_follow_enable';
        }

        $flags[] = "mtf_confidence_delta_{$confidenceDelta}";
        $flags = $this->normalizeFlags($flags);

        $resolvedFinalAlignment = $this->resolveAiAlignment($finalAction, $contextBias);

        Log::info('[DecisionFusionService] Decision computed', [
            'mtf_score' => $mtfScore,
            'mtf_action' => $mtfDirection,
            'ai_action' => $aiAction,
            'final_action' => $finalAction,
            'confidence_adjustment' => $confidenceDelta,
            'flags' => $flags,
        ]);

        return [
            'action' => $finalAction,
            'confidence' => $finalConfidence,
            'risk_level' => $this->resolveRiskLevel($finalConfidence),
            'reason' => (string) $aiDecision['reason'],
            'flags' => $flags,
            'ai_action' => $aiAction,
            'ai_confidence' => $aiConfidence,
            'mtf_score' => $mtfScore,
            'mtf_alignment' => $resolvedFinalAlignment,
            'context_bias' => $contextBias,
            'confidence_delta' => $confidenceDelta,
            'confidence_adjusted' => $finalConfidence,
            'final_action' => $finalAction,
        ];
    }

    /**
     * @param  array<int, string>  $flags
     */
    private function resolveTriggerThresholdFromFlags(array $flags): float
    {
        if (in_array('signal_activation_conservative', $flags, true)) {
            return 1.5;
        }

        if (in_array('signal_activation_aggressive', $flags, true)) {
            return 0.8;
        }

        return 0.8;
    }

    private function resolveAiAlignment(string $action, string $contextBias): string
    {
        if ($action === 'HOLD' || $contextBias === 'neutral') {
            return 'neutral';
        }

        if (($action === 'BUY' && $contextBias === 'bullish') || ($action === 'SELL' && $contextBias === 'bearish')) {
            return 'aligned';
        }

        return 'opposed';
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
