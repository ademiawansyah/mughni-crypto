<?php

namespace App\Services\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceFuturesService
{
    private bool $enabled;

    private string $baseUrl;

    private int $timeout;

    private int $cacheTtlSeconds;

    public function __construct()
    {
        $this->enabled = (bool) config('market.binance_futures.enabled', true);
        $this->baseUrl = (string) config('market.binance_futures.base_url', 'https://fapi.binance.com/fapi/v1');
        $this->timeout = (int) config('market.binance_futures.timeout', 10);
        $this->cacheTtlSeconds = (int) config('market.binance_futures.cache_ttl_seconds', 120);
    }

    /**
     * @return array{open_interest: float, request_params: array<string, mixed>, raw_response: array<string, mixed>}|null
     */
    public function fetchOpenInterest(string $symbol): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $cacheKey = sprintf('binance:futures:oi:%s', strtoupper($symbol));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = ['symbol' => strtoupper($symbol)];
        $response = $this->sendGet('/openInterest', $params);

        if ($response === null) {
            return null;
        }

        $openInterest = is_numeric($response['openInterest'] ?? null)
            ? (float) $response['openInterest']
            : null;

        if ($openInterest === null) {
            return null;
        }

        $result = [
            'open_interest' => $openInterest,
            'request_params' => $params,
            'raw_response' => $response,
        ];

        Cache::put($cacheKey, $result, now()->addSeconds($this->cacheTtlSeconds));

