<?php

namespace App\Services\Market;

use App\Models\MarketIndicator;
use App\Models\MarketRaw;
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
            timestamp: $timestamp,
            requestParams: $payload['request_params'],
            rawResponse: $payload['raw_response'],
            executionId: $executionId,
        );

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
            'counts' => array_map(static fn(array $series): int => count($series), $seriesByTimeframe),
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

            $this->storeIndicator($coin, $derivedTimeframe, $timestamp, $indicators, $executionId);
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

        usort($unique, fn(string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

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
     * Persist the raw CoinGecko API response for one coin request.
     *
     * @param  array<string, mixed>  $requestParams
     * @param  array<string, mixed>  $rawResponse
     */
    private function storeRaw(string $coin, Carbon $timestamp, array $requestParams, array $rawResponse, string $executionId): void
    {
        $record = new MarketRaw;
        $record->execution_id = $executionId;
        $record->coin = $coin;
        $record->endpoint = 'market_chart';
        $record->timestamp = $timestamp;
        $record->request_params = $requestParams;
        $record->response_json = $rawResponse;
        $record->source = 'coingecko';
        $record->save();

        Log::info('[MarketDataService] Raw API response stored', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'raw_id' => $record->id,
            'request_params' => $requestParams,
            'raw_response' => $rawResponse,
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * Persist computed indicator data for a single timeframe.
     *
     * @param  array{price: float, rsi: float, ema9: float, ema21: float, trend: string}  $indicators
     */
    private function storeIndicator(string $coin, string $timeframe, Carbon $timestamp, array $indicators, string $executionId): void
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
                'source' => 'coingecko',
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
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }
}
