<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Market\CoinUniverseService;
use App\Services\Market\MarketDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FetchMarketJob
 *
 * Queued job that runs every 5 minutes and triggers one MTF ingestion cycle.
 *
 * Data is fetched once per coin (days=1) and then derived into configured
 * dynamic timeframes inside MarketDataService.
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
     * Runs one complete MTF pipeline cycle and dispatches downstream jobs.
     */
    public function handle(
        MarketDataService $marketDataService,
        CoinUniverseService $coinUniverseService,
    ): void {
        $executionId = (string) Str::uuid();

        Log::info('[FetchMarketJob] Execution started', [
            'execution_id' => $executionId,
        ]);

        if (! GeneralConfig::isCronEnabled()) {
            Log::info('[FetchMarketJob] Skipped: cron disabled', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        $coins = $this->resolveCoins($coinUniverseService);

        $timeframes = $this->resolveSortedTimeframes();

        if ($coins === []) {
            Log::warning('[FetchMarketJob] No configured coins available, skipping execution', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        if ($timeframes === []) {
            Log::warning('[FetchMarketJob] No valid timeframe configured, skipping execution', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        Log::info('[FetchMarketJob] Fetching MTF market data', [
            'execution_id' => $executionId,
            'timeframes' => $timeframes,
            'coins_count' => count($coins),
        ]);

        $marketDataService->ingest($coins, $timeframes, $executionId);

        Log::info('[FetchMarketJob] Execution completed', [
            'execution_id' => $executionId,
            'coins_count' => count($coins),
            'timeframes' => $timeframes,
        ]);
    }

    /**
     * Resolve candidate coins for ingestion.
     *
     * Coins are sourced exclusively from the cached Binance futures universe
     * maintained by UpdateCoinUniverseJob. If the cache is empty (i.e. the
     * universe has not been seeded yet), this returns an empty array and the
     * job will skip execution gracefully.
     *
     * Manual coin lists from GeneralConfig are intentionally NOT used here;
     * the spec requires the full Binance futures universe as the data source.
     *
     * @return array<string>
     */
    private function resolveCoins(CoinUniverseService $coinUniverseService): array
    {
        /** @var array<int, array{coin?: string}> $cachedUniverse */
        $cachedUniverse = $coinUniverseService->getCachedUniverse();

        $coins = array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['coin'] ?? '')),
            $cachedUniverse,
        )));

        return array_values(array_unique($coins));
    }

    /**
     * @return array<string>
     */
    private function resolveSortedTimeframes(): array
    {
        $timeframes = GeneralConfig::getTimeframes();
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn (string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return array_values(array_filter($unique, fn (string $timeframe): bool => $this->timeframeToMinutes($timeframe) !== PHP_INT_MAX));
    }

    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        if (preg_match('/^(\d+)d$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60 * 24;
        }

        return PHP_INT_MAX;
    }
}
