<?php

namespace App\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoinalyzeService
{
    private bool $enabled;

    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    private int $cacheTtlSeconds;

    public function __construct()
    {
        $this->enabled = (bool) config('market.coinalyze.enabled', true);
        $this->baseUrl = (string) config('market.coinalyze.base_url', 'https://api.coinalyze.net/v1');
        $this->apiKey = config('market.coinalyze.api_key');
        $this->timeout = (int) config('market.coinalyze.timeout', 10);
        $this->cacheTtlSeconds = (int) config('market.coinalyze.cache_ttl_seconds', 120);
    }

    /**
     * @return array<int, array{timestamp: int, open_interest: float}>
     */
    public function fetchOpenInterestHistory(string $binanceSymbol, string $interval = '1hour', int $limit = 24): array
    {
        if (! $this->enabled || $this->apiKey === null || $this->apiKey === '') {
            return [];
        }

        $safeLimit = max(2, min($limit, 200));
        $safeInterval = $this->normalizeOiInterval($interval);
        $symbol = $this->toCoinalyzeSymbol($binanceSymbol);
        $cacheKey = sprintf('coinalyze:oi:%s:%s:%d', $symbol, $safeInterval, $safeLimit);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey, []);
        }

        $intervalSeconds = $this->intervalSeconds($safeInterval);
        $to = now()->timestamp;
        $from = $to - ($intervalSeconds * $safeLimit);

        $response = $this->sendGet('/open-interest-history', [
            'symbols' => $symbol,
            'interval' => $safeInterval,
            'from' => $from,
            'to' => $to,
        ]);

        if (! is_array($response) || $response === []) {
            Cache::put($cacheKey, [], now()->addSeconds($this->cacheTtlSeconds));

            return [];
        }

        $items = [];

        foreach ($response as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (($entry['history'] ?? []) as $point) {
                if (! is_array($point)) {
                    continue;
                }

                if (! is_numeric($point['t'] ?? null) || ! is_numeric($point['c'] ?? null)) {
                    continue;
                }

                $items[] = [
                    'timestamp' => (int) $point['t'],
                    'open_interest' => (float) $point['c'],
                ];
            }
        }

        usort(
            $items,
            static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp'],
        );

        Cache::put($cacheKey, $items, now()->addSeconds($this->cacheTtlSeconds));

        return $items;
    }

    /**
     * @return array<int, array{timestamp: int, funding_rate: float}>
     */
    public function fetchFundingRateHistory(string $binanceSymbol, int $limit = 10): array
    {
        if (! $this->enabled || $this->apiKey === null || $this->apiKey === '') {
            return [];
        }

        $safeLimit = max(1, min($limit, 120));
        $symbol = $this->toCoinalyzeSymbol($binanceSymbol);
        $cacheKey = sprintf('coinalyze:funding:%s:%d', $symbol, $safeLimit);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey, []);
        }

        $to = now()->timestamp;
        $from = $to - (86400 * $safeLimit);

        $response = $this->sendGet('/funding-rate-history', [
            'symbols' => $symbol,
            'interval' => 'daily',
            'from' => $from,
            'to' => $to,
        ]);

        if (! is_array($response) || $response === []) {
            Cache::put($cacheKey, [], now()->addSeconds($this->cacheTtlSeconds));

            return [];
        }

        $items = [];

        foreach ($response as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (($entry['history'] ?? []) as $point) {
                if (! is_array($point)) {
                    continue;
                }

                if (! is_numeric($point['t'] ?? null) || ! is_numeric($point['c'] ?? null)) {
                    continue;
                }

                $items[] = [
                    'timestamp' => (int) $point['t'],
                    'funding_rate' => (float) $point['c'],
                ];
            }
        }

        usort(
            $items,
            static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp'],
        );

        Cache::put($cacheKey, $items, now()->addSeconds($this->cacheTtlSeconds));

        return $items;
    }

    private function toCoinalyzeSymbol(string $binanceSymbol): string
    {
        $upper = strtoupper($binanceSymbol);
        $base = str_ends_with($upper, 'USDT')
            ? substr($upper, 0, -1)
            : $upper;

        return sprintf('%s_PERP.A', $base);
    }

    private function normalizeOiInterval(string $interval): string
    {
        return match (strtolower($interval)) {
            '1min', '5min', '15min', '30min', '1hour', '4hour', 'daily' => strtolower($interval),
            '1h' => '1hour',
            '1d' => 'daily',
            default => '1hour',
        };
    }

    private function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            '1min' => 60,
            '5min' => 300,
            '15min' => 900,
            '30min' => 1800,
            '4hour' => 14400,
            'daily' => 86400,
            default => 3600,
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, mixed>|null
     */
    private function sendGet(string $endpoint, array $params): ?array
    {
        $params['api_key'] = $this->apiKey;

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->baseUrl($this->baseUrl)
                ->retry(2, 200, throw: false)
                ->get($endpoint, $params);
        } catch (ConnectionException $exception) {
            Log::warning('[CoinalyzeService] Connection failed', [
                'endpoint' => $endpoint,
                'params' => $params,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            // Coinalyze returns 400/404 for unsupported symbols; treat as empty dataset.
            if (in_array($response->status(), [400, 404], true)) {
                return [];
            }

            Log::warning('[CoinalyzeService] HTTP request failed', [
                'endpoint' => $endpoint,
                'params' => $params,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