        return $result;
    }

    public function fetchOpenInterestUsd(string $symbol, ?float $fallbackPrice = null): ?float
    {
        $openInterestPayload = $this->fetchOpenInterest($symbol);

        if ($openInterestPayload === null) {
            return null;
        }

        $markPricePayload = $this->fetchMarkPrice($symbol);
        $markPrice = $markPricePayload['mark_price'] ?? $fallbackPrice;

        if ($markPrice === null || $markPrice <= 0.0) {
            return null;
        }

        return $openInterestPayload['open_interest'] * $markPrice;
    }

    /**
     * @return array<int, array<int, mixed>>|null
     */
    public function fetchKlines(string $symbol, string $interval, int $limit = 100): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $safeLimit = max(1, min($limit, 1500));
        $normalizedSymbol = strtoupper($symbol);
        $cacheKey = sprintf('binance:futures:klines:%s:%s:%d', $normalizedSymbol, strtolower($interval), $safeLimit);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [
            'symbol' => $normalizedSymbol,
            'interval' => $interval,
            'limit' => $safeLimit,
        ];

        $response = $this->sendGet('/klines', $params);

        if (! is_array($response) || $response === []) {
            return null;
        }

        /** @var array<int, array<int, mixed>> $klines */
        $klines = $response;

        Cache::put($cacheKey, $klines, now()->addSeconds($this->cacheTtlSeconds));

        return $klines;
    }

    /**
     * @return array{funding_rate: float, request_params: array<string, mixed>, raw_response: array<string, mixed>}|null
     */
    public function fetchLatestFundingRate(string $symbol): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $cacheKey = sprintf('binance:futures:funding:%s', strtoupper($symbol));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [
            'symbol' => strtoupper($symbol),
            'limit' => 1,
        ];

        $response = $this->sendGet('/fundingRate', $params);

        if (! is_array($response) || $response === []) {
            return null;
        }

        $latest = $response[0] ?? [];
        $fundingRate = is_numeric($latest['fundingRate'] ?? null)
            ? (float) $latest['fundingRate']
            : null;

        if ($fundingRate === null) {
            return null;
        }

        $result = [
            'funding_rate' => $fundingRate,
            'request_params' => $params,
            'raw_response' => ['rows' => $response],
        ];

        Cache::put($cacheKey, $result, now()->addSeconds($this->cacheTtlSeconds));

        return $result;
    }

    /**
     * Fetch historical open interest snapshots for a perpetual futures symbol.
     *
     * Returns an ordered array of OI records from oldest to newest, each with:
     *   - sumOpenInterest (float): contract units
     *   - timestamp (int): unix ms
     *
     * @param  string  $period  One of: 5m, 15m, 30m, 1h, 2h, 4h, 6h, 12h, 1d
     * @return array<int, array{sumOpenInterest: float, timestamp: int}>|null
     */
    public function fetchOpenInterestHistory(string $symbol, string $period = '1h', int $limit = 5): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $safeLimit = max(2, min($limit, 500));
        $cacheKey = sprintf('binance:futures:oi_hist:%s:%s:%d', strtoupper($symbol), $period, $safeLimit);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [
            'symbol' => strtoupper($symbol),
            'period' => $period,
            'limit' => $safeLimit,
        ];

        $response = $this->sendGet('/openInterestHist', $params);

        if (! is_array($response) || $response === []) {
            return null;
        }

        $records = [];

        foreach ($response as $row) {
            if (! is_array($row) || ! is_numeric($row['sumOpenInterest'] ?? null)) {
                continue;
            }

            $records[] = [
                'sumOpenInterest' => (float) $row['sumOpenInterest'],
                'timestamp' => (int) ($row['timestamp'] ?? 0),
            ];
        }

        if (count($records) < 2) {
            return null;
        }

        // Cache for half the period to stay fresh
        Cache::put($cacheKey, $records, now()->addSeconds((int) ($this->cacheTtlSeconds / 2)));

        return $records;
    }

    /**
     * @return array{trades: array<int, array<string, mixed>>, request_params: array<string, mixed>, raw_response: array<string, mixed>}|null
     */
    public function fetchAggTrades(string $symbol, int $limit = 200): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $safeLimit = max(20, min($limit, 1000));
        $cacheKey = sprintf('binance:futures:agg_trades:%s:%d', strtoupper($symbol), $safeLimit);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [
            'symbol' => strtoupper($symbol),
            'limit' => $safeLimit,
        ];

        $response = $this->sendGet('/aggTrades', $params);

        if (! is_array($response)) {
            return null;
        }

        $result = [
            'trades' => $response,
            'request_params' => $params,
            'raw_response' => ['rows' => $response],
        ];

        Cache::put($cacheKey, $result, now()->addSeconds($this->cacheTtlSeconds));

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $trades
     * @return array{cvd: float, cvd_slope: float}
     */
    public function calculateCvdMetrics(array $trades): array
    {
        if ($trades === []) {
            return ['cvd' => 0.0, 'cvd_slope' => 0.0];
        }

        $cvd = 0.0;
        $points = [];

        foreach (array_values($trades) as $index => $trade) {
            $quantity = is_numeric($trade['q'] ?? null) ? (float) $trade['q'] : 0.0;
            $price = is_numeric($trade['p'] ?? null) ? (float) $trade['p'] : 0.0;
            $isBuyerMaker = (bool) ($trade['m'] ?? false);
            $notional = $quantity * $price;

            if ($notional <= 0.0) {
                continue;
            }

            $cvd += $isBuyerMaker ? -$notional : $notional;
            $points[] = $cvd;
        }

        return [
            'cvd' => $cvd,
            'cvd_slope' => $this->linearRegressionSlope($points),
        ];
    }

    public function hasPerpetualUsdtSymbol(string $symbol): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $map = $this->fetchPerpetualSymbolMap();

        return isset($map[strtoupper($symbol)]);
    }

    /**
     * @return array<string, bool>
     */
    private function fetchPerpetualSymbolMap(): array
    {
        $cacheKey = 'binance:futures:exchange_info:perpetual_symbols';

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey, []);
        }

        $response = $this->sendGet('/exchangeInfo', []);

        if (! is_array($response)) {
            return [];
        }

        $symbols = $response['symbols'] ?? [];
        $map = [];

        foreach ($symbols as $item) {
            if (! is_array($item)) {
                continue;
            }

            $symbol = strtoupper((string) ($item['symbol'] ?? ''));
            $status = strtoupper((string) ($item['status'] ?? ''));
            $contractType = strtoupper((string) ($item['contractType'] ?? ''));
            $quoteAsset = strtoupper((string) ($item['quoteAsset'] ?? ''));

            if ($symbol === '' || $status !== 'TRADING') {
                continue;
            }

            if ($contractType === 'PERPETUAL' && $quoteAsset === 'USDT') {
                $map[$symbol] = true;
            }
        }

        Cache::put($cacheKey, $map, now()->addHours(6));

        return $map;
    }

    /**
     * @return array{mark_price: float, request_params: array<string, mixed>, raw_response: array<string, mixed>}|null
     */
    private function fetchMarkPrice(string $symbol): ?array
    {
        $cacheKey = sprintf('binance:futures:mark_price:%s', strtoupper($symbol));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = ['symbol' => strtoupper($symbol)];
        $response = $this->sendGet('/premiumIndex', $params);

        if (! is_array($response)) {
            return null;
        }

        $markPrice = is_numeric($response['markPrice'] ?? null) ? (float) $response['markPrice'] : null;

        if ($markPrice === null) {
            return null;
        }

        $result = [
            'mark_price' => $markPrice,
            'request_params' => $params,
            'raw_response' => $response,
        ];

        Cache::put($cacheKey, $result, now()->addSeconds($this->cacheTtlSeconds));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|array<int, array<string, mixed>>|null
     */
    private function sendGet(string $endpoint, array $params): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->baseUrl($this->baseUrl)
                ->retry(2, 200, throw: false)
                ->get($endpoint, $params);
        } catch (ConnectionException $exception) {
            Log::warning('[BinanceFuturesService] Connection failed', [
                'endpoint' => $endpoint,
                'params' => $params,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('[BinanceFuturesService] HTTP request failed', [
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

    /**
     * @param  array<int, float>  $points
     */
    private function linearRegressionSlope(array $points): float
    {
        $n = count($points);

        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        foreach (array_values($points) as $index => $y) {
            $x = (float) $index;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);

        if (abs($denominator) < 1e-9) {
            return 0.0;
        }

        return (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
    }
}
