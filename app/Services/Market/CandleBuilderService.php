<?php

namespace App\Services\Market;

use App\Services\Trading\DTO\CandleDTO;
use App\Services\Trading\DTO\MarketDataDTO;

/**
 * CandleBuilderService
 *
 * Builds candle data for configured timeframes from a base market chart stream.
 */
class CandleBuilderService
{
    /**
     * @param  array<string>  $timeframes
     * @return array<int, CandleDTO>
     */
    public function build(MarketDataDTO $marketData, array $timeframes): array
    {
        $sortedTimeframes = $this->sortTimeframes($timeframes);

        if ($sortedTimeframes === []) {
            return [];
        }

        $baseTimeframe = $sortedTimeframes[0];
        $baseMinutes = $this->timeframeToMinutes($baseTimeframe);

        $prices = array_values($marketData->prices);
        $timestamps = array_values($marketData->timestamps);

        $candles = [];

        foreach ($sortedTimeframes as $timeframe) {
            $targetMinutes = $this->timeframeToMinutes($timeframe);

            if ($targetMinutes === PHP_INT_MAX || $baseMinutes === PHP_INT_MAX || $targetMinutes < $baseMinutes) {
                continue;
            }

            if ($targetMinutes % $baseMinutes !== 0) {
                continue;
            }

            $factor = (int) ($targetMinutes / $baseMinutes);
            $chunks = array_chunk($prices, max($factor, 1));
            $timestampChunks = array_chunk($timestamps, max($factor, 1));

            foreach ($chunks as $index => $chunk) {
                if ($chunk === []) {
                    continue;
                }

                $tsChunk = $timestampChunks[$index] ?? [];
                $lastTimestamp = $tsChunk !== []
                    ? (int) end($tsChunk)
                    : (int) ($timestamps[min(count($timestamps) - 1, $index)] ?? now()->valueOf());

                $candles[] = new CandleDTO(
                    timeframe: $timeframe,
                    open: (float) $chunk[0],
                    high: (float) max($chunk),
                    low: (float) min($chunk),
                    close: (float) end($chunk),
                    timestamp: $lastTimestamp,
                );
            }
        }

        return $candles;
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function sortTimeframes(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn (string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return $unique;
    }

    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        return PHP_INT_MAX;
    }
}
