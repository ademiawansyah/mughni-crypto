<?php

namespace App\Jobs;

use App\Services\Market\Models\CounterTrendModelService;
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

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('models');
    }

    public function handle(CounterTrendModelService $counterTrendModelService): void
    {
        $executionId = Str::uuid()->toString();

        Log::info('[CounterTrendJob] Started', [
            'execution_id' => $executionId,
        ]);

        $signals = $counterTrendModelService->evaluateUniverse($executionId);

        Log::info('[CounterTrendJob] Completed', [
            'execution_id' => $executionId,
            'signal_count' => $signals->count(),
            'top_coins' => $signals->pluck('coin')->all(),
        ]);
    }
}
