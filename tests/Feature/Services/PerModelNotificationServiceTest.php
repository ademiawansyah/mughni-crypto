<?php

namespace Tests\Feature\Services;

use App\Services\Market\Models\ModelSignalDTO;
use App\Services\Notification\PerModelNotificationService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PerModelNotificationServiceTest extends TestCase
{
    public function test_it_builds_model_labeled_message_with_execution_id_suffix(): void
    {
        $service = app(PerModelNotificationService::class);

        $reflectionMethod = new \ReflectionMethod(PerModelNotificationService::class, 'buildMessage');
        $reflectionMethod->setAccessible(true);

        $message = $reflectionMethod->invoke(
            $service,
            'counter_trend',
            $this->makeSignal(),
            [
                'action' => 'BUY',
                'confidence' => 88,
                'agreement' => false,
                'ai_enabled' => true,
            ],
            [
                'market_regime' => 'TRENDING_UP',
                'risk_level' => 'LOW',
            ],
            'abcd1234-5678-90ef-ghij-1234567890ab',
        );

        $this->assertStringContainsString('COUNTER-TREND MODEL', $message);
        $this->assertStringContainsString('<b>BUY</b>', $message);
        $this->assertStringContainsString('Liquidity sweep (90%)', $message);
        $this->assertStringContainsString('AI Refinement: ⚠️ Disagreed', $message);
        $this->assertStringContainsString('Execution ID: abcd1234', $message);
    }

    public function test_it_logs_error_when_telegram_send_fails(): void
    {
        Log::spy();

        config([
            'telegram.chat_id' => '123456789',
            'services.telegram.bot' => 'missing-bot',
        ]);

        $service = app(PerModelNotificationService::class);

        $service->notify(
            'momentum',
            $this->makeSignal(),
            [
                'action' => 'SELL',
                'confidence' => 72,
                'agreement' => true,
                'ai_enabled' => true,
            ],
            [
                'market_regime' => 'RANGING',
                'risk_level' => 'MEDIUM',
            ],
            'exec-telegram-fail',
        );

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === '[PerModelNotificationService] Failed to send notification'
                    && $context['execution_id'] === 'exec-telegram-fail'
                    && $context['model'] === 'momentum'
                    && $context['coin'] === 'bitcoin'
                    && isset($context['error']);
            });
    }

    private function makeSignal(): ModelSignalDTO
    {
        return new ModelSignalDTO(
            model: 'counter_trend',
            coin: 'bitcoin',
            action: 'BUY',
            score: 88,
            primaryTimeframe: '15m',
            componentScores: [
                'sweep' => 0.9,
                'funding' => 0.8,
                'ema' => 0.85,
            ],
            context: [
                'timeframe' => '15m',
            ],
            reasons: ['sweep', 'mss'],
        );
    }
}
