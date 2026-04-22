<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\DecisionGuardrailService;
use PHPUnit\Framework\TestCase;

class DecisionGuardrailServiceTest extends TestCase
{
    public function test_it_forces_buy_to_hold_when_rsi_is_25_or_higher(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('BUY', 70, 'ai buy'), 25.0, 'DOWN');

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(70, $result['confidence']);
        $this->assertSame('ai buy', $result['reason']);
    }

    public function test_it_forces_sell_to_hold_when_rsi_is_75_or_lower(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('SELL', 70, 'ai sell'), 75.0, 'DOWN');

        $this->assertSame('HOLD', $result['action']);
    }

    public function test_it_forces_hold_when_confidence_is_below_55(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('BUY', 54, 'weak confidence'), 10.0, 'DOWN');

        $this->assertSame('HOLD', $result['action']);
    }

    public function test_strong_overbought_uptrend_overrides_to_sell_and_raises_confidence_to_75_minimum(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('HOLD', 50, 'prior hold'), 81.0, 'UP');

        $this->assertSame('SELL', $result['action']);
        $this->assertSame(75, $result['confidence']);
        $this->assertSame('overbought reversal | overridden', $result['reason']);
    }

    public function test_strong_overbought_uptrend_keeps_higher_existing_confidence(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('HOLD', 82, 'prior hold'), 84.5, 'UP');

        $this->assertSame('SELL', $result['action']);
        $this->assertSame(82, $result['confidence']);
        $this->assertSame('overbought reversal | overridden', $result['reason']);
    }

    public function test_it_keeps_valid_buy_signal_when_all_guardrails_pass(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('BUY', 60, 'valid buy'), 20.0, 'DOWN');

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(60, $result['confidence']);
        $this->assertSame('valid buy', $result['reason']);
    }

    /**
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function decision(string $action, int $confidence, string $reason): array
    {
        return [
            'action' => $action,
            'confidence' => $confidence,
            'risk_level' => 'LOW',
            'reason' => $reason,
        ];
    }
}
