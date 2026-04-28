<?php

namespace App\Services\Market;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CoinUniverseService — Candidate Coin Filtering
 *
 * Maintains a curated universe of coins eligible for trading signals based on
 * predefined market cap, volume, and exchange criteria. This universe is
 * independent of individual signals and is refreshed daily.
 *
 * Filters applied:
 * - Market cap > $100M (excludes micro-caps)
 * - Volume (24h) > $5M (excludes illiquid assets)
 * - Exclude stablecoins (USDT, USDC, BUSD, USDP, TUSD, DXSC)
 * - Binance futures only
 * - Maximum 100 coins (prevents explosion of data)
 *
 * Output (Redis key: coin_universe:main, TTL: 86400s):
 * [
 *   {coin: "bitcoin", market_cap: 1500000000000, volume_24h: 50000000000, rank: 1},
 *   {coin: "ethereum", market_cap: 250000000000, volume_24h: 15000000000, rank: 2},
 *   ...
 * ]
 */
class CoinUniverseService
{
    /**
     * Redis cache key for coin universe
     */
    private const CACHE_KEY = 'coin_universe:main';

    /**
     * Cache TTL in seconds (24 hours)
     */
    private const CACHE_TTL = 86400;

    /**
     * Minimum market cap (USD)
     */
    private const MIN_MARKET_CAP = 100_000_000; // $100M

    /**
     * Minimum 24h volume (USD)
     */
    private const MIN_VOLUME_24H = 5_000_000; // $5M

    /**
     * Maximum number of coins in universe
     */
    private const MAX_COINS = 100;

    /**
     * Stablecoin symbols to exclude
     */
    private const STABLECOINS = [
        'tether',
        'usd-coin',
        'binance-usd',
        'paxos-standard',
        'true-usd',
        'dxchain-token',
    ];

    /**
     * Fetch all coins from CoinGecko, apply filters, and cache the result.
     *
     * Process:
     * 1. Query CoinGecko `/coins/markets` endpoint (paginated)
     * 2. Filter by market cap, volume, stablecoin status
     * 3. Verify Binance futures availability (internal DB check)
     * 4. Sort by market cap descending
     * 5. Take top 100
     * 6. Cache and return
     *
     * NOTE: Actual CoinGecko integration would use `MarketDataService`
     * For Phase 1, mock data structure is shown; real implementation requires
     * CoinGecko API key and pagination logic.
     *
     * @param  string  $executionId  Pipeline execution identifier
     * @return array Filtered list of coins with metadata
     */
    public function updateUniverse(string $executionId = ''): array
    {
        $coins = $this->fetchAndFilterCoins();

        // Cache the result
        Cache::put(self::CACHE_KEY, $coins, self::CACHE_TTL);

        Log::info('[CoinUniverseService] Universe updated', [
            'execution_id' => $executionId,
            'total_coins' => count($coins),
        ]);

        return $coins;
    }

    /**
     * Get the currently cached coin universe.
     *
     * Returns cached value if available; otherwise returns empty array.
     *
     * @return array List of coins
     */
    public function getCachedUniverse(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    /**
     * Fetch all coins from CoinGecko and apply filters.
     *
     * @return array Filtered and sorted coin list
     */
    private function fetchAndFilterCoins(): array
    {
        // In Phase 1, this is a placeholder. Real implementation would:
        // 1. Call CoinGecko API via MarketDataService
        // 2. Paginate through results (max 250 per page)
        // 3. Apply filters in real-time
        // 4. Verify Binance futures symbols

        // For now, return a minimal structure showing expected format
        $coins = $this->getCoinsFromCoinGecko();

        // Apply filters
        $filtered = array_filter($coins, function ($coin) {
            return $this->passesAllFilters($coin);
        });

        // Sort by market cap descending
        usort($filtered, fn ($a, $b) => $b['market_cap'] <=> $a['market_cap']);

        // Take top N
        return array_slice($filtered, 0, self::MAX_COINS);
    }

    /**
     * Evaluate whether a coin passes all filter criteria.
     *
     * @param  array{coin: string, market_cap: ?float, volume_24h: ?float}  $coin
     */
    private function passesAllFilters(array $coin): bool
    {
        // Market cap filter
        if (($coin['market_cap'] ?? 0) < self::MIN_MARKET_CAP) {
            return false;
        }

        // Volume filter
        if (($coin['volume_24h'] ?? 0) < self::MIN_VOLUME_24H) {
            return false;
        }

        // Stablecoin filter
        if (in_array($coin['coin'], self::STABLECOINS, true)) {
            return false;
        }

        // Binance futures availability (simplified check)
        // Real implementation would query Binance API or local symbol mapping
        if (! $this->hasBindanceFutures($coin['coin'])) {
            return false;
        }

        return true;
    }

    /**
     * Check whether coin has Binance futures available.
     *
     * Phase 1: Simplified check. Real implementation would:
     * - Query Binance API /fapi/v1/exchangeInfo
     * - Cache symbol list locally
     * - Verify trading enabled
     *
     * @param  string  $coin  CoinGecko coin ID
     */
    private function hasBindanceFutures(string $coin): bool
    {
        // Placeholder: assume major coins have futures
        $majorCoins = ['bitcoin', 'ethereum', 'binancecoin', 'cardano', 'solana'];

        return in_array($coin, $majorCoins, true);
    }

    /**
     * Fetch coins from CoinGecko API (placeholder for Phase 1).
     *
     * Real implementation:
     * - Uses MarketDataService to call CoinGecko /coins/markets
     * - Handles pagination (250 per page)
     * - Filters in loop to avoid storing full list in memory
     */
    private function getCoinsFromCoinGecko(): array
    {
        // Phase 1 placeholder: return mock data
        return [
            ['coin' => 'bitcoin', 'market_cap' => 1_500_000_000_000, 'volume_24h' => 50_000_000_000],
            ['coin' => 'ethereum', 'market_cap' => 250_000_000_000, 'volume_24h' => 15_000_000_000],
            ['coin' => 'binancecoin', 'market_cap' => 60_000_000_000, 'volume_24h' => 2_000_000_000],
            ['coin' => 'cardano', 'market_cap' => 20_000_000_000, 'volume_24h' => 1_000_000_000],
            ['coin' => 'solana', 'market_cap' => 15_000_000_000, 'volume_24h' => 800_000_000],
        ];
    }
}
