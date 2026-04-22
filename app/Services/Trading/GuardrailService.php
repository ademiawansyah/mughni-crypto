<?php

namespace App\Services\Trading;

use App\Services\Trading\DTO\FinalDecisionDTO;
use App\Services\Trading\DTO\IndicatorDTO;

/**
 * GuardrailService
 *
 * Applies hard safety checks on fused decisions and returns final decision state.
 */
class GuardrailService
{
    public function __construct(
        private readonly DecisionGuardrailService $decisionGuardrailService,
    ) {}

    /**
     * Apply guardrail checks to a fused decision using entry indicators.
     */
    public function apply(FinalDecisionDTO $decision, IndicatorDTO $entryIndicator): FinalDecisionDTO
    {
        $result = $this->decisionGuardrailService->apply(
            decision: [
                'action' => $decision->action,
                'confidence' => $decision->confidence,
                'risk_level' => $decision->riskLevel,
                'reason' => $decision->reason,
                'flags' => $decision->flags,
            ],
            rsi: $entryIndicator->rsi,
            trend: $entryIndicator->trend,
        );

        return new FinalDecisionDTO(
            action: (string) $result['action'],
            confidence: (int) $result['confidence'],
            riskLevel: (string) $result['risk_level'],
            entry: $decision->entry,
            takeProfit: $decision->takeProfit,
            stopLoss: $decision->stopLoss,
            positionSize: $decision->positionSize,
            riskAmount: $decision->riskAmount,
            flags: is_array($result['flags'] ?? null) ? $result['flags'] : [],
            mtfScore: $decision->mtfScore,
            reason: (string) $result['reason'],
        );
    }
}
