<?php

namespace App\Services\Market;

use App\Models\MarketIndicator;
use App\Services\Trading\DTO\CandleDTO;
use Carbon\CarbonImmutable;

/**
 * CandlePersistenceService
 *
 * Persists aggregated candles to market_indicators using upsert.
 */
class CandlePersistenceService
{
    /**
     * @param  array<int, CandleDTO>  $candles
     */
    public function upsert(array $candles, string $executionId): void
    {
        if ($candles === []) {
            return;
        }

        $rows = [];

        foreach ($candles as $candle) {
            $rows[] = [
                'execution_id' => $executionId,
                'coin' => $candle->coin,
                'timeframe' => $candle->timeframe,
                'timestamp' => CarbonImmutable::createFromTimestampUTC($candle->timestampSeconds)->toDateTimeString(),
                'price' => $candle->close,
                'volume' => $candle->volume,
                'trend' => 'sideways',
                'source' => 'coingecko',
            ];
        }

        MarketIndicator::query()->upsert(
            $rows,
            ['coin', 'timeframe', 'timestamp'],
            ['execution_id', 'price', 'volume', 'source'],
        );
    }
}
