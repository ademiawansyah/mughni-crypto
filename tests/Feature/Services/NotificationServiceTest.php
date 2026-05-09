<?php

namespace Tests\Feature\Services;

use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    public function test_it_skips_model_execution_telegram_notification_when_no_coin_passed(): void
    {
        Log::spy();

        config([
            'services.telegram.bot' => 'mybot',
            'services.telegram.chat_id' => '123456789',
        ]);

        $service = app(NotificationService::class);

        $service->sendModelExecutionResult([
            'execution_id' => 'exec-no-pass',
            'model' => 'pre_pump',
            'evaluated' => 10,
            'shortlisted' => 0,
            'results' => [],
        ]);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('[NotificationService] Skipping model execution notification because no coin passed', [
                'execution_id' => 'exec-no-pass',
                'model' => 'pre_pump',
                'evaluated' => 10,
                'shortlisted' => 0,
            ]);

        Log::shouldNotHaveReceived('error');
    }

    public function test_it_skips_telegram_send_when_chat_id_is_missing(): void
    {
        Log::spy();

        config([
            'services.telegram.bot' => 'mybot',
            'services.telegram.chat_id' => '',
        ]);

        $service = app(NotificationService::class);

        $service->sendTradeSignal([
            'execution_id' => 'exec-123',
            'coin' => 'BTC/USDT',
            'timeframe' => '5m',
            'action' => 'BUY',
            'confidence' => 88,
            'reason' => 'test_reason',
            'risk_level' => 'LOW',
            'entry' => 64000.55,
            'take_profit' => 65000.55,
            'stop_loss' => 63000.55,
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('[NotificationService] Telegram chat ID is not configured, skipping send', [
                'execution_id' => 'exec-123',
            ]);
    }

    public function test_it_logs_error_when_telegram_send_throws_exception(): void
    {
        Log::spy();
        Sentry::shouldReceive('captureException')
            ->once()
            ->withArgs(function ($exception): bool {
                return $exception instanceof \Throwable
                    && str_contains($exception->getMessage(), 'Bot [missing-bot] is not defined');
            });

        config([
            'services.telegram.bot' => 'missing-bot',
            'services.telegram.chat_id' => '123456789',
        ]);

        $service = app(NotificationService::class);

        $service->sendTradeSignal([
            'execution_id' => 'exec-456',
            'coin' => 'ETH/USDT',
            'timeframe' => '15m',
            'action' => 'SELL',
            'confidence' => 76,
            'reason' => 'test_reason',
            'risk_level' => 'MEDIUM',
        ]);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[NotificationService] Telegram send failed'
                    && $context['execution_id'] === 'exec-456'
                    && $context['bot'] === 'missing-bot'
                    && $context['chat_id'] === '123456789'
                    && isset($context['error']);
            });
    }

    public function test_it_includes_passed_coins_in_model_execution_summary(): void
    {
        Log::spy();

        config([
            'services.telegram.bot' => 'mybot',
            'services.telegram.chat_id' => '',
        ]);

        $service = app(NotificationService::class);

        $service->sendModelExecutionResult([
            'execution_id' => 'exec-summary-coins',
            'model' => 'pre_pump',
            'evaluated' => 12,
            'shortlisted' => 2,
            'results' => [
                ['symbol' => 'btc/usdt', 'score' => 88.2, 'price' => 64000],
                ['symbol' => 'eth/usdt', 'score' => 81.5, 'price' => 3200],
            ],
        ]);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[NotificationService] System notification triggered'
                    && ($context['execution_id'] ?? null) === 'exec-summary-coins'
                    && in_array('Coins: BTC/USDT, ETH/USDT', $context['lines'] ?? [], true);
            });
    }
}
