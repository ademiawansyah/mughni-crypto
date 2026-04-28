<?php

namespace Tests\Unit\Services\Trading;

use App\Enums\ActionCandidate;
use App\Enums\MarketTrend;
use App\Services\MCP\McpResult;
use App\Services\Trading\DecisionFusionService;
use App\Services\Trading\DTO\MTFContextDTO;
use App\Services\Trading\DTO\TimeframeSignalDTO;
use Tests\TestCase;

class DecisionFusionServiceTest extends TestCase
{
    private DecisionFusionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DecisionFusionService;
    }

    public function test_returns_hold_when_no_signal_exists(): void
    {
        $mcpResults = [
            '5m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '5m',
                actionCandidate: ActionCandidate::Buy,
                score: 0,
                trend: MarketTrend::Up,
                rsi: 45.0,
                emaTrend: 'bullish',
                volumeRatio: 1.2,
                currentPrice: 50000.0,
            ),
        ];

        $mtfContext = new MTFContextDTO(
            mtfScore: 0.5,
            mtfRawScore: 0.0,
            direction: 'BUY',
            mode: 'trend_follow',
            alignment: 'mixed',
            bias: 'neutral',
            timeframeSignals: [
                new TimeframeSignalDTO(timeframe: '5m', rsi: 45.0, trend: 'UP', mcpScore: 0, signalType: 'neutral'),
            ],
            flags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, null);

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(0, $result['confidence']);
    }

    public function test_uses_trigger_timeframe_action_with_base_confidence(): void
    {
        $mcpResults = [
            '5m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '5m',
                actionCandidate: ActionCandidate::Buy,
                score: 2,
                trend: MarketTrend::Up,
                rsi: 28.0,
                emaTrend: 'bullish',
                volumeRatio: 1.5,
                currentPrice: 50000.0,
            ),
        ];

        $mtfContext = new MTFContextDTO(
            mtfScore: 1.2,
            mtfRawScore: 0.0,
            direction: 'BUY',
            mode: 'trend_follow',
            alignment: 'aligned',
            bias: 'bullish',
            timeframeSignals: [
                new TimeframeSignalDTO(timeframe: '5m', rsi: 28.0, trend: 'UP', mcpScore: 2, signalType: 'reversal'),
            ],
            flags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, null);

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(55, $result['confidence']);
        $this->assertContains('trigger_5m', $result['flags']);
    }

    public function test_applies_mtf_conflict_penalty(): void
    {
        $mcpResults = [
            '5m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '5m',
                actionCandidate: ActionCandidate::Buy,
                score: 2,
                trend: MarketTrend::Up,
                rsi: 28.0,
                emaTrend: 'bullish',
                volumeRatio: 1.5,
                currentPrice: 50000.0,
            ),
        ];

        $mtfContext = new MTFContextDTO(
            mtfScore: -1.6, // Strong bearish but trigger is BUY
            mtfRawScore: 0.0,
            direction: 'SELL',
            mode: 'trend_follow',
            alignment: 'conflict',
            bias: 'bearish',
            timeframeSignals: [
                new TimeframeSignalDTO(timeframe: '5m', rsi: 28.0, trend: 'UP', mcpScore: 2, signalType: 'reversal'),
            ],
            flags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, null);

        // Confidence becomes 55 - 15 = 40, which falls below 45 guardrail, so action converts to HOLD with confidence 0
        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(0, $result['confidence']);
        $this->assertContains('mtf_conflict_strong', $result['flags']);
        $this->assertContains('guardrail_low_confidence', $result['flags']);
    }
}
