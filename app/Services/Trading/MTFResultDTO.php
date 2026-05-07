<?php

namespace App\Services\Trading;

/**
 * MTFResultDTO — Multi-Timeframe Decision Result
 *
 * Immutable value object produced by MTFDecisionService after role-based
 * scoring across 5m/15m/30m/60m signals.
 *
 * This DTO is passed downstream to:
 *   - AiAdvisorService  — optional confidence refinement input
 *   - DecisionGuardrailService — enforces preliminary_action as final authority
 */
readonly class MTFResultDTO
{
    /**
     * @param  float  $mtfScore  Aggregate score across role-based timeframe scoring.
     * @param  float  $mtfRawScore  Original weighted score before adjustments (reversals, boosts).
     * @param  string  $preliminaryAction  Deterministic preliminary action: BUY|SELL|HOLD.
     * @param  int  $baseConfidence  Base confidence before optional AI refinement.
     * @param  string  $mode  reversal|trend_follow.
     * @param  array<int, string>  $flags  MTF-specific flags (direction filter, missing data).
     * @param  array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>  $timeframeSignals
     * @param  array{trigger: string, setup: string, context: string, direction: string}  $roleTimeframes
     */
    public function __construct(
        public float $mtfScore,
        public float $mtfRawScore,
        public string $preliminaryAction,
        public int $baseConfidence,
        public string $mode,
        public array $flags,
        public array $timeframeSignals,
        public array $roleTimeframes,
    ) {}

    /**
     * Serialize to array for logging and prompt injection.
     *
     * @return array{
     *   mtf_score: float,
     *   mtf_raw_score: float,
     *   preliminary_action: string,
     *   base_confidence: int,
     *   mode: string,
     *   flags: array<int, string>,
     *   timeframe_signals: array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>,
     *   role_timeframes: array{trigger: string, setup: string, context: string, direction: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'mtf_score' => $this->mtfScore,
            'mtf_raw_score' => $this->mtfRawScore,
            'preliminary_action' => $this->preliminaryAction,
            'base_confidence' => $this->baseConfidence,
            'mode' => $this->mode,
            'flags' => $this->flags,
            'timeframe_signals' => $this->timeframeSignals,
            'role_timeframes' => $this->roleTimeframes,
        ];
    }
}
