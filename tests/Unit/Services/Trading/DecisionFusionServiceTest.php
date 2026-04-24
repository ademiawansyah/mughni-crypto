<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\DecisionFusionService;
use Tests\TestCase;

class DecisionFusionServiceTest extends TestCase
{
    public function test_it_uses_mtf_direction_when_mtf_is_strong(): void
    {
        $service = new DecisionFusionService;

        $result = $service->fuse(
            [
                'action' => 'SELL',
                'confidence' => 62,
                'risk_level' => 'MEDIUM',
                'reason' => 'ai sell',
                'flags' => [],
            ],
            [
                'mtf_score' => 2.8,
                'alignment' => 'aligned',
                'context_bias' => 'bullish',
                'mode' => 'trend_follow',
                'base_confidence' => 60,
                'flags' => [],
            ],
        );

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(70, $result['confidence']);
        $this->assertContains('mtf_dominant', $result['flags']);
        $this->assertSame('aligned', $result['mtf_alignment']);
    }

    public function test_it_balances_medium_mtf_and_penalizes_conflict(): void
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
                'mtf_score' => 1.4,
                'alignment' => 'conflict',
                'context_bias' => 'bearish',
                'mode' => 'reversal',
                'base_confidence' => 55,
                'flags' => [],
            ],
        );

        $this->assertSame('BUY', $result['action']);
        $this->assertSame(60, $result['confidence']);
        $this->assertSame('opposed', $result['mtf_alignment']);
    }

    public function test_it_marks_weak_mtf_and_reduces_confidence(): void
    {
        $service = new DecisionFusionService;

        $result = $service->fuse(
            [
                'action' => 'SELL',
                'confidence' => 52,
                'risk_level' => 'MEDIUM',
                'reason' => 'weak sell',
            ],
            [
                'mtf_score' => 0.7,
                'alignment' => 'mixed',
                'context_bias' => 'neutral',
                'mode' => 'trend_follow',
                'base_confidence' => 65,
                'flags' => [],
            ],
        );

        $this->assertSame('SELL', $result['action']);
        $this->assertSame(42, $result['confidence']);
        $this->assertSame(-10, $result['confidence_delta']);
        $this->assertContains('mtf_weak', $result['flags']);
    }
}
