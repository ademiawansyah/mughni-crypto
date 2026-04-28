<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Market\MarketRegimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MarketRegimeJob — Periodic Market Regime Detection
 *
 * Scheduled: Every 5 minutes
 *
 * Detects the current global market regime (BTC structure, volatility, etc.)
 * independent of any individual coin. Results are cached in Redis for consumption
 * by all trading models.
 *
 * Execution is traced via execution_id UUID for full pipeline traceability.
 */
class MarketRegimeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job timeout (seconds)
     */
    public int $timeout = 30;

    /**
     * Retry attempts on failure
     */
    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('market');
    }

    /**
     * Execute the job.
     */
    public function handle(MarketRegimeService $regimeService): void
    {
        $executionId = Str::uuid()->toString();

        Log::info('[MarketRegimeJob] Started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isCronEnabled()) {
            Log::info('[MarketRegimeJob] Skipped: cron disabled', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        try {
            $regime = $regimeService->detectRegime($executionId);

            Log::info('[MarketRegimeJob] Completed', [
                'execution_id' => $executionId,
                'regime' => $regime['market_regime'],
                'volatility' => $regime['volatility'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[MarketRegimeJob] Failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
