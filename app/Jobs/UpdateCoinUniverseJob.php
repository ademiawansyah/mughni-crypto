<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Market\CoinUniverseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UpdateCoinUniverseJob — Daily Coin Universe Refresh
 *
 * Scheduled: Daily at 00:00 UTC (0 0 * * *)
 *
 * Fetches the latest coins from CoinGecko, applies market criteria filters
 * (market cap > $100M, volume > $5M, Binance futures only, exclude stablecoins),
 * and caches the result in Redis for 24 hours.
 *
 * Execution is traced via execution_id UUID for full pipeline traceability.
 */
class UpdateCoinUniverseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job timeout (seconds)
     */
    public int $timeout = 60;

    /**
     * Retry attempts on failure
     */
    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('market');
    }

    /**
     * Execute the job.
     */
    public function handle(CoinUniverseService $universeService): void
    {
        $executionId = Str::uuid()->toString();

        Log::info('[UpdateCoinUniverseJob] Started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isCronEnabled()) {
            Log::info('[UpdateCoinUniverseJob] Skipped: cron disabled', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        try {
            $coins = $universeService->updateUniverse($executionId);

            Log::info('[UpdateCoinUniverseJob] Completed', [
                'execution_id' => $executionId,
                'total_coins' => count($coins),
            ]);
        } catch (\Throwable $e) {
            Log::error('[UpdateCoinUniverseJob] Failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
