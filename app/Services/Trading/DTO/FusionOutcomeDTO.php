<?php

namespace App\Services\Trading\DTO;

/**
 * FusionOutcomeDTO
 *
 * Combined fusion output containing final decision payload and fusion metadata.
 */
readonly class FusionOutcomeDTO
{
    public function __construct(
        public FinalDecisionDTO $decision,
        public FusionMetadataDTO $metadata,
    ) {}
}
