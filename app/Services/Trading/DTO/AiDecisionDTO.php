<?php

namespace App\Services\Trading\DTO;

/**
 * AiDecisionDTO
 *
 * Structured AI recommendation payload.
 * Action and confidence are nullable when AI is unavailable.
 */
readonly class AiDecisionDTO
{
    /**
     * @param  string|null  $action  BUY|SELL|HOLD or null when AI unavailable.
     * @param  int|null  $confidence  0-100 or null when AI unavailable.
     * @param  array<int, string>  $validationFlags  Flags set by the AI validation guardrail.
     */
    public function __construct(
        public ?string $action,
        public ?int $confidence,
        public string $reason,
        public array $validationFlags = [],
    ) {}
}
