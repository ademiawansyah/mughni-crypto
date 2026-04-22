<?php

use App\Jobs\FetchMarketJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// MTF pipeline runs every 5 minutes and derives 15m/30m/60m from one 5m base dataset.
Schedule::job(new FetchMarketJob)->everyFiveMinutes()->withoutOverlapping();
