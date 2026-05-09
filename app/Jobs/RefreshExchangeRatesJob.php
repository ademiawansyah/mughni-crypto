<?php

namespace App\Jobs;

use App\Services\Trading\ExchangeRateRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Refresh exchange rates from external sources and store in database.
 *
 * Runs periodically (daily) to fetch latest rates for USD→IDR and other
 * currency pairs. Handles errors gracefully and logs all activity.
 */
class RefreshExchangeRatesJob implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    /**
     * Execute the job to refresh all configured exchange rates.
     */
    public function handle(ExchangeRateRepository $rateRepository): void
    {
        try {
            Log::info('Starting exchange rate refresh job');

            // Refresh USD → IDR rate
            $usdToIdrRate = $rateRepository->fetchAndStoreRate('USD', 'IDR');

            if ($usdToIdrRate !== null) {
                Log::info('Exchange rate refresh completed successfully', [
                    'usd_to_idr' => $usdToIdrRate,
                ]);
            } else {
                Log::warning('Exchange rate refresh: some rates could not be fetched');
            }
        } catch (\Exception $e) {
            Log::error('Exchange rate refresh job failed', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }
}
