<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessIndicatorJob
 *
 * Forwards to RunTradingCycleJob after market data ingestion.
 * Indicator calculation is now performed inside MarketDataService during ingest.
 */
class ProcessIndicatorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted before it is marked as failed.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $coins
     * @param  array<int, string>  $timeframes  Only the timeframes that had fresh data ingested.
     */
    public function __construct(
        private readonly array $coins,
        private readonly array $timeframes = [],
        private readonly string $executionId = '',
    ) {}

    /**
     * Execute the job.
     *
     * Indicators are already calculated and stored by MarketDataService during
     * the ingest phase. This job simply forwards to RunTradingCycleJob with the
     * timeframes that were actually ingested, preventing other timeframes from
     * running AI decisions prematurely.
     */
    public function handle(): void
    {
        Log::info('[ProcessIndicatorJob] Execution started', [
            'execution_id' => $this->executionId,
            'coins_count' => count($this->coins),
            'timeframes' => $this->timeframes,
        ]);

        RunTradingCycleJob::dispatch($this->coins, $this->timeframes, $this->executionId);

        Log::info('[ProcessIndicatorJob] Execution completed', [
            'execution_id' => $this->executionId,
        ]);
    }
}
