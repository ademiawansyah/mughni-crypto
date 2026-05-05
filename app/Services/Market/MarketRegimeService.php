<?php

namespace App\Services\Market;

use App\Models\Coin;
use App\Services\External\BinanceService;
use Illuminate\Support\Facades\Log;

class MarketRegimeService
{
    private const CACHE_TTL_SECONDS = 900; // 10 minutes

    /**
     * Get OHLCV data for a given coin and timeframe.
     *
     * @param  string  $symbol  The symbol of the coin (e.g., 'bitcoin').
     * @param  string  $interval  The timeframe interval (e.g., '5m', '15m').
     * @param  int  $limit  The number of data points to retrieve.
     * @return array An array of OHLCV data points.
     */
    public function getOhlcvDataForCoin(string $symbol, string $interval, int $limit = 20): array
    {
        // implement caching
        $cacheKey = "ohlcv_{$symbol}_{$interval}_{$limit}";
        $cachedData = cache()->get($cacheKey);
        if ($cachedData) {
            Log::info("[MarketRegimeService] Returning cached OHLCV data for {$symbol} at interval {$interval}");

            return $cachedData;
        }

        $ohlcvData = (new BinanceService)->getOhlcvDataForCoin($symbol, $interval, $limit);

        // Cache the data for 5 minutes
        cache()->put($cacheKey, $ohlcvData, self::CACHE_TTL_SECONDS);

        return $ohlcvData;
    }
}
