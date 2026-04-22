<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AiAdvisorService;
use App\Services\AI\AIPromptService;
use App\Services\AI\AiResponseParser;
use App\Services\AI\LmStudioClient;
use PHPUnit\Framework\TestCase;

class AiAdvisorServiceCalibrationTest extends TestCase
{
    public function test_score_one_weak_signal_is_forced_to_hold_when_calibrated_confidence_is_below_55(): void
    {
        $service = $this->makeService();

        $decision = $this->callFinalizeDecision($service, [
            'action' => 'BUY',
            'confidence' => 30,
            'risk_level' => 'LOW',
            'reason' => 'test',
        ], 1);

        $this->assertSame(50, $decision['confidence']);
        $this->assertSame('HOLD', $decision['action']);
    }

    public function test_score_one_keeps_action_when_calibrated_confidence_reaches_55_or_higher(): void
    {
        $service = $this->makeService();

        $decision = $this->callFinalizeDecision($service, [
            'action' => 'BUY',
            'confidence' => 55,
            'risk_level' => 'LOW',
            'reason' => 'test',
        ], 1);

        $this->assertSame(55, $decision['confidence']);
        $this->assertSame('BUY', $decision['action']);
    }

    public function test_score_two_clamps_to_band(): void
    {
        $service = $this->makeService();

        $lowDecision = $this->callFinalizeDecision($service, [
            'action' => 'SELL',
            'confidence' => 10,
            'risk_level' => 'MEDIUM',
            'reason' => 'test',
        ], 2);

        $highDecision = $this->callFinalizeDecision($service, [
            'action' => 'SELL',
            'confidence' => 95,
            'risk_level' => 'MEDIUM',
            'reason' => 'test',
        ], 2);

        $this->assertSame(55, $lowDecision['confidence']);
        $this->assertSame(70, $highDecision['confidence']);
    }

    public function test_score_three_clamps_to_band(): void
    {
        $service = $this->makeService();

        $decision = $this->callFinalizeDecision($service, [
            'action' => 'BUY',
            'confidence' => 90,
            'risk_level' => 'LOW',
            'reason' => 'test',
        ], 3);

        $this->assertSame(80, $decision['confidence']);
    }

    public function test_score_four_or_higher_clamps_to_band(): void
    {
        $service = $this->makeService();

        $decision = $this->callFinalizeDecision($service, [
            'action' => 'BUY',
            'confidence' => 60,
            'risk_level' => 'LOW',
            'reason' => 'test',
        ], 4);

        $this->assertSame(70, $decision['confidence']);
    }

    private function makeService(): AiAdvisorService
    {
        return new AiAdvisorService(
            $this->createMock(LmStudioClient::class),
            $this->createMock(AiResponseParser::class),
            $this->createMock(AIPromptService::class),
        );
    }

    /**
     * @param  array{action: string, confidence: int, risk_level: string, reason: string}  $decision
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function callFinalizeDecision(AiAdvisorService $service, array $decision, int $score): array
    {
        $reflectionMethod = new \ReflectionMethod(AiAdvisorService::class, 'finalizeDecision');
        $reflectionMethod->setAccessible(true);

        /** @var array{action: string, confidence: int, risk_level: string, reason: string} $result */
        $result = $reflectionMethod->invoke($service, $decision, $score);

        return $result;
    }
}
