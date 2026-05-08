<?php

namespace App\Services\Market;

use App\Models\Coin;
use App\Models\CoinMarketData;
use App\Services\External\BinanceFuturesService;
use App\Services\External\BinanceService;
use App\Services\External\CoinalyzeService;
use Illuminate\Support\Facades\Log;

class MarketRegimeService
{
    private const OHLCV_CACHE_TTL_SECONDS = 300; // 5 minutes

    private const FUTURES_CACHE_TTL_SECONDS = 120;

    public function __construct(
        private readonly BinanceService $binanceService,
        private readonly BinanceFuturesService $binanceFuturesService,
        private readonly CoinalyzeService $coinalyzeService,
    ) {}

    /**
     * Get OHLCV data for a given coin and timeframe.
     *
     * @param  string  $symbol  The symbol of the coin (e.g., 'bitcoin').
     * @param  string  $interval  The timeframe interval (e.g., '5m', '15m').
     * @param  int  $limit  The number of data points to retrieve.
     * @return array An array of OHLCV data points.
     */
    public function getOhlcvDataForCoin(string $symbol, string $interval, int $limit = 20): array
    {
        $normalizedSymbol = strtolower($symbol);
        $cacheKey = sprintf('market_regime:ohlcv:%s:%s:%d', $normalizedSymbol, $interval, $limit);
        $cachedData = cache()->get($cacheKey);

        if ($cachedData) {
            Log::info("[MarketRegimeService] Returning cached OHLCV data for {$symbol} at interval {$interval}");

            return $cachedData;
        }

        $ohlcvData = $this->binanceService->getOhlcvDataForCoin($normalizedSymbol, $interval, $limit);

        if (empty($ohlcvData)) {
            Log::warning("[MarketRegimeService] No OHLCV data retrieved for {$symbol} at interval {$interval}");

            return [];
        }

        cache()->put($cacheKey, $ohlcvData, self::OHLCV_CACHE_TTL_SECONDS);

        $this->persistMarketData(
            symbol: $normalizedSymbol,
            dataType: 'ohlcv',
            source: 'binance',
            interval: $interval,
            payload: $ohlcvData,
        );

        return $ohlcvData;
    }

    /**
     * @return array<int, array{sumOpenInterest: float, timestamp: int}>|null
     */
    public function getOpenInterestHistoryForCoin(string $symbol, string $period = '1h', int $limit = 5): ?array
    {
        $normalizedSymbol = strtoupper($symbol);
        $cacheKey = sprintf('market_regime:futures:oi_history:%s:%s:%d', $normalizedSymbol, $period, $limit);
        $cachedData = cache()->get($cacheKey);

        if (is_array($cachedData)) {
            return $cachedData;
        }

        $history = $this->binanceFuturesService->fetchOpenInterestHistory(
            symbol: $normalizedSymbol,
            period: $period,
            limit: $limit,
        );

        if ($history === null || $history === []) {
            return null;
        }

        cache()->put($cacheKey, $history, $this->getFuturesCacheTtlSeconds());

        $this->persistMarketData(
            symbol: $normalizedSymbol,
            dataType: 'oi_history',
            source: 'binance_futures',
            interval: $period,
            payload: $history,
        );

        return $history;
    }

    /**
     * @return array{funding_rate: float, request_params: array<string, mixed>, raw_response: array<string, mixed>}|null
     */
    public function getLatestFundingRateForCoin(string $symbol): ?array
    {
        $normalizedSymbol = strtoupper($symbol);
        $cacheKey = sprintf('market_regime:futures:funding_rate:%s', $normalizedSymbol);
        $cachedData = cache()->get($cacheKey);

        if (is_array($cachedData)) {
            return $cachedData;
        }

        $funding = $this->binanceFuturesService->fetchLatestFundingRate($normalizedSymbol);

        if ($funding === null) {
            return null;
        }

        cache()->put($cacheKey, $funding, $this->getFuturesCacheTtlSeconds());

        $this->persistMarketData(
            symbol: $normalizedSymbol,
            dataType: 'funding_rate',
            source: 'binance_futures',
            interval: 'latest',
            payload: $funding,
        );

        return $funding;
    }

    /**
     * @return array<int, array{timestamp: int, open_interest: float}>
     */
    public function getCounterTrendOpenInterestHistoryForCoin(string $symbol, string $interval = '1hour', int $limit = 24): array
    {
        $normalizedSymbol = strtoupper($symbol);
        $safeLimit = max(2, min($limit, 200));
        $cacheKey = sprintf('market_regime:coinalyze:oi_history:%s:%s:%d', $normalizedSymbol, $interval, $safeLimit);
        $cachedData = cache()->get($cacheKey);

        if (is_array($cachedData)) {
            return $cachedData;
        }

        $history = $this->coinalyzeService->fetchOpenInterestHistory(
            binanceSymbol: $normalizedSymbol,
            interval: $interval,
            limit: $safeLimit,
        );

        cache()->put($cacheKey, $history, $this->getFuturesCacheTtlSeconds());

        if ($history !== []) {
            $this->persistMarketData(
                symbol: $normalizedSymbol,
                dataType: 'oi_history',
                source: 'coinalyze',
                interval: $interval,
                payload: $history,
            );
        }

        return $history;
    }

