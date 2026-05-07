<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Market\PreFilterCoinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Layer2PreFilterCoinJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly ?string $executionId = null,
    ) {
        $this->onQueue('market');
    }

    /**
     * Execute the job.
     */
    public function handle(PreFilterCoinService $preFilterCoinService): void
    {
        $executionId = $this->executionId ?? Str::uuid()->toString();

        Log::info('[Layer2PreFilterCoinJob] Started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isCronEnabled()) {
            Log::info('[Layer2PreFilterCoinJob] Skipped due to cron disable flag', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        try {
            $result = $preFilterCoinService->filterCoins();

            Log::info('[Layer2PreFilterCoinJob] Completed', [
                'execution_id' => $executionId,
                'processed' => $result['processed'],
                'valid' => $result['valid'],
                'invalid' => $result['invalid'],
                'updated' => $result['updated'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Layer2PreFilterCoinJob] Failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
