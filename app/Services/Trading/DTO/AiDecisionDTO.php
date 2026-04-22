<?php

namespace App\Services\Trading\DTO;

/**
 * AiDecisionDTO
 *
 * Structured AI recommendation payload.
 */
readonly class AiDecisionDTO
{
    public function __construct(
        public string $action,
        public int $confidence,
        public string $reason,
    ) {}
}
