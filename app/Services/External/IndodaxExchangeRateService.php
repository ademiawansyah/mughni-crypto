<?php

namespace App\Services\External;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches exchange rates from Indodax public API.
 *
 * Indodax provides pair information (e.g., USDT/IDR) via public API endpoints
 * without requiring authentication. This service uses the /api/ticker endpoint
 * to fetch current rates.
 *
 * @link https://github.com/btcid/indodax-official-api-docs/blob/master/Public-RestAPI.md
 */
class IndodaxExchangeRateService
{
    private const BASE_URL = 'https://indodax.com/api';

    private const TIMEOUT = 10;

    /**
     * Fetch USD to IDR exchange rate from Indodax.
     *
     * Uses the USDT/IDR pair as a proxy for USD/IDR rate.
     * USDT (Tether) is pegged to USD and widely available on Indodax.
     *
     * @return float|null The exchange rate (1 USDT in IDR), or null on failure
     */
    public function getUsdToIdrRate(): ?float
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->retry(2, 100, throw: false)
                ->get("{$this->getBaseUrl()}/ticker/usdt_idr");

            if ($response->failed()) {
                Log::warning('Indodax API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            // Response format: { "ticker": { "last": "15850.50", ... } }
            if (! isset($data['ticker']['last'])) {
                Log::warning('Indodax API: unexpected response structure', ['response' => $data]);

                return null;
            }

            $rate = (float) $data['ticker']['last'];

            Log::info('Indodax USD→IDR rate fetched', [
                'rate' => $rate,
                'source' => 'indodax',
            ]);

            return $rate;
        } catch (RequestException $e) {
            Log::error('Indodax API request exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Indodax exchange rate service error', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Get the base URL for the Indodax API.
     * Exposed for testing/override purposes.
     */
    protected function getBaseUrl(): string
    {
        return self::BASE_URL;
    }
}
