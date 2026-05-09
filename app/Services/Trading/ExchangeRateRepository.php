<?php

namespace App\Services\Trading;

use App\Models\ExchangeRate;
use App\Services\External\IndodaxExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Repository for managing and retrieving exchange rates.
 *
 * Handles rate caching, fallback logic, and conversion calculations.
 * Maintains rates in database for persistence and auditing.
 */
class ExchangeRateRepository
{
    private const CACHE_DURATION = 3600; // 1 hour in seconds

    private const CACHE_KEY_PREFIX = 'exchange_rate:';

    public function __construct(
        private IndodaxExchangeRateService $indodaxService,
    ) {}

    /**
     * Get the exchange rate from one currency to another.
     *
     * Checks cache first, then database, then fetches fresh from Indodax.
     * Falls back to CoinGecko if primary service fails.
     *
     * @param  string  $from  Source currency (e.g., 'USD', 'USDT')
     * @param  string  $to  Target currency (e.g., 'IDR')
     * @return float|null The exchange rate, or null if unavailable
     */
    public function getRate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // Check in-memory cache first
        $cacheKey = $this->getCacheKey($from, $to);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (float) $cached;
        }

        // Check database
        $dbRate = ExchangeRate::getRate($from, $to);

        if ($dbRate !== null) {
            // Cache the database rate
            Cache::put($cacheKey, $dbRate, self::CACHE_DURATION);

            return $dbRate;
        }

        // Fetch fresh rate
        return $this->fetchAndStoreRate($from, $to);
    }

    /**
     * Fetch exchange rate from external service and store in database.
     *
     * @param  string  $from  Source currency (e.g., 'USD', 'USDT')
     * @param  string  $to  Target currency (e.g., 'IDR')
     * @return float|null The exchange rate, or null if all sources fail
     */
    public function fetchAndStoreRate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // For USD/USDT → IDR, use Indodax
        if (($from === 'USD' || $from === 'USDT') && $to === 'IDR') {
            $rate = $this->indodaxService->getUsdToIdrRate();

            if ($rate !== null) {
                $this->storeRate($from, $to, $rate, 'indodax');

                return $rate;
            }
        }

        // If Indodax fails, try CoinGecko as fallback
        $rate = $this->fetchFromCoinGecko($from, $to);

        if ($rate !== null) {
            $this->storeRate($from, $to, $rate, 'coingecko');

            return $rate;
        }

        Log::warning("Could not fetch exchange rate: {$from}→{$to}", [
            'sources_tried' => ['indodax', 'coingecko'],
        ]);

        return null;
    }

    /**
     * Store or update exchange rate in database and cache.
     *
     * @param  string  $from  Source currency
     * @param  string  $to  Target currency
     * @param  float  $rate  Exchange rate value
     * @param  string  $source  Source identifier (indodax, coingecko, etc.)
     */
    public function storeRate(string $from, string $to, float $rate, string $source = 'manual'): void
    {
        ExchangeRate::updateOrCreate(
            [
                'from_currency' => strtoupper($from),
                'to_currency' => strtoupper($to),
            ],
            [
                'rate' => $rate,
                'source' => $source,
                'refreshed_at' => now(),
            ],
        );

        // Update cache
        $cacheKey = $this->getCacheKey($from, $to);
        Cache::put($cacheKey, $rate, self::CACHE_DURATION);

        Log::info("Exchange rate stored: {$from}→{$to}", [
            'rate' => $rate,
            'source' => $source,
        ]);
    }

    /**
     * Convert a price from one currency to another.
     *
     * @param  float  $price  The price in the source currency
     * @param  string  $fromCurrency  Source currency (e.g., 'USD')
     * @param  string  $toCurrency  Target currency (e.g., 'IDR')
     * @return float|null The converted price, or null if rate unavailable
     */
    public function convertPrice(float $price, string $fromCurrency, string $toCurrency): ?float
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return $price;
        }

        $rate = $this->getRate($fromCurrency, $toCurrency);

        if ($rate === null) {
            Log::warning('Cannot convert price: exchange rate not available', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
            ]);

            return null;
        }

        return $price * $rate;
    }

    /**
     * Fetch exchange rate from CoinGecko API (fallback).
     *
     * @param  string  $from  Source currency
     * @param  string  $to  Target currency
     * @return float|null The exchange rate, or null on failure
     */
    private function fetchFromCoinGecko(string $from, string $to): ?float
    {
        try {
            // CoinGecko simple price endpoint
            $response = Http::timeout(10)
                ->retry(2, 100, throw: false)
                ->get('https://api.coingecko.com/api/v3/simple/price', [
                    'ids' => 'usd',
                    'vs_currencies' => strtolower($to),
                ]);

            if ($response->failed()) {
                Log::warning('CoinGecko API failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            // Response: { "usd": { "idr": 15850.50 } }
            if (! isset($data['usd'][strtolower($to)])) {
                Log::warning('CoinGecko API: unexpected response structure', ['response' => $data]);

                return null;
            }

            $rate = (float) $data['usd'][strtolower($to)];

            Log::info("CoinGecko {$from}→{$to} rate fetched", [
                'rate' => $rate,
                'source' => 'coingecko',
            ]);

            return $rate;
        } catch (\Exception $e) {
            Log::error('CoinGecko API error', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate cache key for a currency pair.
     */
    private function getCacheKey(string $from, string $to): string
    {
        return self::CACHE_KEY_PREFIX.strtoupper($from).'_'.strtoupper($to);
    }
}
