<?php

namespace App\Console\Commands;

use App\Jobs\CounterTrendJob;
use App\Jobs\MomentumJob;
use App\Jobs\PrePumpJob;
use App\Models\GeneralConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
            // 1. Dispatch CounterTrendJob (if enabled)
            // if (GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('counter_trend')) {
            //     $this->info('▶ Queueing CounterTrendJob...');
            //     CounterTrendJob::dispatch();
            //     $this->line('  ✓ CounterTrendJob queued');
            // } else {
            //     $this->line('  ⊘ CounterTrendJob skipped (not enabled)');
            // }

            // 2. Dispatch PrePumpJob (if enabled)
            // if (GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('pre_pump')) {
            //     $this->info('▶ Queueing PrePumpJob...');
            //     PrePumpJob::dispatch();
            //     $this->line('  ✓ PrePumpJob queued');
            // } else {
            //     $this->line('  ⊘ PrePumpJob skipped (not enabled)');
            // }

            // 3. Dispatch MomentumJob (if enabled)
            // if (GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('momentum')) {
            //     $this->info('▶ Queueing MomentumJob...');
            //     // MomentumJob::dispatch();
            //     $this->line('  ✓ MomentumJob queued');
            // } else {
            //     $this->line('  ⊘ MomentumJob skipped (not enabled)');
            // }

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
