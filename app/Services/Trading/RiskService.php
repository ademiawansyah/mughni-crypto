<?php

namespace App\Services\Trading;

use App\Services\Trading\DTO\FinalDecisionDTO;

/**
 * RiskService
 *
 * Appends trade levels and position sizing to a decision payload.
 */
class RiskService
{
    public function __construct(
        private readonly TradeLevelService $tradeLevelService,
        private readonly PositionSizingService $positionSizingService,
    ) {}

    /**
     * Apply deterministic trade levels and sizing to a final decision DTO.
     */
    public function apply(FinalDecisionDTO $decision, float $entryPrice, ?float $priceChange24h, bool $isSignalConfirmed): FinalDecisionDTO
    {
        $base = [
            'action' => $decision->action,
            'confidence' => $decision->confidence,
            'risk_level' => $decision->riskLevel,
            'reason' => $decision->reason,
            'flags' => $decision->flags,
        ];

        $withLevels = $this->tradeLevelService->appendTradeLevels(
            decision: $base,
            entryPrice: $entryPrice,
            priceChange24h: $priceChange24h,
            isSignalConfirmed: $isSignalConfirmed,
        );

        $sized = $this->positionSizingService->calculate($withLevels);

        return new FinalDecisionDTO(
            action: (string) $sized['action'],
            confidence: (int) $sized['confidence'],
            riskLevel: (string) $sized['risk_level'],
            entry: isset($sized['entry']) ? (float) $sized['entry'] : null,
            takeProfit: isset($sized['take_profit']) ? (float) $sized['take_profit'] : null,
            stopLoss: isset($sized['stop_loss']) ? (float) $sized['stop_loss'] : null,
            positionSize: isset($sized['position_size']) ? (float) $sized['position_size'] : null,
            riskAmount: isset($sized['risk_amount']) ? (float) $sized['risk_amount'] : null,
            flags: is_array($sized['flags'] ?? null) ? $sized['flags'] : [],
            mtfScore: $decision->mtfScore,
            reason: (string) $sized['reason'],
        );
    }
}
