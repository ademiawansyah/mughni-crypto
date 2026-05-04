<?php

namespace App\Services\Market;

use App\Models\Coin;
use App\Services\External\CoinGeckoService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SharedFetchService (Layer 1 - Shared Fetch)
 *
 * Centralized market data fetcher for all models.
 * Fetches top market-cap coins from CoinGecko and persists them into the coins table.
 *
 * Persistence strategy:
 * - upsert by unique symbol
 * - refresh market fields and raw payload per fetch
 * - keep last_fetched_at for freshness checks
 */
class SharedFetchService
{
    /** Freshness window in minutes. */
    private const FRESHNESS_WINDOW_MINUTES = 5;

    /** Rows requested per CoinGecko page. */
    private const FETCH_PER_PAGE = 101;

    /** Number of pages requested from CoinGecko. */
    private const FETCH_PAGE_COUNT = 3;

    /** Maximum rows requested from CoinGecko per full fetch cycle. */
    private const FETCH_LIMIT = self::FETCH_PER_PAGE * self::FETCH_PAGE_COUNT;

    /**
     * Constructor.
     */
    public function __construct(
        private readonly CoinGeckoService $coinGeckoService,
    ) {}

    /**
     * Fetch top market-cap coins from CoinGecko and persist them to coins table.
     *
     * @param  string|null  $executionId  Optional execution ID (generated if null)
     * @return array{execution_id: string, timestamp: string, total_coins_fetched: int, stored_count: int, removed_count: int}
     */
    public function fetchAndStoreMarketData(?string $executionId = null): array
    {
        $executionId = $executionId ?? (string) Str::uuid();
        $fetchedAt = now();

        Log::info('Layer 1: Fetching market data from CoinGecko', [
            'execution_id' => $executionId,
            'per_page' => self::FETCH_PER_PAGE,
            'pages' => self::FETCH_PAGE_COUNT,
            'max_total' => self::FETCH_LIMIT,
        ]);

        $rawResponse = $this->fetchCoinMarketsInPages();

        if ($rawResponse === []) {
            Log::warning('Layer 1: CoinGecko returned empty response', [
                'execution_id' => $executionId,
            ]);

            return [
                'execution_id' => $executionId,
                'timestamp' => $fetchedAt->toIso8601String(),
                'total_coins_fetched' => 0,
                'stored_count' => 0,
                'removed_count' => 0,
            ];
        }

        $normalizedSymbols = $this->extractNormalizedSymbols($rawResponse);
        $storedCount = $this->upsertCoins($rawResponse, $fetchedAt);
        $removedCount = $this->removeCoinsNotInLatestSet($normalizedSymbols);

        Log::info('Layer 1: Market data persisted to database', [
            'execution_id' => $executionId,
            'fetched_count' => count($rawResponse),
            'stored_count' => $storedCount,
            'removed_count' => $removedCount,
        ]);

        return [
            'execution_id' => $executionId,
            'timestamp' => $fetchedAt->toIso8601String(),
            'total_coins_fetched' => count($rawResponse),
            'stored_count' => $storedCount,
            'removed_count' => $removedCount,
        ];
    }

