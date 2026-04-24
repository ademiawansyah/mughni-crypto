<?php

namespace App\Services\Market;

use App\Services\Trading\DTO\CandleDTO;
use App\Services\Trading\TimeframeParser;
use Illuminate\Support\Facades\Log;

/**
 * CandleBuilderService
 *
 * Builds candle data for configured timeframes from a base market chart stream.
 */
class CandleBuilderService
{
    private const MAX_POINTS = 500;

    public function __construct(
        private readonly TimeframeParser $timeframeParser,
    ) {}

    /**
     * Build candles from CoinGecko market chart arrays.
     *
     * @param  array<int, mixed>  $prices  Each item can be [timestamp, price] or scalar price.
     * @param  array<int, mixed>  $volumes  Each item can be [timestamp, volume] or scalar volume.
     * @param  array<string>  $timeframes
     * @return array<int, CandleDTO>
     */
    public function build(string $coin, array $prices, array $volumes, array $timeframes): array
    {
        $sortedTimeframes = $this->sortSupportedTimeframes($timeframes);

        if ($sortedTimeframes === []) {
            return [];
        }

        [$pricePoints, $volumeByTimestamp] = $this->normalizeInputs($prices, $volumes);

        $candles = [];

        foreach ($sortedTimeframes as $timeframe) {
            $bucketSeconds = $this->timeframeParser->toSeconds($timeframe);
            $bucketMap = [];

            foreach ($pricePoints as $point) {
                $timestamp = $point['timestamp'];
                $price = $point['price'];
                $bucket = (int) (floor($timestamp / $bucketSeconds) * $bucketSeconds);
                $volume = $volumeByTimestamp[$timestamp] ?? 0.0;

                if (! isset($bucketMap[$bucket])) {
                    $bucketMap[$bucket] = [
                        'open' => $price,
                        'high' => $price,
                        'low' => $price,
                        'close' => $price,
                        'volume' => $volume,
                    ];

                    continue;
                }

                $bucketMap[$bucket]['high'] = max($bucketMap[$bucket]['high'], $price);
                $bucketMap[$bucket]['low'] = min($bucketMap[$bucket]['low'], $price);
                $bucketMap[$bucket]['close'] = $price;
                $bucketMap[$bucket]['volume'] += $volume;
            }

            ksort($bucketMap);

            foreach ($bucketMap as $bucketTimestamp => $bucketData) {
                $candles[] = new CandleDTO(
                    coin: $coin,
                    timeframe: $timeframe,
                    volume: (float) $bucketData['volume'],
                    open: (float) $bucketData['open'],
                    high: (float) $bucketData['high'],
                    low: (float) $bucketData['low'],
                    close: (float) $bucketData['close'],
                    timestampSeconds: (int) $bucketTimestamp,
                );
            }
        }

        return $candles;
    }

    /**
     * @param  array<int, mixed>  $prices
     * @param  array<int, mixed>  $volumes
     * @return array{0: array<int, array{timestamp: int, price: float}>, 1: array<int, float>}
     */
    private function normalizeInputs(array $prices, array $volumes): array
    {
        $volumeByTimestamp = [];

        foreach ($volumes as $index => $volumePoint) {
            if (is_array($volumePoint) && count($volumePoint) >= 2) {
                $timestamp = $this->normalizeTimestamp($volumePoint[0]);
                $volumeByTimestamp[$timestamp] = (float) $volumePoint[1];

                continue;
            }

            $volumeByTimestamp[$index] = is_numeric($volumePoint)
                ? (float) $volumePoint
                : 0.0;
        }

        $pricePoints = [];

        foreach ($prices as $index => $pricePoint) {
            if (is_array($pricePoint) && count($pricePoint) >= 2) {
                $timestamp = $this->normalizeTimestamp($pricePoint[0]);
                $price = (float) $pricePoint[1];
            } else {
                $timestamp = (int) $index;
                $price = is_numeric($pricePoint) ? (float) $pricePoint : 0.0;
            }

            $pricePoints[] = [
                'timestamp' => $timestamp,
                'price' => $price,
            ];
        }

        $pricePoints = array_values(array_filter(
            $pricePoints,
            static fn (array $point): bool => $point['timestamp'] > 0,
        ));

        usort($pricePoints, static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);

        if (count($pricePoints) > self::MAX_POINTS) {
            $pricePoints = array_slice($pricePoints, -self::MAX_POINTS);
        }

        return [$pricePoints, $volumeByTimestamp];
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function sortSupportedTimeframes(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));
        $supported = [];

        foreach ($unique as $timeframe) {
            try {
                $this->timeframeParser->toSeconds($timeframe);
                $supported[] = trim($timeframe);
            } catch (\InvalidArgumentException) {
                Log::warning('[CandleBuilderService] Ignoring unsupported timeframe', [
                    'timeframe' => $timeframe,
                ]);
            }
        }

        return $this->timeframeParser->sortUnique($supported);
    }

    private function normalizeTimestamp(mixed $timestamp): int
    {
        if (! is_numeric($timestamp)) {
            return 0;
        }

        $normalized = (int) $timestamp;

        if ($normalized > 1000000000000) {
            $normalized = (int) floor($normalized / 1000);
        }

        return $normalized;
    }
}
