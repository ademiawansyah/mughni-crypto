<?php

use App\Services\Notification\NotificationService;
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

Artisan::command('notification:test-telegram {--chat_id=} {--bot=} {--action=BUY}', function (NotificationService $notificationService) {
    $executionId = (string) Str::uuid();
    $chatIdOption = $this->option('chat_id');
    $botOption = $this->option('bot');
    $actionOption = strtoupper((string) $this->option('action'));

    if (is_string($chatIdOption) && $chatIdOption !== '') {
        config(['services.telegram.chat_id' => $chatIdOption]);
    }

    if (is_string($botOption) && $botOption !== '') {
        config(['services.telegram.bot' => $botOption]);
    }

    $action = in_array($actionOption, ['BUY', 'SELL'], true)
        ? $actionOption
        : 'BUY';

    $notificationService->sendTradeSignal([
        'execution_id' => $executionId,
        'coin' => 'BTC/USDT',
        'timeframe' => '5m',
        'action' => $action,
        'confidence' => 85,
        'risk_level' => 'MEDIUM',
        'reason' => 'telegram_test_message',
        'entry' => 65000.12345678,
        'take_profit' => 66100.12345678,
        'stop_loss' => 64250.12345678,
    ]);

    $this->info('Telegram test notification dispatched. Check your Telegram chat and application logs.');
    $this->line(sprintf('execution_id: %s', $executionId));
})->purpose('Send a test Telegram trade signal notification');

Schedule::command('trading:run-cycle')->everyFiveMinutes()->withoutOverlapping();
