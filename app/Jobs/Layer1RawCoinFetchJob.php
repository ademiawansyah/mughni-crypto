<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Market\SharedFetchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Layer1RawCoinFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(
        private readonly ?string $executionId = null,
    ) {
        $this->onQueue('market');
    }

    /**
     * Execute the job.
     */
    public function handle(SharedFetchService $sharedFetchService): void
    {
        $executionId = $this->executionId ?? Str::uuid()->toString();

        Log::info('[Layer1RawCoinFetchJob] Started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isCronEnabled()) {
            Log::info('[Layer1RawCoinFetchJob] Skipped due to cron disable flag', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        try {
            $result = $sharedFetchService->fetchAndStoreMarketData($executionId);

            Layer2PreFilterCoinJob::dispatch($executionId);

            Log::info('[Layer1RawCoinFetchJob] Completed', [
                'execution_id' => $executionId,
                'total_coins_fetched' => $result['total_coins_fetched'],
                'stored_count' => $result['stored_count'],
                'removed_count' => $result['removed_count'],
                'layer2_dispatched' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Layer1RawCoinFetchJob] Failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
