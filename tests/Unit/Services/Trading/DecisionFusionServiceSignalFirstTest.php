<?php

namespace Tests\Unit\Services\Trading;

use App\Enums\ActionCandidate;
use App\Enums\MarketTrend;
use App\Services\MCP\McpResult;
use App\Services\Trading\DecisionFusionService;
use App\Services\Trading\DTO\AiDecisionDTO;
use App\Services\Trading\DTO\MTFContextDTO;
use App\Services\Trading\DTO\TimeframeSignalDTO;
use Tests\TestCase;

class DecisionFusionServiceSignalFirstTest extends TestCase
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
            '15m' => null,
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
                new TimeframeSignalDTO(timeframe: '15m', rsi: 50.0, trend: 'NEUTRAL', mcpScore: 0, signalType: 'neutral'),
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
            '15m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '15m',
                actionCandidate: ActionCandidate::Buy,
                score: 0,
                trend: MarketTrend::Up,
                rsi: 55.0,
                emaTrend: 'bullish',
                volumeRatio: 1.0,
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
                new TimeframeSignalDTO(timeframe: '15m', rsi: 55.0, trend: 'UP', mcpScore: 0, signalType: 'neutral'),
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

        // Confidence becomes 55 - 15 = 40, which falls below 45 guardrail, so action converts to HOLD
        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(0, $result['confidence']);
        $this->assertContains('mtf_conflict_strong', $result['flags']);
        $this->assertContains('guardrail_low_confidence', $result['flags']);
    }

    public function test_ai_aligned_uses_max_of_base_and_ai_confidence(): void
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

        $aiDecision = new AiDecisionDTO(
            action: 'BUY',
            confidence: 75,
            reason: 'AI agrees',
            validationFlags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, $aiDecision);

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(75, $result['confidence']); // max(55, 75) = 75
        $this->assertContains('ai_aligned', $result['flags']);
    }

    public function test_ai_says_hold_but_trigger_says_buy_discards_ai(): void
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

        $aiDecision = new AiDecisionDTO(
            action: 'HOLD',
            confidence: 0,
            reason: 'AI too cautious',
            validationFlags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, $aiDecision);

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(55, $result['confidence']); // Kept base_confidence
    }

    public function test_ai_disagrees_discards_ai_confidence(): void
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

        $aiDecision = new AiDecisionDTO(
            action: 'SELL',
            confidence: 80,
            reason: 'AI says sell',
            validationFlags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, $aiDecision);

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(55, $result['confidence']); // Kept base_confidence
        $this->assertContains('ai_conflict', $result['flags']);
    }

    public function test_guardrail_converts_low_confidence_to_hold(): void
    {
        $mcpResults = [
            '5m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '5m',
                actionCandidate: ActionCandidate::Buy,
                score: 1,
                trend: MarketTrend::Up,
                rsi: 39.0,
                emaTrend: 'bullish',
                volumeRatio: 1.0,
                currentPrice: 50000.0,
            ),
        ];

        $mtfContext = new MTFContextDTO(
            mtfScore: -1.6, // Strong conflict
            mtfRawScore: 0.0,
            direction: 'SELL',
            mode: 'trend_follow',
            alignment: 'conflict',
            bias: 'bearish',
            timeframeSignals: [
                new TimeframeSignalDTO(timeframe: '5m', rsi: 39.0, trend: 'UP', mcpScore: 1, signalType: 'neutral'),
            ],
            flags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, null);

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(0, $result['confidence']);
        $this->assertContains('guardrail_low_confidence', $result['flags']);
    }

    public function test_returns_trigger_timeframe_in_output(): void
    {
        $mcpResults = [
            '5m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '5m',
                actionCandidate: ActionCandidate::Sell,
                score: 0,
                trend: MarketTrend::Down,
                rsi: 72.0,
                emaTrend: 'bearish',
                volumeRatio: 1.2,
                currentPrice: 50000.0,
            ),
            '15m' => new McpResult(
                symbol: 'bitcoin',
                timeframe: '15m',
                actionCandidate: ActionCandidate::Sell,
                score: 3,
                trend: MarketTrend::Down,
                rsi: 75.0,
                emaTrend: 'bearish',
                volumeRatio: 1.3,
                currentPrice: 50000.0,
            ),
        ];

        $mtfContext = new MTFContextDTO(
            mtfScore: -2.0,
            mtfRawScore: 0.0,
            direction: 'SELL',
            mode: 'reversal',
            alignment: 'aligned',
            bias: 'bearish',
            timeframeSignals: [
                new TimeframeSignalDTO(timeframe: '5m', rsi: 72.0, trend: 'DOWN', mcpScore: 0, signalType: 'reversal'),
                new TimeframeSignalDTO(timeframe: '15m', rsi: 75.0, trend: 'DOWN', mcpScore: 3, signalType: 'reversal'),
            ],
            flags: [],
        );

        $result = $this->service->fuse($mcpResults, $mtfContext, null);

        $this->assertSame('SELL', $result['action']);
        $this->assertSame('15m', $result['trigger_timeframe']);
    }
}
