<?php

use App\Jobs\FetchMarketJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FetchMarketJob runs every minute and decides internally which timeframes
// are due based on GeneralConfig. This avoids any DB query at scheduler boot.
Schedule::job(new FetchMarketJob)->everyMinute()->withoutOverlapping();
