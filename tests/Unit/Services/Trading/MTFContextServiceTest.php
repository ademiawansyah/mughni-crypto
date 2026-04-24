<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\ConfigService;
use App\Services\Trading\MTFContextService;
use App\Services\Trading\MTFResultDTO;
use PHPUnit\Framework\TestCase;

class MTFContextServiceTest extends TestCase
{
    public function test_it_computes_weighted_score_direction_and_alignment_from_configured_timeframes(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $configService
            ->method('getTimeframes')
            ->willReturn(['5m', '15m', '30m', '60m']);

        $service = new MTFContextService($configService);

        $result = new MTFResultDTO(
            mtfScore: 0.0,
            preliminaryAction: 'HOLD',
            baseConfidence: 50,
            mode: 'trend_follow',
            flags: [],
            timeframeSignals: [
                '5m' => ['timeframe' => '5m', 'rsi' => 60.0, 'trend' => 'UP', 'mcp_score' => 4, 'signal_type' => 'trend_follow'],
                '15m' => ['timeframe' => '15m', 'rsi' => 62.0, 'trend' => 'UP', 'mcp_score' => 3, 'signal_type' => 'trend_follow'],
                '30m' => ['timeframe' => '30m', 'rsi' => 58.0, 'trend' => 'UP', 'mcp_score' => 2, 'signal_type' => 'trend_follow'],
                '60m' => ['timeframe' => '60m', 'rsi' => 65.0, 'trend' => 'UP', 'mcp_score' => 1, 'signal_type' => 'trend_follow'],
            ],
            roleTimeframes: ['trigger' => '5m', 'setup' => '15m', 'context' => '30m', 'direction' => '60m'],
        );

        $context = $service->buildDto($result);

        $this->assertSame(2.75, $context->mtfScore);
        $this->assertSame('BUY', $context->direction);
        $this->assertSame('trend_follow', $context->mode);
        $this->assertSame('aligned', $context->alignment);
        $this->assertSame('bullish', $context->bias);
        $this->assertSame([], $context->flags);
    }

    public function test_it_applies_htf_conflict_penalty_and_sets_reversal_mode_when_rsi_is_extreme(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $configService
            ->method('getTimeframes')
            ->willReturn(['5m', '15m', '30m', '60m']);

        $service = new MTFContextService($configService);

        $result = new MTFResultDTO(
            mtfScore: 0.0,
            preliminaryAction: 'HOLD',
            baseConfidence: 50,
            mode: 'trend_follow',
            flags: ['preexisting_flag'],
            timeframeSignals: [
                '5m' => ['timeframe' => '5m', 'rsi' => 20.0, 'trend' => 'UP', 'mcp_score' => 4, 'signal_type' => 'reversal'],
                '15m' => ['timeframe' => '15m', 'rsi' => 56.0, 'trend' => 'UP', 'mcp_score' => 4, 'signal_type' => 'trend_follow'],
                '30m' => ['timeframe' => '30m', 'rsi' => 54.0, 'trend' => 'UP', 'mcp_score' => 3, 'signal_type' => 'trend_follow'],
                '60m' => ['timeframe' => '60m', 'rsi' => 66.0, 'trend' => 'DOWN', 'mcp_score' => 4, 'signal_type' => 'trend_follow'],
            ],
            roleTimeframes: ['trigger' => '5m', 'setup' => '15m', 'context' => '30m', 'direction' => '60m'],
        );

        $context = $service->buildDto($result);

        $this->assertSame(2.8, $context->mtfScore);
        $this->assertSame('BUY', $context->direction);
        $this->assertSame('reversal', $context->mode);
        $this->assertSame('conflict', $context->alignment);
        $this->assertSame('bullish', $context->bias);
        $this->assertSame(['preexisting_flag', 'htf_conflict'], $context->flags);
    }
}
