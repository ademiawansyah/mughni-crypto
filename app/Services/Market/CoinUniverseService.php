<?php

namespace App\Services\Market;

use App\Models\Coin;
use App\Services\External\BinanceFuturesService;
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
     * Minimum open interest notional in USD.
     */
    private const MIN_OPEN_INTEREST_USD = 1_000_000; // $1M

    /**
     * Maximum number of coins in universe
     */
    private const MAX_COINS = 200;

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
        private readonly BinanceFuturesService $binanceFuturesService,
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
        Log::info('[CoinUniverseService] Fetched and filtered coins', [
            'execution_id' => $executionId,
            'filtered_count' => count($coins),
        ]);
        if ($coins === []) {
            $existingUniverse = $this->getCachedUniverse();

            if ($existingUniverse !== []) {
                Log::warning('[CoinUniverseService] Universe refresh returned empty, preserving previous cache', [
                    'execution_id' => $executionId,
                    'preserved_coins' => count($existingUniverse),
                ]);

                return $existingUniverse;
            }

            Log::warning('[CoinUniverseService] Universe refresh returned empty and no previous cache found', [
                'execution_id' => $executionId,
            ]);
        }

        // save to database (coin_universe table)
        foreach ($coins as $coinData) {
            Coin::updateOrCreate(
                ['symbol' => $coinData['symbol']],
                [
                    'name' => $coinData['name'],
                    'image' => $coinData['image'],
                    'coin_gecko_id' => $coinData['coin_gecko_id'] ?? null,
                    'raw_data' => $coinData['raw_data'] ?? null,
                    'coin_data_last_updated' => $coinData['coin_data_last_updated'] ?? null,
                    'last_fetched_at' => $coinData['last_fetched_at'] ?? null,
                    'market_cap' => $coinData['market_cap'] ?? null,
                    'volume_24h' => $coinData['volume_24h'] ?? null,
                    'current_price' => $coinData['current_price'] ?? null,
                ]
            );
        }

        // Cache the result in Redis with TTL
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

        $baseFiltered = array_values(array_filter(
            $coins,
            fn (array $coin): bool => $this->passesBaseFilters($coin),
        ));

        // Disabled derivatives enrichment step since doesnt have Binance API
        // $enriched = array_map(
        //     fn(array $coin): array => $this->enrichWithDerivatives($coin),
        //     $baseFiltered,
        // );

        $filtered = array_values(array_filter(
            $baseFiltered,
            fn (array $coin): bool => $this->passesAllFilters($coin),
        ));

        Log::info('[CoinUniverseService] Applied filters to coins', [
            'total_fetched' => count($coins),
            'after_base_filters' => count($baseFiltered),
            'after_all_filters' => count($filtered),
        ]);

        usort($filtered, fn (array $a, array $b): int => ($b['market_cap'] <=> $a['market_cap']) ?: ($b['open_interest_usd'] <=> $a['open_interest_usd']));

        return array_slice($filtered, 0, self::MAX_COINS);
    }

    /**
     * @param  array{coin: string, symbol: string, market_cap: float|null, volume_24h: float|null}  $coin
     */
    private function passesBaseFilters(array $coin): bool
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
     * Evaluate whether a coin passes all filter criteria.
     *
     * Note: The Binance futures availability check and OI filter are only applied when
     * enrichWithDerivatives() has been run (i.e. has_binance_futures and open_interest_usd
     * are present on the coin array). CoinGecko /coins/markets does not provide OI data —
     * that field comes exclusively from the Binance Futures /fapi/v1/openInterest endpoint.
     * While derivatives enrichment is disabled, only base filters apply.
     *
     * @param  array{coin: string, symbol: string, market_cap: float|null, volume_24h: float|null, has_binance_futures?: bool, open_interest_usd?: float|null}  $coin
     */
    private function passesAllFilters(array $coin): bool
    {
        if (! $this->passesBaseFilters($coin)) {
            return false;
        }

        // OI and Binance futures checks are only meaningful after enrichWithDerivatives() runs.
        // When derivatives enrichment is disabled, skip these checks to avoid silently
        // filtering out all coins (open_interest_usd would be 0 for every coin).
        if (isset($coin['has_binance_futures'])) {
            if ($coin['has_binance_futures'] !== true) {
                return false;
            }

            if (($coin['open_interest_usd'] ?? 0.0) < self::MIN_OPEN_INTEREST_USD) {
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
     * @return array<int, array{coin: string, symbol: string, market_cap: float, volume_24h: float, current_price: float}>
     */
    private function getCoinsFromCoinGecko(): array
    {
        Log::info('[CoinUniverseService] Fetching coins from CoinGecko', [
            'timestamp' => now()->toIso8601String(),
        ]);
        $result = [];
        $maxPages = 4;

        for ($page = 1; $page <= $maxPages; $page++) {
            $batch = $this->coinGeckoService->fetchCoinMarkets($page, 300);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $item) {
                $result[] = [
                    'coin' => $item['id'],
                    'coin_gecko_id' => $item['id'],
                    'symbol' => $item['symbol'],
                    'name' => $item['name'],
                    'image' => $item['image'] ?? '',
                    'market_cap' => $item['market_cap'] ?? 0.0,
                    'volume_24h' => $item['total_volume'] ?? 0.0,
                    'current_price' => $item['current_price'] ?? 0.0,
                    'coin_data_last_updated' => $item['last_updated'] ?? '',
                    'last_fetched_at' => now()->toIso8601String(),
                    'raw_data' => json_decode((string) json_encode($item), true) ?? [],
                    // json_encode → json_decode ensures the value is a clean,
                    // JSON-serializable array (no objects, closures, or resource types)
                    // before it is stored in the JSONB column via the model cast.
                ];
            }

            // get sample of result for logging
            Log::info('[CoinUniverseService] Fetched batch of coins from CoinGecko', [
                'page' => $page,
                'batch_count' => count($batch),
                'sample_coins' => array_slice($batch, 0, 5),
            ]);

            // Stop paginating once we receive a partial page
            if (count($batch) < 250) {
                break;
            }
        }

        Log::info('[CoinUniverseService] Completed fetching coins from CoinGecko', [
            'total_fetched' => count($result),
        ]);

        return $result;
    }

    /**
     * @param  array{coin: string, symbol: string, market_cap: float, volume_24h: float, current_price: float}  $coin
     * @return array{coin: string, symbol: string, market_cap: float, volume_24h: float, current_price: float, has_binance_futures: bool, open_interest_usd: float}
     */
    private function enrichWithDerivatives(array $coin): array
    {
        $symbol = $this->normalizeBinanceSymbol((string) $coin['symbol']);
        $hasBinanceFutures = $symbol !== '' && $this->binanceFuturesService->hasPerpetualUsdtSymbol($symbol);

        $openInterestUsd = 0.0;

        if ($hasBinanceFutures) {
            $openInterestUsd = (float) ($this->binanceFuturesService->fetchOpenInterestUsd(
                $symbol,
                (float) ($coin['current_price'] ?? 0.0),
            ) ?? 0.0);
        }

        return [
            ...$coin,
            'has_binance_futures' => $hasBinanceFutures,
            'open_interest_usd' => $openInterestUsd,
        ];
    }

    private function normalizeBinanceSymbol(string $symbol): string
    {
        $clean = strtoupper(trim($symbol));

        if ($clean === '') {
            return '';
        }

        return str_ends_with($clean, 'USDT') ? $clean : ($clean.'USDT');
    }
}
