<?php

namespace App\Console\Commands;

use App\Jobs\Layer1RawCoinFetchJob;
use App\Jobs\Layer2PreFilterCoinJob;
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

            // Layer 1: Shared Fetch (always runs first)
            // if (GeneralConfig::isCronEnabled()) {
            //     $this->info('▶ Running Layer 1: Shared Fetch (Layer1RawCoinFetchJob)...');
            //     Layer1RawCoinFetchJob::dispatchSync($executionId);
            //     $this->line('  ✓ Layer 1: Shared Fetch completed');
            // } else {
            //     $this->line('  ⊘ Layer 1: Shared Fetch skipped (cron disabled)');
            // }

            // Layer 2: Pre-Filter (always runs after Layer 1)
            if (GeneralConfig::isCronEnabled()) {
                $this->info('▶ Running Layer 2: Pre-Filter (Layer2Pre   FilterCoinJob)...');
                Layer2PreFilterCoinJob::dispatchSync($executionId);
                $this->line('  ✓ Layer 2: Pre-Filter completed');
            } else {
                $this->line('  ⊘ Layer 2: Pre-Filter skipped (  cron disabled)');
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