    /**
     * @return array<int, array{timestamp: int, funding_rate: float}>
     */
    public function getCounterTrendFundingRateHistoryForCoin(string $symbol, int $limit = 10): array
    {
        $normalizedSymbol = strtoupper($symbol);
        $safeLimit = max(1, min($limit, 120));
        $cacheKey = sprintf('market_regime:coinalyze:funding_rate:%s:%d', $normalizedSymbol, $safeLimit);
        $cachedData = cache()->get($cacheKey);

        if (is_array($cachedData)) {
            return $cachedData;
        }

        $funding = $this->coinalyzeService->fetchFundingRateHistory(
            binanceSymbol: $normalizedSymbol,
            limit: $safeLimit,
        );

        cache()->put($cacheKey, $funding, $this->getFuturesCacheTtlSeconds());

        if ($funding !== []) {
            $this->persistMarketData(
                symbol: $normalizedSymbol,
                dataType: 'funding_rate',
                source: 'coinalyze',
                interval: 'daily',
                payload: $funding,
            );
        }

        return $funding;
    }

    /**
     * @return array{trades: array<int, array<string, mixed>>, request_params: array<string, mixed>, raw_response: array<string, mixed>}|null
     */
    public function getAggTradesForCoin(string $symbol, int $limit = 200): ?array
    {
        $normalizedSymbol = strtoupper($symbol);
        $safeLimit = max(20, min($limit, 1000));
        $cacheKey = sprintf('market_regime:futures:agg_trades:%s:%d', $normalizedSymbol, $safeLimit);
        $cachedData = cache()->get($cacheKey);

        if (is_array($cachedData)) {
            return $cachedData;
        }

        $trades = $this->binanceFuturesService->fetchAggTrades(
            symbol: $normalizedSymbol,
            limit: $safeLimit,
        );

        if ($trades === null || ($trades['trades'] ?? []) === []) {
            return null;
        }

        cache()->put($cacheKey, $trades, $this->getFuturesCacheTtlSeconds());

        $this->persistMarketData(
            symbol: $normalizedSymbol,
            dataType: 'agg_trades',
            source: 'binance_futures',
            interval: sprintf('limit_%d', $safeLimit),
            payload: $trades,
        );

        return $trades;
    }

    /**
     * @return array{cvd: float, cvd_slope: float}|null
     */
    public function getCvdMetricsForCoin(string $symbol, int $limit = 1000): ?array
    {
        $normalizedSymbol = strtoupper($symbol);
        $safeLimit = max(20, min($limit, 1000));
        $cacheKey = sprintf('market_regime:futures:cvd_metrics:%s:%d', $normalizedSymbol, $safeLimit);
        $cachedData = cache()->get($cacheKey);

        if (is_array($cachedData)) {
            return $cachedData;
        }

        $tradesPayload = $this->getAggTradesForCoin($normalizedSymbol, $safeLimit);

        if ($tradesPayload === null || ($tradesPayload['trades'] ?? []) === []) {
            return null;
        }

        $cvdMetrics = $this->binanceFuturesService->calculateCvdMetrics($tradesPayload['trades']);

        cache()->put($cacheKey, $cvdMetrics, $this->getFuturesCacheTtlSeconds());

        $this->persistMarketData(
            symbol: $normalizedSymbol,
            dataType: 'cvd_metrics',
            source: 'binance_futures',
            interval: sprintf('limit_%d', $safeLimit),
            payload: $cvdMetrics,
        );

        return $cvdMetrics;
    }

    private function getFuturesCacheTtlSeconds(): int
    {
        return max(1, (int) config('market.binance_futures.cache_ttl_seconds', self::FUTURES_CACHE_TTL_SECONDS));
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     */
    private function persistMarketData(string $symbol, string $dataType, string $source, string $interval, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $coin = $this->resolveCoinBySymbol($symbol);

        if ($coin === null) {
            return;
        }

        CoinMarketData::query()->updateOrCreate(
            [
                'coin_id' => $coin->id,
                'data_type' => $dataType,
                'source' => $source,
                'interval' => $interval,
            ],
            [
                'data' => $payload,
            ],
        );
    }

    private function resolveCoinBySymbol(string $symbol): ?Coin
    {
        $normalized = strtolower($symbol);

        if (str_ends_with($normalized, 'usdt')) {
            $normalized = substr($normalized, 0, -4);
        }

        return Coin::query()
            ->whereRaw('LOWER(symbol) = ?', [$normalized])
            ->first();
    }
}
