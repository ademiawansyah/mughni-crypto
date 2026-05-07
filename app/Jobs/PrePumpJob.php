<?php

namespace App\Jobs;

use App\Enums\ModelType;
use App\Models\GeneralConfig;
use App\Services\Market\Models\PrePumpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PrePumpJob implements ShouldQueue
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
    public function handle(PrePumpService $prePumpService): void
    {
        $executionId = $this->executionId ?? Str::uuid()->toString();

        Log::info('[PrePumpJob] Started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isModelEnabled(ModelType::PrePump->value)) {
            Log::info('[PrePumpJob] Skipped due to cron disable flag', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        try {
            $result = $prePumpService->execute($executionId);

            Log::info('[PrePumpJob] Completed', [
                'execution_id' => $executionId,
                'evaluated' => $result['evaluated'] ?? 0,
                'shortlisted' => $result['shortlisted'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PrePumpJob] Failed', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
