<?php

namespace App\Services\Trading;

use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\FinalDecisionDTO;
use App\Services\Trading\DTO\FusionMetadataDTO;
use App\Services\Trading\DTO\FusionOutcomeDTO;
use App\Services\Trading\DTO\MTFContextDTO;

/**
 * DecisionFusionService
 *
 * Combines AI raw decision with MTF context as a soft confidence modifier.
 * AI action remains primary unless a downstream guardrail blocks it.
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
                'flags' => [],
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
        $mtfAlignment = $this->resolveAiAlignment($aiAction, $contextBias);

        $confidenceDelta = $this->resolveConfidenceDelta($mtfScore, $mtfAlignment, $aiAction);
        $adjustedConfidence = max(0, min(100, $aiConfidence + $confidenceDelta));

        $flags = $this->normalizeFlags(array_merge(
            (array) ($aiDecision['flags'] ?? []),
            (array) ($mtfContext['flags'] ?? []),
            [
                'ai_primary_decision',
                'mtf_soft_context',
                "mtf_alignment_{$mtfAlignment}",
                "mtf_confidence_delta_{$confidenceDelta}",
            ],
        ));

        return [
            'action' => $aiAction,
            'confidence' => $adjustedConfidence,
            'risk_level' => $this->resolveRiskLevel($adjustedConfidence),
            'reason' => (string) $aiDecision['reason'],
            'flags' => $flags,
            'ai_action' => $aiAction,
            'ai_confidence' => $aiConfidence,
            'mtf_score' => $mtfScore,
            'mtf_alignment' => $mtfAlignment,
            'context_bias' => $contextBias,
            'confidence_delta' => $confidenceDelta,
            'confidence_adjusted' => $adjustedConfidence,
            'final_action' => $aiAction,
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

    private function resolveConfidenceDelta(float $mtfScore, string $alignment, string $aiAction): int
    {
        if ($aiAction === 'HOLD') {
            return 0;
        }

        $impact = (int) min(12, round(abs($mtfScore) * 3));

        if ($alignment === 'aligned') {
            return $impact;
        }

        if ($alignment === 'opposed') {
            return -$impact;
        }

        return 0;
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
