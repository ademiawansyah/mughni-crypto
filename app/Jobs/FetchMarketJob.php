<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Market\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FetchMarketJob
 *
 * Queued job that runs every minute and determines which timeframes are due
 * based on the current time and the configured timeframes in GeneralConfig.
 * Only timeframes whose interval aligns with the current minute are processed.
 *
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

    public function __construct() {}

    /**
     * Execute the job.
     *
     * Reads configured timeframes and coins from GeneralConfig, then processes
     * only the timeframes whose interval aligns with the current minute.
     */
    public function handle(MarketDataService $marketDataService): void
    {
        $executionId = (string) Str::uuid();

        // Log::info('[FetchMarketJob] Execution started', [
        //     'execution_id' => $executionId,
        // ]);

        /** @var array<string> $coins */
        $coins = GeneralConfig::getCoins();

        /** @var array<string> $timeframes */
        $timeframes = GeneralConfig::getArray('timeframes', ['5m']);

        $dueTimeframes = array_filter($timeframes, fn (string $tf) => $this->isDue($tf));

        if (empty($dueTimeframes)) {
            // Log::info('[FetchMarketJob] No due timeframe found', [
            //     'execution_id' => $executionId,
            // ]);

            return;
        }

        foreach ($dueTimeframes as $timeframe) {
            Log::info('[FetchMarketJob] Fetching market data', [
                'execution_id' => $executionId,
                'timeframe' => $timeframe,
                'coins_count' => count($coins),
            ]);
            $marketDataService->ingest($coins, $timeframe, $executionId);
        }

        ProcessIndicatorJob::dispatch($coins, array_values($dueTimeframes), $executionId);

        Log::info('[FetchMarketJob] Execution completed', [
            'execution_id' => $executionId,
            'coins_count' => count($coins),
            'timeframes' => array_values($dueTimeframes),
        ]);
    }

    /**
     * Determine whether a given timeframe is due at the current minute.
     *
     * Examples:
     *   '1m'  → always true
     *   '5m'  → true when minute % 5 === 0
     *   '1h'  → true when minute === 0
     *   '2h'  → true when minute === 0 and hour % 2 === 0
     *
     * @param  string  $timeframe  Timeframe string (e.g. '5m', '1h').
     */
    private function isDue(string $timeframe): bool
    {
        $now = now();
        $minute = (int) $now->format('i');
        $hour = (int) $now->format('G');

        if (preg_match('/^(\d+)m$/', $timeframe, $matches)) {
            $interval = (int) $matches[1];

            return $interval === 1 || $minute % $interval === 0;
        }

        if (preg_match('/^(\d+)h$/', $timeframe, $matches)) {
            $interval = (int) $matches[1];

            return $minute === 0 && ($interval === 1 || $hour % $interval === 0);
        }

        return false;
    }
}
