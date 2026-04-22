<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\DecisionFusionService;
use PHPUnit\Framework\TestCase;

class DecisionFusionServiceTest extends TestCase
{
    public function test_it_boosts_confidence_when_ai_action_aligns_with_mtf_bias(): void
    {
        $service = new DecisionFusionService;

        $result = $service->fuse(
            [
                'action' => 'BUY',
                'confidence' => 65,
                'risk_level' => 'MEDIUM',
                'reason' => 'ai buy',
                'flags' => [],
            ],
            [
                'mtf_score' => 2.5,
                'alignment' => 'aligned',
                'context_bias' => 'bullish',
                'mode' => 'trend_follow',
                'base_confidence' => 60,
                'flags' => [],
            ],
        );

        $this->assertSame('BUY', $result['action']);
        $this->assertGreaterThan(65, $result['confidence']);
        $this->assertSame('aligned', $result['mtf_alignment']);
    }

    public function test_it_reduces_confidence_when_ai_action_opposes_mtf_bias(): void
    {
        $service = new DecisionFusionService;

        $result = $service->fuse(
            [
                'action' => 'BUY',
                'confidence' => 70,
                'risk_level' => 'LOW',
                'reason' => 'ai buy',
            ],
            [
                'mtf_score' => -3.0,
                'alignment' => 'contradictory',
                'context_bias' => 'bearish',
                'mode' => 'reversal',
                'base_confidence' => 55,
                'flags' => [],
            ],
        );

        $this->assertSame('BUY', $result['action']);
        $this->assertLessThan(70, $result['confidence']);
        $this->assertSame('opposed', $result['mtf_alignment']);
    }

    public function test_it_keeps_hold_confidence_unchanged(): void
    {
        $service = new DecisionFusionService;

        $result = $service->fuse(
            [
                'action' => 'HOLD',
                'confidence' => 52,
                'risk_level' => 'MEDIUM',
                'reason' => 'no edge',
            ],
            [
                'mtf_score' => 4.0,
                'alignment' => 'aligned',
                'context_bias' => 'bullish',
                'mode' => 'trend_follow',
                'base_confidence' => 65,
                'flags' => [],
            ],
        );

        $this->assertSame('HOLD', $result['action']);
        $this->assertSame(52, $result['confidence']);
        $this->assertSame(0, $result['confidence_delta']);
    }
}
