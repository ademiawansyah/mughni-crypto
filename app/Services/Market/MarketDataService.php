<?php

namespace App\Services\Market;

use App\Jobs\CoinMarketDataJob;
use App\Models\Coin;
use App\Services\External\CoinGeckoService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MarketDataService
 *
 * Orchestrates the market data ingestion pipeline per coin:
 *   1. Fetch one market_chart dataset (days=1) from CoinGeckoService.
 *   2. Persist the full raw API response to `market_raw`.
 *   3. Build role timeframe close series from the same base dataset:
 *      - 5m  => base close series
 *      - 15m => aggregate factor 3
 *      - 30m => aggregate factor 6
 *      - 60m => aggregate factor 12
 *   4. Calculate RSI, EMA9, EMA21, and trend per timeframe.
 *   5. Persist computed indicators to `market_indicators`.
 */
class MarketDataService
{
    public function __construct() {}

    /**
     * Run a full ingestion cycle for the given list of coins.
     *
     * @param  array<string>  $coins
     * @param  array<string>  $timeframes  Dynamic timeframe list from configuration.
     * @param  string  $executionId  Pipeline execution identifier for traceability.
     */
    public function ingest(array $coins, array $timeframes, string $executionId = ''): void
    {
        $sortedTimeframes = $this->sortTimeframes($timeframes);

        if (count($sortedTimeframes) === 0) {
            Log::warning('[MarketDataService] Ingestion aborted — no timeframe configured', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        $index = 1;
        Log::info('[MarketDataService] Dispatching CoinMarketDataJob for coins', [
            'execution_id' => $executionId,
            'coins_count' => count($coins),
            'timeframes' => $sortedTimeframes,
        ]);

        foreach ($coins as $coin) {
            try {
                $delaySeconds = $index + rand(0, 10); // add random delay to prevent job dispatch collisions
                CoinMarketDataJob::dispatch($coin, $sortedTimeframes, $executionId)->delay(now()->addSeconds($delaySeconds)); // slight delay to prevent job dispatch collisions
                $index++;
            } catch (Throwable $e) {
                Log::error('[MarketDataService] Failed to ingest coin', [
                    'execution_id' => $executionId,
                    'coin' => $coin,
                    'timeframes' => $sortedTimeframes,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function sortTimeframes(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn(string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

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

        if (preg_match('/^(\d+)d$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60 * 24;
        }

        return PHP_INT_MAX;
    }
}
