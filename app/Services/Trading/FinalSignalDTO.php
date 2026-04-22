<?php

namespace App\Services\Trading;

/**
 * FinalSignalDTO
 *
 * Immutable final decision payload after MTF, optional AI refinement, and guardrails.
 */
readonly class FinalSignalDTO
{
    /**
     * @param  string  $action  BUY|SELL|HOLD
     * @param  int  $confidence  0-100
     * @param  string  $riskLevel  LOW|MEDIUM|HIGH
     * @param  float|null  $entry  Optional entry price.
     * @param  float|null  $takeProfit  Optional take-profit price.
     * @param  float|null  $stopLoss  Optional stop-loss price.
     * @param  float|null  $positionSize  Optional position size.
     * @param  array<int, string>  $flags  Decision flags.
     * @param  float  $mtfScore  Deterministic aggregate score.
     * @param  string  $mode  reversal|trend_follow
     */
    public function __construct(
        public string $action,
        public int $confidence,
        public string $riskLevel,
        public ?float $entry,
        public ?float $takeProfit,
        public ?float $stopLoss,
        public ?float $positionSize,
        public array $flags,
        public float $mtfScore,
        public string $mode,
    ) {}

    /**
     * @return array{
     *   action: string,
     *   confidence: int,
     *   risk_level: string,
     *   entry: float|null,
     *   take_profit: float|null,
     *   stop_loss: float|null,
     *   position_size: float|null,
     *   flags: array<int, string>,
     *   mtf_score: float,
     *   mode: string
     * }
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
            'flags' => $this->flags,
            'mtf_score' => $this->mtfScore,
            'mode' => $this->mode,
        ];
    }
}
