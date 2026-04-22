<?php

namespace App\Services\Trading\DTO;

/**
 * AiAdviceDTO
 *
 * Structured AI advisory result including decision and raw model metadata.
 */
readonly class AiAdviceDTO
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public AiDecisionDTO $decision,
        public ?array $rawResponse,
        public string $modelUsed,
    ) {}
}