    /**
     * Retrieve fresh market data snapshot from database without external fetch.
     *
     * Returns null when data is missing or older than the freshness window.
     *
     * @return array<string, mixed>|null
     */
    public function getFreshMarketDataFromDatabase(): ?array
    {
        /** @var Carbon|string|null $lastFetchedAt */
        $lastFetchedAt = Coin::query()->max('last_fetched_at');

        if ($lastFetchedAt === null) {
            return null;
        }

        $lastFetchedAtCarbon = $lastFetchedAt instanceof Carbon
            ? $lastFetchedAt
            : Carbon::parse((string) $lastFetchedAt);

        if ($lastFetchedAtCarbon->lt(now()->subMinutes(self::FRESHNESS_WINDOW_MINUTES))) {
            return null;
        }

        $coins = Coin::query()
            ->orderByDesc('market_cap')
            ->limit(300)
            ->get()
            ->map(fn (Coin $coin): array => [
                'id' => $coin->coin_gecko_id,
                'symbol' => strtolower($coin->symbol),
                'name' => $coin->name,
                'image' => $coin->image,
                'market_cap' => $coin->market_cap,
                'total_volume' => $coin->volume_24h,
                'current_price' => $coin->current_price,
                'last_updated' => $coin->coin_data_last_updated?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'execution_id' => null,
            'timestamp' => $lastFetchedAtCarbon->toIso8601String(),
            'freshness_window_minutes' => self::FRESHNESS_WINDOW_MINUTES,
            'total_coins_fetched' => count($coins),
            'total_coins_upserted' => count($coins),
            'raw_response' => $coins,
        ];
    }

    /**
     * Retrieve latest persisted rows ordered by market cap.
     *
     * @return Collection<int, Coin>
     */
    public function getLatestCoinsFromDatabase(): Collection
    {
        return Coin::query()
            ->orderByDesc('market_cap')
            ->get();
    }

    /**
     * Parse an API date-time value safely.
     */
    private function parseDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert numeric-like values to float or null.
     */
    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Upsert coins into database using symbol as the unique key.
     *
     * @param  array<int, array<string, mixed>>  $coins
     */
    private function upsertCoins(array $coins, Carbon $fetchedAt): int
    {
        $storedCount = 0;

        foreach ($coins as $coinData) {
            $symbol = strtoupper(trim((string) ($coinData['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }

            Log::debug('Layer 1: Upserting coin', [
                'symbol' => $symbol,
                'name' => $coinData['name'] ?? null,
                'market_cap' => $coinData['market_cap'] ?? null,
                'current_price' => $coinData['current_price'] ?? null,
                'total_volume' => $this->toNullableFloat($coinData['total_volume'] ?? null),
            ]);

            Coin::query()->updateOrCreate(
                ['symbol' => $symbol],
                [
                    'name' => $coinData['name'] ?? null,
                    'image' => $coinData['image'] ?? null,
                    'coin_gecko_id' => $coinData['id'] ?? null,
                    'coin_data_last_updated' => $this->parseDateTime($coinData['last_updated'] ?? null),
                    'last_fetched_at' => $fetchedAt,
                    'market_cap' => $this->toNullableFloat($coinData['market_cap'] ?? null),
                    'total_volume' => $this->toNullableFloat($coinData['total_volume'] ?? null),
                    'is_valid' => true,
                    'volume_24h' => $this->toNullableFloat($coinData['total_volume'] ?? null),
                    'current_price' => $this->toNullableFloat($coinData['current_price'] ?? null),
                    'raw_data' => $coinData,
                ],
            );

            $storedCount++;
        }

        return $storedCount;
    }

    /**
     * Build unique, normalized symbols from API payload.
     *
     * @param  array<int, array<string, mixed>>  $coins
     * @return array<int, string>
     */
    private function extractNormalizedSymbols(array $coins): array
    {
        $symbols = [];

        foreach ($coins as $coinData) {
            $symbol = strtoupper(trim((string) ($coinData['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }

            $symbols[$symbol] = true;
        }

        return array_keys($symbols);
    }

    /**
     * Remove rows from coins table that are not in the latest top-300 API set.
     *
     * @param  array<int, string>  $latestSymbols
     */
    private function removeCoinsNotInLatestSet(array $latestSymbols): int
    {
        if ($latestSymbols === []) {
            return 0;
        }

        return Coin::query()
            ->whereNotIn('symbol', $latestSymbols)
            ->delete();
    }

    /**
     * Fetch coin market pages from CoinGecko and merge them into a single list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCoinMarketsInPages(): array
    {
        $merged = [];

        for ($page = 1; $page <= self::FETCH_PAGE_COUNT; $page++) {
            $pageData = $this->coinGeckoService->fetchCoinMarkets(
                page: $page,
                perPage: self::FETCH_PER_PAGE,
            );

            if ($pageData === []) {
                Log::warning('Layer 1: Empty page received from CoinGecko markets', [
                    'page' => $page,
                    'per_page' => self::FETCH_PER_PAGE,
                ]);

                continue;
            }

            $merged = [...$merged, ...$pageData];
        }

        return $merged;
    }
}
