<?php

namespace App\Services\Trading\DTO;

/**
 * IndicatorDTO
 *
 * Immutable indicator snapshot per timeframe.
 */
readonly class IndicatorDTO
{
    public function __construct(
        public string $timeframe,
        public float $rsi,
        public string $trend,
        public float $volumeRatio,
        public float $price,
    ) {}
}
