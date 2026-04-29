<?php

namespace Database\Seeders;

use App\Models\GeneralConfig;
use Illuminate\Database\Seeder;

/**
 * Seeds the general_config table with default application settings.
 * These defaults mirror the previously env-based market configuration.
 */
class GeneralConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Inserts default key-value settings. Existing keys are updated to avoid duplicates.
     */
    public function run(): void
    {
        $defaults = [
            'coins' => 'bitcoin,ethereum,solana,tether,xrp',
            'timeframes' => '15m,1h,4h,8h,1d',
            'cron_enabled' => '1',
            'counter_trend_enabled' => '1',
            'pre_pump_enabled' => '1',
            'momentum_enabled' => '1',
        ];

        foreach ($defaults as $key => $value) {
            GeneralConfig::set($key, $value);
        }
    }
}
