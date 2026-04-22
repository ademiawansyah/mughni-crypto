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
 *   1. Fetch time-series price data from CoinGeckoService (market_chart endpoint).
 *   2. Persist the full raw API response to `market_raw` (one row per coin per call).
 *   3. Validate that enough price data exists for indicator calculation (>= 21 points).
 *   4. Calculate RSI, EMA9, EMA21, and trend via IndicatorService.
 *   5. Persist the computed indicators to `market_indicators` (one row per coin).
 *
 * Raw storage always happens before processed storage.
 * A failure for one coin must not abort processing of the remaining coins.
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
     * @param  array<string>  $coins  CoinGecko coin IDs (e.g. ['bitcoin', 'ethereum'])
     * @param  string  $timeframe  The scheduler timeframe that triggered this cycle (e.g. '5m').
     * @param  string  $executionId  Pipeline execution identifier for traceability.
     */
    public function ingest(array $coins, string $timeframe = '5m', string $executionId = ''): void
    {
        foreach ($coins as $coin) {
            try {
                $this->ingestCoin($coin, $timeframe, $executionId);
            } catch (Throwable $e) {
                Log::error('[MarketDataService] Failed to ingest coin', [
                    'execution_id' => $executionId,
                    'coin' => $coin,
                    'timeframe' => $timeframe,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Run the full ingestion pipeline for a single coin.
     *
     * Fetches market chart data, stores raw response, validates price count,
     * calculates indicators, and persists the resulting indicator record.
     *
     * @param  string  $coin  CoinGecko coin ID.
     * @param  string  $timeframe  Active scheduler timeframe.
     * @param  string  $executionId  Pipeline execution identifier for traceability.
     */
    private function ingestCoin(string $coin, string $timeframe, string $executionId): void
    {
        $payload = $this->coinGeckoService->fetchMarketChart($coin);

        if ($payload === null) {
            Log::error('[MarketDataService] Ingestion aborted — no data returned for coin', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return;
        }

        $timestamp = Carbon::now();

        $this->storeRaw($coin, $timestamp, $payload['request_params'], $payload['raw_response'], $executionId);

        $prices = $payload['prices'];

        if (count($prices) < self::MIN_PRICES) {
            Log::warning('[MarketDataService] Not enough price data for indicator calculation — skipping', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $timeframe,
                'count' => count($prices),
                'required' => self::MIN_PRICES,
            ]);

            return;
        }

        $indicators = $this->indicatorService->calculateFromPrices($prices);

        if ($indicators === null) {
            Log::warning('[MarketDataService] Indicator calculation returned null — skipping', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return;
        }

        $this->storeIndicator($coin, $timeframe, $timestamp, $indicators, $executionId);
    }

    /**
     * Persist the raw CoinGecko API response for one coin request.
     *
     * @param  string  $coin  The coin ID.
     * @param  Carbon  $timestamp  Normalized timestamp for this ingestion cycle.
     * @param  array<string, mixed>  $requestParams  Params that were sent to the API.
     * @param  array<string, mixed>  $rawResponse  Full JSON body returned by the API.
     * @param  string  $executionId  Pipeline execution identifier for traceability.
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
     * Persist computed indicator data for a single coin to `market_indicators`.
     *
     * All indicator fields are populated from the calculated values. Volume is
     * intentionally excluded as it is not used in AI signal generation.
     *
     * @param  string  $coin  CoinGecko coin ID.
     * @param  string  $timeframe  Active scheduler timeframe.
     * @param  Carbon  $timestamp  Timestamp for this ingestion cycle.
     * @param  array{price: float, rsi: float, ema9: float, ema21: float, trend: string}  $indicators  Calculated indicator values.
     * @param  string  $executionId  Pipeline execution identifier for traceability.
     */
    private function storeIndicator(string $coin, string $timeframe, Carbon $timestamp, array $indicators, string $executionId): void
    {
        $record = new MarketIndicator;
        $record->execution_id = $executionId;
        $record->coin = $coin;
        $record->timeframe = $timeframe;
        $record->timestamp = $timestamp;
        $record->price = $indicators['price'];
        $record->rsi = $indicators['rsi'];
        $record->ema9 = $indicators['ema9'];
        $record->ema21 = $indicators['ema21'];
        $record->trend = $indicators['trend'];
        $record->source = 'coingecko';
        $record->save();

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
