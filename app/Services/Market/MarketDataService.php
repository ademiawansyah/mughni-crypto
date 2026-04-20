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
     */
    public function ingest(array $coins, string $timeframe = '5m'): void
    {
        foreach ($coins as $coin) {
            try {
                $this->ingestCoin($coin, $timeframe);
            } catch (Throwable $e) {
                Log::error('[MarketDataService] Failed to ingest coin', [
                    'coin' => $coin,
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
     */
    private function ingestCoin(string $coin, string $timeframe): void
    {
        $payload = $this->coinGeckoService->fetchMarketChart($coin);

        if ($payload === null) {
            Log::error('[MarketDataService] Ingestion aborted — no data returned for coin', [
                'coin' => $coin,
            ]);

            return;
        }

        $timestamp = Carbon::now();

        $this->storeRaw($coin, $timestamp, $payload['request_params'], $payload['raw_response']);

        $prices = $payload['prices'];

        if (count($prices) < self::MIN_PRICES) {
            Log::warning('[MarketDataService] Not enough price data for indicator calculation — skipping', [
                'coin' => $coin,
                'count' => count($prices),
                'required' => self::MIN_PRICES,
            ]);

            return;
        }

        $indicators = $this->indicatorService->calculateFromPrices($prices);

        if ($indicators === null) {
            Log::warning('[MarketDataService] Indicator calculation returned null — skipping', [
                'coin' => $coin,
            ]);

            return;
        }

        $this->storeIndicator($coin, $timeframe, $timestamp, $indicators);
    }

    /**
     * Persist the raw CoinGecko API response for one coin request.
     *
     * @param  string  $coin  The coin ID.
     * @param  Carbon  $timestamp  Normalized timestamp for this ingestion cycle.
     * @param  array<string, mixed>  $requestParams  Params that were sent to the API.
     * @param  array<string, mixed>  $rawResponse  Full JSON body returned by the API.
     */
    private function storeRaw(string $coin, Carbon $timestamp, array $requestParams, array $rawResponse): void
    {
        $record = new MarketRaw;
        $record->coin = $coin;
        $record->endpoint = 'market_chart';
        $record->timestamp = $timestamp;
        $record->request_params = $requestParams;
        $record->response_json = $rawResponse;
        $record->source = 'coingecko';
        $record->save();

        Log::info('[MarketDataService] Raw data stored', [
            'coin' => $coin,
            'raw_id' => $record->id,
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
     */
    private function storeIndicator(string $coin, string $timeframe, Carbon $timestamp, array $indicators): void
    {
        $record = new MarketIndicator;
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

        Log::info('[MarketDataService] Indicator record stored', [
            'coin' => $coin,
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
