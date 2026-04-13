<?php

namespace App\Jobs;

use App\Services\Market\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * FetchMarketJob
 *
 * Queued job that triggers one full market data ingestion cycle.
 * The coin list is sourced from config/market.php to keep it configurable
 * without touching this class.
 *
 * Intended to run every 5 minutes via the scheduler.
 * No business logic lives here — all logic is delegated to MarketDataService.
 */
class FetchMarketJob implements ShouldQueue
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
     * Execute the job.
     *
     * Reads the configured coin list and delegates ingestion to MarketDataService.
     */
    public function handle(MarketDataService $marketDataService): void
    {
        /** @var array<string> $coins */
        $coins = config('market.coins', []);

        $marketDataService->ingest($coins);
    }
}
