<?php

namespace App\Console\Commands;

use App\Jobs\CounterTrendJob;
use App\Jobs\FetchMarketJob;
use App\Jobs\MarketRegimeJob;
use App\Jobs\PrePumpJob;
use App\Jobs\UpdateCoinUniverseJob;
use App\Models\GeneralConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

#[Signature('cron:run-all')]
#[Description('Manually run all scheduled cron jobs')]
class RunAllCronJobsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Starting all scheduled cron jobs...');
        $this->newLine();

        $executionId = (string) Str::uuid();
        $this->line("Execution ID: <fg=cyan>{$executionId}</>");
        $this->newLine();

        try {
            // 1. Run trading cycle
            $this->info('▶ Running trading:run-cycle...');
            Artisan::call('trading:run-cycle');
            $this->line('  ✓ trading:run-cycle completed');

            // 2. Dispatch FetchMarketJob
            $this->info('▶ Dispatching FetchMarketJob...');
            FetchMarketJob::dispatchSync();
            $this->line('  ✓ FetchMarketJob completed');

            // 3. Dispatch MarketRegimeJob
            $this->info('▶ Dispatching MarketRegimeJob...');
            MarketRegimeJob::dispatchSync();
            $this->line('  ✓ MarketRegimeJob completed');

            // 4. Dispatch UpdateCoinUniverseJob
            $this->info('▶ Dispatching UpdateCoinUniverseJob...');
            UpdateCoinUniverseJob::dispatchSync();
            $this->line('  ✓ UpdateCoinUniverseJob completed');

            // 5. Dispatch CounterTrendJob (if enabled)
            if (GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('counter_trend')) {
                $this->info('▶ Dispatching CounterTrendJob...');
                CounterTrendJob::dispatchSync();
                $this->line('  ✓ CounterTrendJob completed');
            } else {
                $this->line('  ⊘ CounterTrendJob skipped (not enabled)');
            }

            // 6. Dispatch PrePumpJob (if enabled)
            if (GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('pre_pump')) {
                $this->info('▶ Dispatching PrePumpJob...');
                PrePumpJob::dispatchSync();
                $this->line('  ✓ PrePumpJob completed');
            } else {
                $this->line('  ⊘ PrePumpJob skipped (not enabled)');
            }

            $this->newLine();
            $this->info('✅ All cron jobs completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Error running cron jobs:');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
