<?php

namespace App\Jobs;

use App\Models\GeneralConfig;
use App\Services\Indicator\IndicatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ProcessIndicatorJob
 *
 * Recomputes RSI, EMA9, EMA21, and trend for each configured coin.
 * Triggered after fresh market data has been inserted.
 */
class ProcessIndicatorJob implements ShouldQueue
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

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $coins
     */
    public function __construct(
        private readonly array $coins,
        private readonly ?string $timeframe = null,
    ) {}

    /**
     * Execute the job.
     *
     * Loops each coin and processes all relevant timeframes.
     */
    public function handle(IndicatorService $indicatorService): void
    {
        foreach (array_values(array_unique($this->coins)) as $coin) {
            foreach ($this->resolveTimeframes() as $timeframe) {
                $indicatorService->process($coin, $timeframe);
            }
        }

        RunAiDecisionJob::dispatch($this->coins);
    }

    /**
     * Resolve timeframes to process.
     *
     * Uses the timeframe passed from the scheduler when available (preferred).
     * Falls back to GeneralConfig so we never query the large market_indicators table.
     *
     * @return array<int, string>
     */
    private function resolveTimeframes(): array
    {
        if ($this->timeframe !== null) {
            return [$this->timeframe];
        }

        return GeneralConfig::getArray('timeframes', ['5m']);
    }
}
