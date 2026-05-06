<?php

namespace App\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CoinMarketCapService
 *
 * Responsible for communicating with CoinMarketCap public listings endpoints.
 * This service only performs HTTP requests and returns normalized arrays.
 */
class CoinMarketCapService
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('market.coinmarketcap.base_url', 'https://pro-api.coinmarketcap.com');
        $this->apiKey = config('market.coinmarketcap.api_key');
        $this->timeout = (int) config('market.coinmarketcap.timeout', 10);
    }

    /**
     * Fetch latest listings sorted by market cap from CoinMarketCap.
     *
     * @return array<int, array{
     *   symbol: string,
     *   name: string,
     *   market_cap: float,
     *   volume_24h: float,
     *   price: float,
     *   percent_change_24h: float
     * }>
     */
    public function fetchListingsLatest(int $limit = 200): array
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            Log::warning('[CoinMarketCapService] Missing API key, skipping CMC request');

            return [];
        }

        $safeLimit = max(1, min($limit, 5000));

        $parameters = [
            'limit' => $safeLimit,
            'sort' => 'market_cap',
            'sort_dir' => 'desc',
            'convert' => 'USD',
        ];

        try {
            Log::info('[CoinMarketCapService] Sending request to /v1/cryptocurrency/listings/latest', [
                'parameters' => $parameters,
            ]);

            $response = Http::timeout($this->timeout)
                ->baseUrl($this->baseUrl)
                ->withHeaders([
                    'X-CMC_PRO_API_KEY' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->get('/v1/cryptocurrency/listings/latest', $parameters);
        } catch (ConnectionException $exception) {
            Log::error('[CoinMarketCapService] Connection failed', [
                'parameters' => $parameters,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::error('[CoinMarketCapService] HTTP request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'parameters' => $parameters,
            ]);

            return [];
        }

        /** @var array<int, array<string, mixed>> $items */
        $items = $response->json('data') ?? [];

        return array_values(array_filter(array_map(static function (array $item): ?array {
            $quote = is_array($item['quote'] ?? null) ? $item['quote'] : [];
            $usd = is_array($quote['USD'] ?? null) ? $quote['USD'] : [];

            if (! isset($item['symbol']) || ! is_string($item['symbol'])) {
                return null;
            }

            return [
                'symbol' => strtoupper($item['symbol']),
                'name' => (string) ($item['name'] ?? ''),
                'market_cap' => (float) ($usd['market_cap'] ?? 0),
                'volume_24h' => (float) ($usd['volume_24h'] ?? 0),
                'price' => (float) ($usd['price'] ?? 0),
                'percent_change_24h' => (float) ($usd['percent_change_24h'] ?? 0),
            ];
        }, $items)));
    }
}
