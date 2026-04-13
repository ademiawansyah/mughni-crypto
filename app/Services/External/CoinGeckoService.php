<?php

namespace App\Services\External;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CoinGeckoService
 *
 * Responsible exclusively for communicating with the CoinGecko REST API.
 * It builds the HTTP request, handles failures gracefully, and returns a
 * normalised payload that includes both the raw API response (for audit
 * storage) and structured per-coin data.
 *
 * This class must NOT persist data to the database.
 */
class CoinGeckoService
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    private string $vsCurrency;

    public function __construct()
    {
        $this->baseUrl = config('market.coingecko.base_url');
        $this->apiKey = config('market.coingecko.api_key');
        $this->timeout = config('market.coingecko.timeout');
        $this->vsCurrency = config('market.coingecko.vs_currency');
    }

    /**
     * Fetch live price data for one or more coins from /simple/price.
     *
     * @param  array<string>  $coins  CoinGecko coin IDs (e.g. ['bitcoin', 'ethereum'])
     * @return array{
     *     request_params: array<string, mixed>,
     *     raw_response: array<string, mixed>,
     *     coins: array<int, array{
     *         coin: string,
     *         price: float|null,
     *         volume_24h: float|null,
     *         change_24h: float|null,
     *         timestamp: Carbon,
     *     }>,
     * }|null  Returns null when the API call fails entirely.
     */
    public function fetchPrices(array $coins): ?array
    {
        $params = [
            'ids' => implode(',', $coins),
            'vs_currencies' => $this->vsCurrency,
            'include_24hr_vol' => 'true',
            'include_24hr_change' => 'true',
        ];

        $request = Http::timeout($this->timeout)->baseUrl($this->baseUrl);

        if ($this->apiKey !== null) {
            $request = $request->withHeaders(['x-cg-demo-api-key' => $this->apiKey]);
        }

        try {
            $response = $request->get('/simple/price', $params);
        } catch (ConnectionException $e) {
            Log::error('[CoinGeckoService] Connection failed', [
                'exception' => $e->getMessage(),
                'coins' => $coins,
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::error('[CoinGeckoService] HTTP request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'coins' => $coins,
            ]);

            return null;
        }

        $rawResponse = $response->json();
        $timestamp = Carbon::now();
        $structured = [];

        foreach ($coins as $coin) {
            if (! isset($rawResponse[$coin])) {
                Log::warning('[CoinGeckoService] Coin missing from API response', ['coin' => $coin]);

                continue;
            }

            $coinData = $rawResponse[$coin];

            $structured[] = [
                'coin' => $coin,
                'price' => $coinData[$this->vsCurrency] ?? null,
                'volume_24h' => $coinData["{$this->vsCurrency}_24h_vol"] ?? null,
                'change_24h' => $coinData["{$this->vsCurrency}_24h_change"] ?? null,
                'timestamp' => $timestamp,
            ];
        }

        return [
            'request_params' => $params,
            'raw_response' => $rawResponse,
            'coins' => $structured,
        ];
    }
}
