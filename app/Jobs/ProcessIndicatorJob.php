<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ProcessIndicatorJob
 *
 * Forwards to RunAiDecisionJob after market data ingestion.
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
     */
    public function __construct(
        private readonly array $coins,
        private readonly ?string $timeframe = null,
    ) {}

    /**
     * Execute the job.
     *
     * Indicators are already calculated and stored by MarketDataService during
     * the ingest phase. This job simply forwards to RunAiDecisionJob.
     */
    public function handle(): void
    {
        RunAiDecisionJob::dispatch($this->coins);
    }
}
