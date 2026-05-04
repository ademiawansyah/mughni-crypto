<?php

namespace App\Jobs;

use App\Enums\ModelType;
use App\Models\GeneralConfig;
use App\Services\Market\Models\CounterTrendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CounterTrendJob implements ShouldQueue
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
    public function handle(CounterTrendService $counterTrendService): void
    {
        $executionId = $this->executionId ?? Str::uuid()->toString();

        Log::info('[CounterTrendJob] Started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isModelEnabled(ModelType::CounterTrend->value)) {
            Log::info('[CounterTrendJob] Skipped due to cron disable flag', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        try {
            $result = $counterTrendService->execute($executionId);

            Log::info('[CounterTrendJob] Completed', [
                'execution_id' => $executionId,
                'evaluated' => $result['evaluated'] ?? 0,
                'shortlisted' => $result['shortlisted'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('[CounterTrendJob] Failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
