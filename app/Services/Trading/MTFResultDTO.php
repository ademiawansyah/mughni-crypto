<?php

namespace App\Services\Trading;

/**
 * MTFResultDTO — Multi-Timeframe Scoring Result
 *
 * Immutable value object produced by MTFScoringService after aggregating
 * per-timeframe MCP signals into a single deterministic weighted score.
 *
 * This DTO is passed downstream to:
 *   - AiAdvisorService  — informs the AI prompt with preliminary action and score
 *   - DecisionGuardrailService — enforces preliminary_action as the final authority
 */
readonly class MTFResultDTO
{
    /**
     * @param  float  $mtfScore  Weighted aggregate score across all timeframes.
     * @param  string  $preliminaryAction  Deterministic preliminary action: BUY | SELL | HOLD.
     * @param  int  $baseConfidence  Base confidence (50–85) before AI refinement.
     */
    public function __construct(
        public float $mtfScore,
        public string $preliminaryAction,
        public int $baseConfidence,
    ) {}

    /**
     * Serialize to array for logging and prompt injection.
     *
     * @return array{mtf_score: float, preliminary_action: string, base_confidence: int}
     */
    public function toArray(): array
    {
        return [
            'mtf_score' => $this->mtfScore,
            'preliminary_action' => $this->preliminaryAction,
            'base_confidence' => $this->baseConfidence,
        ];
    }
}
