<?php

namespace App\Jobs;

use App\Services\Market\Models\MomentumModelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MomentumJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('models');
    }

    public function handle(MomentumModelService $momentumModelService): void
    {
        $executionId = Str::uuid()->toString();

        Log::info('[MomentumJob] Started', [
            'execution_id' => $executionId,
        ]);

        $signals = $momentumModelService->evaluateUniverse($executionId);

        Log::info('[MomentumJob] Completed', [
            'execution_id' => $executionId,
            'signal_count' => $signals->count(),
            'top_coins' => $signals->pluck('coin')->all(),
        ]);
    }
}
