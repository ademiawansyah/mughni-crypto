<?php

namespace App\Services\Trading;

use InvalidArgumentException;

/**
 * TimeframeParser
 *
 * Parses timeframe labels into seconds.
 */
class TimeframeParser
{
    /**
     * Convert timeframe string to seconds.
     *
     * Supported format: {number}m (e.g. 5m, 15m, 60m).
     */
    public function toSeconds(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) !== 1) {
            throw new InvalidArgumentException("Unsupported timeframe [{$timeframe}] provided.");
        }

        $minutes = (int) $matches[1];

        if ($minutes <= 0) {
            throw new InvalidArgumentException("Invalid timeframe [{$timeframe}] provided.");
        }

        return $minutes * 60;
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    public function sortUnique(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn (string $left, string $right): int => $this->toSeconds($left) <=> $this->toSeconds($right));

        return $unique;
    }
}
