<?php

namespace App\Jobs;

use App\Services\Market\Models\PrePumpModelService;
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

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('models');
    }

    public function handle(PrePumpModelService $prePumpModelService): void
    {
        $executionId = Str::uuid()->toString();

        Log::info('[PrePumpJob] Started', [
            'execution_id' => $executionId,
        ]);

        $signals = $prePumpModelService->evaluateUniverse($executionId);

        Log::info('[PrePumpJob] Completed', [
            'execution_id' => $executionId,
            'signal_count' => $signals->count(),
            'top_coins' => $signals->pluck('coin')->all(),
        ]);
    }
}
