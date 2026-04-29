<?php

namespace App\Jobs;

use App\Services\Market\CoinMarketDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CoinMarketDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $coin;

    /**
     * @var array<int, string>
     */
    public array $timeframes;

    public string $executionId;

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
     */
    public function __construct(
        string $coin,
        array $timeframes,
        string $executionId,
    ) {
        $this->coin = $coin;
        $this->timeframes = $timeframes;
        $this->executionId = $executionId;
        $this->onQueue('market');
    }

    /**
     * Execute the job.
     */
    public function handle(
        CoinMarketDataService $coinMarketDataService,
    ): void {
        $coin = $this->coin;
        $timeframes = $this->timeframes;
        $executionId = $this->executionId;
        Log::info('[CoinMarketDataJob] Started ingestion for coin', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframes' => $timeframes,
        ]);

        $coinMarketDataService->ingest($coin, $timeframes, $executionId);

        Log::info('[CoinMarketDataJob] Completed ingestion for coin', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframes' => $timeframes,
        ]);
    }
}
