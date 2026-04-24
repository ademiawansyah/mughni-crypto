<?php

namespace App\Services\Trading\DTO;

/**
 * MTFContextDTO
 *
 * Aggregated multi-timeframe context used by downstream AI and fusion layers.
 */
readonly class MTFContextDTO
{
    /**
     * @param  array<int, TimeframeSignalDTO>  $timeframeSignals
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public float $mtfScore,
        public string $direction,
        public string $mode,
        public string $alignment,
        public string $bias,
        public array $timeframeSignals,
        public array $flags = [],
    ) {}
}
