<?php

namespace App\Services\Trading\DTO;

/**
 * AiDecisionDTO
 *
 * Structured AI recommendation payload.
 */
readonly class AiDecisionDTO
{
    /**
     * @param  array<int, string>  $validationFlags  Flags set by the AI validation guardrail.
     */
    public function __construct(
        public string $action,
        public int $confidence,
        public string $reason,
        public array $validationFlags = [],
    ) {}
}
