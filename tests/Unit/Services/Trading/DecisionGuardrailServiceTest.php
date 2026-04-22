<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\DecisionGuardrailService;
use PHPUnit\Framework\TestCase;

class DecisionGuardrailServiceTest extends TestCase
{
    public function test_it_blocks_non_hold_action_when_confidence_is_below_minimum(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('BUY', 54, 'weak confidence'), 25.0, 'DOWN');

        $this->assertSame('HOLD', $result['action']);
        $this->assertStringContainsString('guardrail:low_confidence', $result['reason']);
        $this->assertContains('guardrail_low_confidence', $result['flags']);
    }

    public function test_it_blocks_invalid_market_data(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('SELL', 70, 'ai sell'), 0.0, 'DOWN');

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(0, $result['confidence']);
        $this->assertSame('HIGH', $result['risk_level']);
        $this->assertStringContainsString('guardrail:invalid_market_data', $result['reason']);
        $this->assertContains('guardrail_invalid_market_data', $result['flags']);
    }

    public function test_it_blocks_invalid_action_payload(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('UNKNOWN', 70, 'bad action'), 20.0, 'UP');

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(0, $result['confidence']);
        $this->assertSame('HIGH', $result['risk_level']);
        $this->assertStringContainsString('guardrail:invalid_action', $result['reason']);
        $this->assertContains('guardrail_invalid_action', $result['flags']);
    }

    public function test_it_keeps_valid_buy_signal_when_all_guardrails_pass(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('BUY', 60, 'valid buy'), 48.0, 'DOWN');

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(60, $result['confidence']);
        $this->assertSame('valid buy', $result['reason']);
    }

    public function test_it_keeps_hold_as_is_when_ai_already_returns_hold(): void
    {
        $service = new DecisionGuardrailService;

        $result = $service->apply($this->decision('HOLD', 40, 'no edge'), 52.0, 'SIDEWAYS');

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(40, $result['confidence']);
        $this->assertSame('no edge', $result['reason']);
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
