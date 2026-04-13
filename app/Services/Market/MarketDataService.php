<?php

namespace App\Services\Market;

use App\Models\MarketIndicator;
use App\Models\MarketRaw;
use App\Services\External\CoinGeckoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MarketDataService
 *
 * Orchestrates the market data ingestion pipeline:
 *   1. Fetch fresh price data from CoinGeckoService.
 *   2. Persist the full raw API response to `market_raw` (one row per API call).
 *   3. Persist normalised market data to `market_indicators` (one row per coin).
 *
 * Raw storage always happens before processed storage.
 * A failure for one coin must not abort processing of the remaining coins.
 * No indicator calculations (RSI, EMA) are performed here.
 */
class MarketDataService
{
    public function __construct(
        private readonly CoinGeckoService $coinGeckoService,
    ) {}

    /**
     * Run a full ingestion cycle for the given list of coins.
     *
     * @param  array<string>  $coins  CoinGecko coin IDs (e.g. ['bitcoin', 'ethereum'])
     */
    public function ingest(array $coins): void
    {
        $payload = $this->coinGeckoService->fetchPrices($coins);

        if ($payload === null) {
            Log::error('[MarketDataService] Ingestion aborted — CoinGeckoService returned no data.', [
                'coins' => $coins,
            ]);

            return;
        }

        $rawTimestamp = isset($payload['coins'][0]['timestamp'])
            ? $payload['coins'][0]['timestamp']
            : Carbon::now();

        $this->storeRaw(
            implode(',', $coins),
            $rawTimestamp,
            $payload['request_params'],
            $payload['raw_response'],
        );

        foreach ($payload['coins'] as $coinData) {
            try {
                $this->storeIndicator($coinData);
            } catch (Throwable $e) {
                // Log and continue — a single-coin failure must not stop the rest.
                Log::error('[MarketDataService] Failed to persist data for coin', [
                    'coin' => $coinData['coin'],
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Persist the raw CoinGecko API response for one API request.
     *
     * Stores the full response JSON alongside the request parameters so that
     * every ingestion cycle is fully auditable with no data loss.
     *
     * @param  string  $coins  Comma-separated list of requested coins.
     * @param  Carbon  $timestamp  Normalized timestamp for this ingestion cycle.
     * @param  array<string, mixed>  $requestParams  Params that were sent to the API.
     * @param  array<string, mixed>  $rawResponse  Full JSON body returned by the API.
     */
    private function storeRaw(string $coins, Carbon $timestamp, array $requestParams, array $rawResponse): void
    {
        $record = new MarketRaw;
        $record->coin = $coins;
        $record->endpoint = 'simple_price';
        $record->timestamp = $timestamp;
        $record->request_params = $requestParams;
        $record->response_json = $rawResponse;
        $record->source = 'coingecko';
        $record->save();

        Log::info('[MarketDataService] Raw data stored', [
            'coin' => $coins,
            'raw_id' => $record->id,
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * Persist normalised market data for a single coin to `market_indicators`.
     *
     * Indicator fields (RSI, EMA, trend) are intentionally left at their zero/
     * neutral defaults; they will be populated by IndicatorService in a later phase.
     *
     * @param  array<string, mixed>  $coinData  Structured single-coin data from CoinGeckoService.
     */
    private function storeIndicator(array $coinData): void
    {
        /** @var Carbon $timestamp */
        $timestamp = $coinData['timestamp'];

        $record = new MarketIndicator;
        $record->coin = $coinData['coin'];
        $record->timeframe = '5m';
        $record->timestamp = $timestamp;
        $record->price = $coinData['price'] ?? 0.0;
        $record->volume = $coinData['volume_24h'] ?? 0.0;
        $record->source = 'coingecko';

        // Indicator fields — not yet calculated; defaults satisfy NOT NULL constraints.
        $record->rsi = 0.0;
        $record->ema9 = 0.0;
        $record->ema21 = 0.0;
        $record->trend = 'pending';

        $record->save();

        Log::info('[MarketDataService] Indicator record stored', [
            'coin' => $coinData['coin'],
            'indicator_id' => $record->id,
            'price' => $coinData['price'],
            'volume_24h' => $coinData['volume_24h'],
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }
}
