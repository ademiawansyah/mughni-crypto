<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\TradeLevelService;
use PHPUnit\Framework\TestCase;

class TradeLevelServiceTest extends TestCase
{
    public function test_it_appends_buy_trade_levels_using_provided_24h_change(): void
    {
        $service = new TradeLevelService;

        $result = $service->appendTradeLevels(
            $this->decision('BUY', 72, 'valid buy'),
            10.0,
            4.0,
            true,
        );

        $this->assertSame(10.0, $result['entry']);
        $this->assertSame(10.6, $result['take_profit']);
        $this->assertSame(9.6, $result['stop_loss']);
    }

    public function test_it_appends_sell_trade_levels_with_default_fallback_volatility(): void
    {
        $service = new TradeLevelService;

        $result = $service->appendTradeLevels(
            $this->decision('SELL', 75, 'valid sell'),
            100.0,
            null,
            true,
        );

        $this->assertSame(100.0, $result['entry']);
        $this->assertSame(97.0, $result['take_profit']);
        $this->assertSame(102.0, $result['stop_loss']);
    }

    public function test_it_normalizes_volatility_using_absolute_value_and_upper_bound(): void
    {
        $service = new TradeLevelService;

        $result = $service->appendTradeLevels(
            $this->decision('BUY', 80, 'volatile market'),
            0.01234567,
            -9.0,
            true,
        );

        $this->assertSame(0.012346, $result['entry']);
        $this->assertSame(0.013272, $result['take_profit']);
        $this->assertSame(0.011728, $result['stop_loss']);
    }

    public function test_it_does_not_append_trade_levels_for_unconfirmed_or_hold_decisions(): void
    {
        $service = new TradeLevelService;

        $unconfirmed = $service->appendTradeLevels(
            $this->decision('BUY', 60, 'not confirmed'),
            20.0,
            2.5,
            false,
        );

        $hold = $service->appendTradeLevels(
            $this->decision('HOLD', 80, 'hold signal'),
            20.0,
            2.5,
            true,
        );

        $this->assertArrayNotHasKey('entry', $unconfirmed);
        $this->assertArrayNotHasKey('take_profit', $unconfirmed);
        $this->assertArrayNotHasKey('stop_loss', $unconfirmed);

        $this->assertArrayNotHasKey('entry', $hold);
        $this->assertArrayNotHasKey('take_profit', $hold);
        $this->assertArrayNotHasKey('stop_loss', $hold);
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
