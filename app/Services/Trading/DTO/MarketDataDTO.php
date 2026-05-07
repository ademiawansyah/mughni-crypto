<?php

namespace App\Services\Trading\DTO;

/**
 * MarketDataDTO
 *
 * Immutable market chart payload used across the pipeline.
 */
readonly class MarketDataDTO
{
    /**
     * @param  array<int, float>  $prices
     * @param  array<int, float>  $volumes
     * @param  array<int, int>  $timestamps
     * @param  array<string, mixed>  $requestParams
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public array $prices,
        public array $volumes,
        public array $timestamps,
        public array $requestParams = [],
        public array $rawResponse = [],
    ) {}
}
