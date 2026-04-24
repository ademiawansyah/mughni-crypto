<?php

namespace App\Jobs;

use App\Services\Trading\TradingCycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunTradingCycleJob
 *
 * Queue wrapper for TradingCycleService to preserve existing call sites.
 */
class RunTradingCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @var array<int>
     */
    public array $backoff = [60, 120];

    /**
     * @param  array<string>  $coins
     * @param  array<string>  $timeframes
     */
    public function __construct(
        private readonly array $coins = [],
        private readonly array $timeframes = [],
        private readonly string $executionId = '',
    ) {}

    public function handle(TradingCycleService $tradingCycleService): void
    {
        Log::info('[RunTradingCycleJob] Execution started', [
            'execution_id' => $this->executionId,
            'coins_override_count' => count($this->coins),
            'timeframes_override_count' => count($this->timeframes),
        ]);

        $tradingCycleService->run($this->executionId, $this->coins, $this->timeframes);

        Log::info('[RunTradingCycleJob] Execution completed', [
            'execution_id' => $this->executionId,
        ]);
    }
}
