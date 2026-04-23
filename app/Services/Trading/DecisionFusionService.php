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
 *   ≥ 3.0  — Strong MTF: MTF direction overrides AI, confidence floored at 75.
 *   1.5–3  — Medium MTF: AI action primary; ±confidence adjusted by alignment.
 *   < 1.5  — Weak MTF: AI action primary; confidence reduced by 20 (low conviction).
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
                'mode' => 'trend_follow',
                'base_confidence' => 50,
                'flags' => $mtfContext->flags,
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
     * @param  array{mtf_score: float, alignment: string, context_bias: string, mode: string, base_confidence: int, flags: array<int, string>}  $mtfContext
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
        $contextBias = (string) $mtfContext['context_bias'];
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

        if ($absScore >= 3.0) {
            // Tier 1: Strong MTF — MTF direction is authoritative
            $finalAction = $mtfDirection !== 'HOLD' ? $mtfDirection : $aiAction;
            $finalConfidence = max($aiConfidence, 75);
            $confidenceDelta = $finalConfidence - $aiConfidence;
            $flags[] = 'mtf_strong_dominant';
        } elseif ($absScore >= 1.5) {
            // Tier 2: Medium MTF — AI action primary, alignment-based adjustment
            $finalAction = $aiAction;
            $alignment = $this->resolveAiAlignment($aiAction, $contextBias);

            if ($alignment === 'aligned') {
                $confidenceDelta = 10;
            } elseif ($alignment === 'opposed') {
                $confidenceDelta = -15;
            }

            $finalConfidence = max(0, min(100, $aiConfidence + $confidenceDelta));
            $flags[] = "mtf_alignment_{$alignment}";
        } else {
            // Tier 3: Weak MTF — AI action with reduced confidence
            $finalAction = $aiAction;
            $confidenceDelta = -20;
            $finalConfidence = max(0, min(100, $aiConfidence + $confidenceDelta));
            $flags[] = 'mtf_weak_context';
        }

        $flags[] = "mtf_confidence_delta_{$confidenceDelta}";
        $flags = $this->normalizeFlags($flags);

        $mtfAlignment = $this->resolveAiAlignment($finalAction, $contextBias);

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
            'mtf_alignment' => $mtfAlignment,
            'context_bias' => $contextBias,
            'confidence_delta' => $confidenceDelta,
            'confidence_adjusted' => $finalConfidence,
            'final_action' => $finalAction,
        ];
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
