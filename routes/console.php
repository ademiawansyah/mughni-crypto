<?php

use App\Services\Trading\TradingCycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('trading:run-cycle', function (TradingCycleService $tradingCycleService) {
    $executionId = (string) Str::uuid();

    Log::info('[trading:run-cycle] Command started', [
        'execution_id' => $executionId,
    ]);

    $tradingCycleService->run($executionId);

    Log::info('[trading:run-cycle] Command completed', [
        'execution_id' => $executionId,
    ]);
})->purpose('Run one full trading cycle using dynamic DB timeframes and candle aggregation');

Schedule::command('trading:run-cycle')->everyFiveMinutes()->withoutOverlapping();
