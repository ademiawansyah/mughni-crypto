<?php

namespace App\Services\Market;

use App\Services\External\CoinGeckoService;
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
     * Stablecoin CoinGecko IDs to exclude
     */
    private const STABLECOINS = [
        'tether',
        'usd-coin',
        'binance-usd',
        'paxos-standard',
        'true-usd',
        'dxchain-token',
        'dai',
        'frax',
        'usdd',
        'first-digital-usd',
        'paypal-usd',
    ];

    /**
     * Symbols that identify stablecoins (USDT suffix, BUSD, etc.)
     * Used as a secondary guard on top of the ID-based exclusion list.
     */
    private const STABLECOIN_SYMBOL_SUFFIXES = ['usdt', 'usdc', 'busd', 'usdp', 'tusd', 'dai', 'frax'];

    public function __construct(
        private readonly CoinGeckoService $coinGeckoService,
    ) {}

    /**
     * Fetch all coins from CoinGecko, apply filters, and cache the result.
     *
     * Process:
     * 1. Query CoinGecko `/coins/markets` endpoint (paginated, up to 4 × 250)
     * 2. Filter by market cap, volume, and stablecoin status
     * 3. Sort by market cap descending
     * 4. Take top 100
     * 5. Cache and return
     *
     * @param  string  $executionId  Pipeline execution identifier
     * @return array<int, array{coin: string, symbol: string, market_cap: float, volume_24h: float}> Filtered list of coins
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
     * @return array<int, array{coin: string, symbol: string, market_cap: float, volume_24h: float}>
     */
    public function getCachedUniverse(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    /**
     * Fetch all coins from CoinGecko, apply filters, and return sorted list.
     *
     * Paginates through /coins/markets (up to 4 pages × 250) and applies
     * market cap, volume, and stablecoin filters in a single pass.
     *
     * @return array<int, array{coin: string, symbol: string, market_cap: float, volume_24h: float}> Filtered and sorted coin list
     */
    private function fetchAndFilterCoins(): array
    {
        $coins = $this->getCoinsFromCoinGecko();

        $filtered = array_values(array_filter(
            $coins,
            fn (array $coin): bool => $this->passesAllFilters($coin),
        ));

        usort($filtered, fn (array $a, array $b): int => $b['market_cap'] <=> $a['market_cap']);

        return array_slice($filtered, 0, self::MAX_COINS);
    }

    /**
     * Evaluate whether a coin passes all filter criteria.
     *
     * @param  array{coin: string, symbol: string, market_cap: float|null, volume_24h: float|null}  $coin
     */
    private function passesAllFilters(array $coin): bool
    {
        if (($coin['market_cap'] ?? 0.0) < self::MIN_MARKET_CAP) {
            return false;
        }

        if (($coin['volume_24h'] ?? 0.0) < self::MIN_VOLUME_24H) {
            return false;
        }

        if (in_array($coin['coin'], self::STABLECOINS, true)) {
            return false;
        }

        // Secondary stablecoin guard: catch symbols ending with a stablecoin suffix
        $symbol = strtolower($coin['symbol'] ?? '');
        foreach (self::STABLECOIN_SYMBOL_SUFFIXES as $suffix) {
            if ($symbol === $suffix) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch coins from CoinGecko /coins/markets (paginated).
     *
     * Fetches up to 4 pages of 250 results each (1 000 coins total) and stops
     * early if a page returns fewer than 250 results.
     *
     * @return array<int, array{coin: string, symbol: string, market_cap: float, volume_24h: float}>
     */
    private function getCoinsFromCoinGecko(): array
    {
        $result = [];
        $maxPages = 4;

        for ($page = 1; $page <= $maxPages; $page++) {
            $batch = $this->coinGeckoService->fetchCoinMarkets($page, 250);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $item) {
                $result[] = [
                    'coin' => $item['id'],
                    'symbol' => $item['symbol'],
                    'market_cap' => $item['market_cap'] ?? 0.0,
                    'volume_24h' => $item['total_volume'] ?? 0.0,
                ];
            }

            // Stop paginating once we receive a partial page
            if (count($batch) < 250) {
                break;
            }
        }

        return $result;
    }
}
