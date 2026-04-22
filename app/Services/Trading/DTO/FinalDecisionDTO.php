<?php

namespace App\Services\Trading\DTO;

/**
 * FinalDecisionDTO
 *
 * Canonical decision payload after fusion, guardrails, and risk sizing.
 */
readonly class FinalDecisionDTO
{
    /**
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public string $action,
        public int $confidence,
        public string $riskLevel,
        public ?float $entry,
        public ?float $takeProfit,
        public ?float $stopLoss,
        public ?float $positionSize,
        public ?float $riskAmount,
        public array $flags,
        public float $mtfScore,
        public string $reason,
    ) {}

    /**
     * @return array{action: string, confidence: int, risk_level: string, entry: float|null, take_profit: float|null, stop_loss: float|null, position_size: float|null, flags: array<int, string>, mtf_score: float, reason: string}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'confidence' => $this->confidence,
            'risk_level' => $this->riskLevel,
            'entry' => $this->entry,
            'take_profit' => $this->takeProfit,
            'stop_loss' => $this->stopLoss,
            'position_size' => $this->positionSize,
            'risk_amount' => $this->riskAmount,
            'flags' => $this->flags,
            'mtf_score' => $this->mtfScore,
            'reason' => $this->reason,
        ];
    }
}
