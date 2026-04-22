<?php

namespace App\Services\Trading\DTO;

/**
 * FusionMetadataDTO
 *
 * Captures fusion-stage metadata used for persistence and observability.
 */
readonly class FusionMetadataDTO
{
    public function __construct(
        public string $aiAction,
        public int $aiConfidence,
        public float $mtfScore,
        public string $mtfAlignment,
        public string $contextBias,
        public int $confidenceDelta,
        public int $confidenceAdjusted,
        public string $finalAction,
    ) {}
}
