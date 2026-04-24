<?php

namespace App\Services\Trading\DTO;

/**
 * CandleDTO
 *
 * Immutable candle representation for one timeframe bucket.
 */
readonly class CandleDTO
{
    public function __construct(
        public string $coin,
        public string $timeframe,
        public float $volume,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public int $timestampSeconds,
    ) {}
}
