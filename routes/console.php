<?php

use App\Jobs\Layer1RawCoinFetchJob;
use App\Jobs\PrePumpJob;
use App\Jobs\SpotMomentumGainerJob;
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

/**
 * ============================================================================
 * LAYER 1: SHARED FETCH (Every 5 minutes)
 * ============================================================================
 * Centralized market data fetcher. Fetches top 300 coins from CoinGecko
 * and persists to coins table. All models consume database data from coins table.
 */
Schedule::job(new Layer1RawCoinFetchJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn(): bool => GeneralConfig::isCronEnabled());

/**
 * ============================================================================
 * MODEL 2: PRE-PUMP (Every 4 hours)
 * ============================================================================
 * Runs independent Model 2 scanning pipeline using cached/shared market data.
 */
Schedule::job(new PrePumpJob)
    ->cron((string) config('models.pre_pump.job_schedule', '0 */4 * * *'))
    ->withoutOverlapping()
    ->when(fn(): bool => GeneralConfig::isCronEnabled())
    ->when(fn(): bool => GeneralConfig::isModelEnabled('pre_pump'));

/**
 * ============================================================================
 * MODEL 4: SPOT MOMENTUM GAINER (Daily at 07:00 WIB)
 * ============================================================================
 * Runs independent Model 4 scanning pipeline using CMC primary source,
 * CoinGecko fallback, and 1D bullish-candle gate validation.
 */
Schedule::job(new SpotMomentumGainerJob)
    ->cron((string) config('models.spot_momentum_gainer.job_schedule', '0 7 * * *'))
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->when(fn(): bool => GeneralConfig::isCronEnabled())
    ->when(fn(): bool => GeneralConfig::isModelEnabled('spot_momentum_gainer'));
