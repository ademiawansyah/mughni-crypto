<?php

namespace Tests\Feature\Services;

use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;
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

        Log::shouldNotHaveReceived('error')
            ->withArgs(fn (string $message): bool => $message === '[NotificationService] Telegram send failed');
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
}
