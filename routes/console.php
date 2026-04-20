<?php

use App\Jobs\FetchMarketJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new FetchMarketJob)
    ->cron(timeframeToCron(config('market.timeframe', '5m')))
    ->withoutOverlapping();

/**
 * Convert a timeframe string (e.g. '1m', '5m', '1h') into a cron expression.
 * Defaults to every 5 minutes for unrecognised values.
 */
function timeframeToCron(string $timeframe): string
{
    if (preg_match('/^(\d+)m$/', $timeframe, $matches)) {
        $minutes = (int) $matches[1];

        return $minutes === 1 ? '* * * * *' : "*/{$minutes} * * * *";
    }

    if (preg_match('/^(\d+)h$/', $timeframe, $matches)) {
        $hours = (int) $matches[1];

        return $hours === 1 ? '0 * * * *' : "0 */{$hours} * * *";
    }

    return '*/5 * * * *';
}
