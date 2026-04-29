<?php

namespace App\Services\Market;

use App\Models\MarketIndicator;
use App\Models\MarketRaw;
use App\Services\External\BinanceFuturesService;
use App\Services\External\CoinGeckoService;
use App\Services\Indicator\IndicatorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MarketDataService
 *
 * Orchestrates the market data ingestion pipeline per coin:
 *   1. Fetch one market_chart dataset (days=1) from CoinGeckoService.
 *   2. Persist the full raw API response to `market_raw`.
 *   3. Build role timeframe close series from the same base dataset:
 *      - 5m  => base close series
 *      - 15m => aggregate factor 3
 *      - 30m => aggregate factor 6
 *      - 60m => aggregate factor 12
 *   4. Calculate RSI, EMA9, EMA21, and trend per timeframe.
 *   5. Persist computed indicators to `market_indicators`.
 */
class MarketDataService
{
    /** Minimum number of price data points required to compute indicators. */
    private const MIN_PRICES = 21;

    public function __construct(
        private readonly CoinGeckoService $coinGeckoService,
        private readonly BinanceFuturesService $binanceFuturesService,
        private readonly CoinUniverseService $coinUniverseService,
        private readonly IndicatorService $indicatorService,
    ) {}

    /**
     * Run a full ingestion cycle for the given list of coins.
     *
     * @param  array<string>  $coins
     * @param  array<string>  $timeframes  Dynamic timeframe list from configuration.
     * @param  string  $executionId  Pipeline execution identifier for traceability.
     */
    public function ingest(array $coins, array $timeframes, string $executionId = ''): void
    {
        $sortedTimeframes = $this->sortTimeframes($timeframes);

        if (count($sortedTimeframes) === 0) {
            Log::warning('[MarketDataService] Ingestion aborted — no timeframe configured', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        foreach ($coins as $coin) {
            try {
                $this->ingestCoin($coin, $sortedTimeframes, $executionId);
            } catch (Throwable $e) {
                Log::error('[MarketDataService] Failed to ingest coin', [
                    'execution_id' => $executionId,
                    'coin' => $coin,
                    'timeframes' => $sortedTimeframes,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Run the full ingestion pipeline for a single coin.
     *
     * @param  array<string>  $timeframes
     */
    private function ingestCoin(string $coin, array $timeframes, string $executionId): void
    {
        $payload = $this->coinGeckoService->fetchMarketChart($coin);

        if ($payload === null) {
            Log::error('[MarketDataService] Ingestion aborted — no data returned for coin', [
                'execution_id' => $executionId,
                'coin' => $coin,
            ]);

            return;
        }

        $timestamp = Carbon::now();

        $this->storeRaw(
            coin: $coin,
            endpoint: 'market_chart',
            source: 'coingecko',
            timestamp: $timestamp,
            requestParams: $payload['request_params'],
            rawResponse: $payload['raw_response'],
            executionId: $executionId,
        );

        $derivatives = $this->fetchDerivativesSnapshot($coin, $executionId);

        /** @var array<int, float> $prices */
        $prices = $payload['prices'];

        if (count($prices) < self::MIN_PRICES) {
            Log::warning('[MarketDataService] Not enough price data for indicator calculation — skipping', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'count' => count($prices),
                'required' => self::MIN_PRICES,
            ]);

            return;
        }

        $seriesByTimeframe = $this->buildSeriesByTimeframe($prices, $timeframes);

        Log::info('[MarketDataService] MTF candle series prepared', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'counts' => array_map(static fn (array $series): int => count($series), $seriesByTimeframe),
        ]);

        foreach ($seriesByTimeframe as $derivedTimeframe => $series) {
            if (count($series) < self::MIN_PRICES) {
                Log::warning('[MarketDataService] Not enough derived data for timeframe — skipping', [
                    'execution_id' => $executionId,
                    'coin' => $coin,
                    'timeframe' => $derivedTimeframe,
                    'count' => count($series),
                    'required' => self::MIN_PRICES,
                ]);

                continue;
            }

            $indicators = $this->indicatorService->calculateFromPrices($series);

            if ($indicators === null) {
                Log::warning('[MarketDataService] Indicator calculation returned null — skipping timeframe', [
                    'execution_id' => $executionId,
                    'coin' => $coin,
                    'timeframe' => $derivedTimeframe,
                ]);

                continue;
            }

            $this->storeIndicator($coin, $derivedTimeframe, $timestamp, $indicators, $derivatives, $executionId);
        }
    }

    /**
     * Build close-price series for all MTF roles from one base dataset.
     *
     * @param  array<int, float>  $basePrices
     * @param  array<string>  $timeframes
     * @return array<string, array<int, float>>
     */
    private function buildSeriesByTimeframe(array $basePrices, array $timeframes): array
    {
        $sortedTimeframes = $this->sortTimeframes($timeframes);

        if ($sortedTimeframes === []) {
            return [];
        }

        $baseTimeframe = $sortedTimeframes[0];
        $baseMinutes = $this->timeframeToMinutes($baseTimeframe);

        $series = [];

        foreach ($sortedTimeframes as $timeframe) {
            $targetMinutes = $this->timeframeToMinutes($timeframe);

            if ($targetMinutes === PHP_INT_MAX || $baseMinutes === PHP_INT_MAX || $targetMinutes < $baseMinutes) {
                Log::warning('[MarketDataService] Unsupported timeframe for aggregation — skipping', [
                    'timeframe' => $timeframe,
                    'base_timeframe' => $baseTimeframe,
                ]);

                continue;
            }

            if ($targetMinutes % $baseMinutes !== 0) {
                Log::warning('[MarketDataService] Timeframe not divisible by base timeframe — skipping', [
                    'timeframe' => $timeframe,
                    'base_timeframe' => $baseTimeframe,
                ]);

                continue;
            }

            $factor = (int) ($targetMinutes / $baseMinutes);

            if ($factor <= 1) {
                $series[$timeframe] = array_values($basePrices);

                continue;
            }

            $series[$timeframe] = $this->aggregateByFactor($basePrices, $factor);
        }

        return $series;
    }

    /**
     * Aggregate a close series by fixed factor using the last price per bucket.
     *
     * @param  array<int, float>  $prices
     * @return array<int, float>
     */
    private function aggregateByFactor(array $prices, int $factor): array
    {
        $chunks = array_chunk(array_values($prices), $factor);
        $aggregated = [];

        foreach ($chunks as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $aggregated[] = (float) end($chunk);
        }

        return $aggregated;
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function sortTimeframes(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn (string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return $unique;
    }

    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        return PHP_INT_MAX;
    }

    /**
     * Persist one raw API payload for observability and replay.
     *
     * @param  array<string, mixed>  $requestParams
     * @param  array<string, mixed>  $rawResponse
     */
    private function storeRaw(
        string $coin,
        string $endpoint,
        string $source,
        Carbon $timestamp,
        array $requestParams,
        array $rawResponse,
        string $executionId,
    ): void {
        $record = new MarketRaw;
        $record->execution_id = $executionId;
        $record->coin = $coin;
        $record->endpoint = $endpoint;
        $record->timestamp = $timestamp;
        $record->request_params = $requestParams;
        $record->response_json = $rawResponse;
        $record->source = $source;
        $record->save();

        Log::info('[MarketDataService] Raw API response stored', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'endpoint' => $endpoint,
            'source' => $source,
            'raw_id' => $record->id,
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * Persist computed indicator data for a single timeframe.
     *
     * @param  array{price: float, rsi: float, ema9: float, ema21: float, trend: string}  $indicators
     * @param  array{open_interest: float|null, funding_rate: float|null, cvd: float|null, cvd_slope: float|null}  $derivatives
     */
    private function storeIndicator(string $coin, string $timeframe, Carbon $timestamp, array $indicators, array $derivatives, string $executionId): void
    {
        $record = MarketIndicator::updateOrCreate(
            [
                'coin' => $coin,
                'timeframe' => $timeframe,
                'timestamp' => $timestamp,
            ],
            [
                'execution_id' => $executionId,
                'price' => $indicators['price'],
                'rsi' => $indicators['rsi'],
                'ema9' => $indicators['ema9'],
                'ema21' => $indicators['ema21'],
                'trend' => $indicators['trend'],
                'open_interest' => $derivatives['open_interest'],
                'funding_rate' => $derivatives['funding_rate'],
                'cvd' => $derivatives['cvd'],
                'cvd_slope' => $derivatives['cvd_slope'],
                'source' => 'coingecko+binance_futures',
            ]
        );

        Log::info('[MarketDataService] Indicator result stored', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'indicator_id' => $record->id,
            'price' => $indicators['price'],
            'rsi' => $indicators['rsi'],
            'ema9' => $indicators['ema9'],
            'ema21' => $indicators['ema21'],
            'trend' => $indicators['trend'],
            'open_interest' => $derivatives['open_interest'],
            'funding_rate' => $derivatives['funding_rate'],
            'cvd' => $derivatives['cvd'],
            'cvd_slope' => $derivatives['cvd_slope'],
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * @return array{open_interest: float|null, funding_rate: float|null, cvd: float|null, cvd_slope: float|null}
     */
    private function fetchDerivativesSnapshot(string $coin, string $executionId): array
    {
        $symbol = $this->resolveBinanceSymbol($coin);

        if ($symbol === null) {
            Log::debug('[MarketDataService] Derivatives skipped: missing futures symbol', [
                'execution_id' => $executionId,
                'coin' => $coin,
            ]);

            return [
                'open_interest' => null,
                'funding_rate' => null,
                'cvd' => null,
                'cvd_slope' => null,
            ];
        }

        $openInterestPayload = $this->binanceFuturesService->fetchOpenInterest($symbol);
        $fundingPayload = $this->binanceFuturesService->fetchLatestFundingRate($symbol);
        $aggTradesPayload = $this->binanceFuturesService->fetchAggTrades($symbol, 200);

        if ($openInterestPayload !== null) {
            $this->storeRaw(
                coin: $coin,
                endpoint: 'fapi_v1_openInterest',
                source: 'binance_futures',
                timestamp: now(),
                requestParams: $openInterestPayload['request_params'],
                rawResponse: $openInterestPayload['raw_response'],
                executionId: $executionId,
            );
        }

        if ($fundingPayload !== null) {
            $this->storeRaw(
                coin: $coin,
                endpoint: 'fapi_v1_fundingRate',
                source: 'binance_futures',
                timestamp: now(),
                requestParams: $fundingPayload['request_params'],
                rawResponse: $fundingPayload['raw_response'],
                executionId: $executionId,
            );
        }

        if ($aggTradesPayload !== null) {
            $this->storeRaw(
                coin: $coin,
                endpoint: 'fapi_v1_aggTrades',
                source: 'binance_futures',
                timestamp: now(),
                requestParams: $aggTradesPayload['request_params'],
                rawResponse: $aggTradesPayload['raw_response'],
                executionId: $executionId,
            );
        }

        $cvd = null;
        $cvdSlope = null;

        if ($aggTradesPayload !== null) {
            $cvdMetrics = $this->binanceFuturesService->calculateCvdMetrics($aggTradesPayload['trades']);
            $cvd = $cvdMetrics['cvd'];
            $cvdSlope = $cvdMetrics['cvd_slope'];
        }

        return [
            'open_interest' => $openInterestPayload['open_interest'] ?? null,
            'funding_rate' => $fundingPayload['funding_rate'] ?? null,
            'cvd' => $cvd,
            'cvd_slope' => $cvdSlope,
        ];
    }

    private function resolveBinanceSymbol(string $coin): ?string
    {
        $universe = $this->coinUniverseService->getCachedUniverse();

        foreach ($universe as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['coin'] ?? null) !== $coin) {
                continue;
            }

            $symbol = strtoupper((string) ($entry['symbol'] ?? ''));

            if ($symbol === '') {
                return null;
            }

            return str_ends_with($symbol, 'USDT') ? $symbol : ($symbol.'USDT');
        }

        return null;
    }
}
