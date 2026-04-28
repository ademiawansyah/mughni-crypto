<?php

namespace Tests\Feature\Services\AI;

use App\Services\AI\AiResponseParser;
use App\Services\AI\LmStudioClient;
use App\Services\AI\PerModelAiLayer;
use App\Services\Market\Models\ModelSignalDTO;
use Tests\TestCase;

class PerModelAiLayerTest extends TestCase
{
    public function test_it_returns_regime_adjusted_signal_when_ai_is_disabled(): void
    {
        config([
            'models.counter_trend.ai_enabled' => false,
            'models.counter_trend.market_confidence_adjusters.TRENDING_UP' => -15,
        ]);

        $lmStudioClient = $this->createMock(LmStudioClient::class);
        $lmStudioClient->expects($this->never())->method('chat');

        $responseParser = $this->createMock(AiResponseParser::class);
        $responseParser->expects($this->never())->method('parse');

        $service = new PerModelAiLayer($lmStudioClient, $responseParser);

        $signal = $this->makeSignal('counter_trend', 'BUY', 60);
        $marketRegime = [
            'market_regime' => 'TRENDING_UP',
            'volatility' => 'MEDIUM',
        ];

        $result = $service->interpret($signal, $marketRegime, 'exec-1');

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(45, $result['confidence']);
        $this->assertTrue($result['agreement']);
        $this->assertFalse($result['ai_enabled']);
        $this->assertNull($result['ai_response']);
    }

    public function test_it_uses_ai_response_and_applies_disagreement_penalty_when_ai_is_enabled(): void
    {
        config([
            'models.momentum.ai_enabled' => true,
            'models.momentum.market_confidence_adjusters.TRENDING_UP' => 30,
        ]);

        $rawResponse = [
            'response' => '{"action":"SELL","confidence":80,"risk_level":"MEDIUM","reason":"trend weakening"}',
        ];

        $lmStudioClient = $this->createMock(LmStudioClient::class);
        $lmStudioClient
            ->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                if (! isset($messages[0]['content'])) {
                    return false;
                }

                return str_contains($messages[0]['content'], 'Momentum (trend) setup')
                    && str_contains($messages[0]['content'], 'COIN: bitcoin');
            }))
            ->willReturn($rawResponse);

        $responseParser = $this->createMock(AiResponseParser::class);
        $responseParser
            ->expects($this->once())
            ->method('parse')
            ->with($rawResponse)
            ->willReturn([
                'action' => 'SELL',
                'confidence' => 80,
                'risk_level' => 'MEDIUM',
                'reason' => 'trend weakening',
            ]);

        $service = new PerModelAiLayer($lmStudioClient, $responseParser);

        $signal = $this->makeSignal('momentum', 'BUY', 70);
        $marketRegime = [
            'market_regime' => 'TRENDING_UP',
            'volatility' => 'HIGH',
            'btc_direction' => 'UP',
            'risk_level' => 'LOW',
        ];

        $result = $service->interpret($signal, $marketRegime, 'exec-2');

        $this->assertSame('SELL', $result['action']);
        $this->assertSame(82, $result['confidence']);
        $this->assertFalse($result['agreement']);
        $this->assertTrue($result['ai_enabled']);
        $this->assertSame($rawResponse, $result['ai_response']);
        $this->assertSame('trend weakening', $result['reasoning']);
    }

    public function test_it_falls_back_to_signal_when_ai_returns_null(): void
    {
        config([
            'models.pre_pump.ai_enabled' => true,
            'models.pre_pump.market_confidence_adjusters.RANGING' => 10,
        ]);

        $lmStudioClient = $this->createMock(LmStudioClient::class);
        $lmStudioClient->expects($this->once())->method('chat')->willReturn(null);

        $responseParser = $this->createMock(AiResponseParser::class);
        $responseParser->expects($this->never())->method('parse');

        $service = new PerModelAiLayer($lmStudioClient, $responseParser);

        $signal = $this->makeSignal('pre_pump', 'BUY', 65);
        $marketRegime = [
            'market_regime' => 'RANGING',
        ];

        $result = $service->interpret($signal, $marketRegime, 'exec-3');

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(75, $result['confidence']);
        $this->assertTrue($result['agreement']);
        $this->assertTrue($result['ai_enabled']);
        $this->assertNull($result['ai_response']);
    }

    private function makeSignal(string $model, string $action, int $score): ModelSignalDTO
    {
        return new ModelSignalDTO(
            model: $model,
            coin: 'bitcoin',
            action: $action,
            score: $score,
            primaryTimeframe: '15m',
            componentScores: [
                'sweep' => 0.9,
                'mss' => 0.8,
                'funding' => 0.7,
                'atr' => 0.6,
                'ema' => 0.85,
                'macd' => 0.7,
                'rsi' => 0.6,
                'oi' => 0.7,
                'bos' => 0.8,
                'cvd' => 0.75,
                'rs' => 0.65,
            ],
            context: [
                'timeframe' => '15m',
            ],
            reasons: ['reason-one', 'reason-two'],
        );
    }
}
