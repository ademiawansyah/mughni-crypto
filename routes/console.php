<?php

use App\Jobs\CounterTrendJob;
use App\Jobs\FetchMarketJob;
use App\Jobs\MarketRegimeJob;
use App\Jobs\MomentumJob;
use App\Jobs\PrePumpJob;
use App\Jobs\UpdateCoinUniverseJob;
use App\Models\GeneralConfig;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

// Schedule::job(new FetchMarketJob)
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->when(fn(): bool => GeneralConfig::isCronEnabled());

// Schedule::job(new MarketRegimeJob)
//     ->everyFiveMinutes()
//     ->withoutOverlapping()
//     ->when(fn(): bool => GeneralConfig::isCronEnabled());

// Schedule::job(new UpdateCoinUniverseJob)
//     ->dailyAt('00:00')
//     ->withoutOverlapping()
//     ->when(fn(): bool => GeneralConfig::isCronEnabled());

// Schedule::job(new CounterTrendJob)
//     ->everyFifteenMinutes()
//     ->withoutOverlapping()
//     ->when(fn(): bool => GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('counter_trend'));

// Schedule::job(new PrePumpJob)
//     ->everyThirtyMinutes()
//     ->withoutOverlapping()
//     ->when(fn(): bool => GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('pre_pump'));

// Schedule::job(new MomentumJob)
//     ->hourly()
//     ->withoutOverlapping()
//     ->when(fn(): bool => GeneralConfig::isCronEnabled() && GeneralConfig::isModelEnabled('momentum'));
