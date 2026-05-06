<?php

namespace App\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceService
{
    private string $baseUrl;

    private ?string $apiKey;

    private ?string $apiSecret;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('market.binance.base_url');
        $this->apiKey = config('market.binance.api_key');
        $this->apiSecret = config('market.binance.api_secret');
        $this->timeout = config('market.binance.timeout');
    }

    public function getOhlcvDataForCoin(string $symbol, string $interval, int $limit = 20): array
    {
        $endpoint = '/klines';
        $parameters = [
            'symbol' => strtoupper($symbol) . 'USDT',
            'interval' => $interval,
            'limit' => $limit,
        ];

        try {
            Log::info('[BinanceService] Sending request to /klines', [
                'parameters' => $parameters,
            ]);
            $response = Http::timeout($this->timeout)->baseUrl($this->baseUrl)->get($endpoint, $parameters);
        } catch (ConnectionException $e) {
            Log::error('[BinanceService] Connection failed on /klines', [
                'parameters' => $parameters,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            Log::error('[BinanceService] HTTP request failed on /klines', [
                'status' => $response->status(),
                'error' => $response->body(),
                'parameters' => $parameters,
            ]);

            return [];
        }

        /** @var array<int, array<int, mixed>> $data */
        $data = $response->json() ?? [];

        return $data;
    }
}
