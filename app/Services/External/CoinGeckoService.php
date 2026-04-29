<?php

namespace App\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CoinGeckoService
 *
 * Responsible exclusively for communicating with the CoinGecko REST API.
 * It builds the HTTP request, handles failures gracefully, and returns a
 * normalised payload that includes both the raw API response (for audit
 * storage) and a flat array of time-series prices.
 *
 * Responses are cached for 5 minutes per coin to prevent redundant API calls.
 * This class must NOT persist data to the database.
 */
class CoinGeckoService
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    private string $vsCurrency;

    /** Cache TTL in minutes for market chart responses. */
    private const CACHE_TTL_MINUTES = 2;

    public function __construct()
    {
        $this->baseUrl = config('market.coingecko.base_url');
        $this->apiKey = config('market.coingecko.api_key');
        $this->timeout = config('market.coingecko.timeout');
        $this->vsCurrency = config('market.coingecko.vs_currency');
    }

    /**
     * Fetch time-series price data for a single coin from /coins/{id}/market_chart.
     *
     * The response is cached for 5 minutes to avoid repeated API calls within
     * the same processing cycle. Only successful responses are cached.
     *
     * @param  string  $coin  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  int  $days  Number of days of historical data to fetch
     * @return array{
     *     request_params: array<string, mixed>,
     *     raw_response: array<string, mixed>,
     *     prices: array<int, float>,
     * }|null  Returns null when the API call fails entirely.
     */
    public function fetchMarketChart(string $coin, int $days = 1): ?array
    {
        $cacheKey = "coingecko_market_chart_{$coin}_{$days}_{$this->vsCurrency}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->doFetchMarketChart($coin, $days);

        if ($result !== null) {
            Cache::put($cacheKey, $result, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }

        return $result;
    }

    /**
     * Fetch paginated market data from /coins/markets.
     *
     * Returns a flat list of coin entries per page, or an empty array on failure.
     * Each entry includes: id, market_cap, total_volume, current_price, symbol.
     *
     * @param  int  $page  Page number (1-indexed)
     * @param  int  $perPage  Results per page (max 250)
     * @return array<int, array{id: string, symbol: string, market_cap: float|null, total_volume: float|null, current_price: float|null}>
     */
    public function fetchCoinMarkets(int $page = 1, int $perPage = 250): array
    {
        $request = Http::timeout($this->timeout)->baseUrl($this->baseUrl);

        if ($this->apiKey !== null) {
            $request = $request->withHeaders(['x-cg-demo-api-key' => $this->apiKey]);
        }

        $parameters = [
            'vs_currency' => $this->vsCurrency,
            'order' => 'market_cap_desc',
            'per_page' => $perPage,
            'page' => $page,
            'sparkline' => 'false',
        ];

        try {
            $response = $request->get('/coins/markets', $parameters);
        } catch (ConnectionException $e) {
            Log::error('[CoinGeckoService] Connection failed on /coins/markets', [
                'parameters' => $parameters,
                'exception' => $e->getMessage(),
                'page' => $page,
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::error('[CoinGeckoService] HTTP request failed on /coins/markets', [
                'status' => $response->status(),
                'error' => $response->body(),
                'parameters' => $parameters,
            ]);

            return [];
        }

        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json() ?? [];

        return array_map(fn (array $coin): array => [
            'id' => (string) ($coin['id'] ?? ''),
            'symbol' => strtoupper((string) ($coin['symbol'] ?? '')),
            'market_cap' => is_numeric($coin['market_cap'] ?? null) ? (float) $coin['market_cap'] : null,
            'total_volume' => is_numeric($coin['total_volume'] ?? null) ? (float) $coin['total_volume'] : null,
            'current_price' => is_numeric($coin['current_price'] ?? null) ? (float) $coin['current_price'] : null,
        ], $data);
    }

    /**
     * Perform the actual HTTP request to /coins/{id}/market_chart.
     *
     * @param  string  $coin  CoinGecko coin ID
     * @param  int  $days  Number of days of data
     * @return array{
     *     request_params: array<string, mixed>,
     *     raw_response: array<string, mixed>,
     *     prices: array<int, float>,
     * }|null
     */
    private function doFetchMarketChart(string $coin, int $days): ?array
    {
        $params = [
            'vs_currency' => $this->vsCurrency,
            'days' => $days,
        ];

        $request = Http::timeout($this->timeout)->baseUrl($this->baseUrl);

        if ($this->apiKey !== null) {
            $request = $request->withHeaders(['x-cg-demo-api-key' => $this->apiKey]);
        }

        try {
            $response = $request->get("/coins/{$coin}/market_chart", $params);
        } catch (ConnectionException $e) {
            Log::error('[CoinGeckoService] Connection failed', [
                'exception' => $e->getMessage(),
                'coin' => $coin,
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::error('[CoinGeckoService] HTTP request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'coin' => $coin,
            ]);

            return null;
        }

        $rawResponse = $response->json();

        // Extract only the price values from the [timestamp, price] pairs.
        $prices = array_map(fn ($item) => (float) $item[1], $rawResponse['prices'] ?? []);

        return [
            'request_params' => array_merge(['coin' => $coin], $params),
            'raw_response' => $rawResponse,
            'prices' => $prices,
        ];
    }
}
