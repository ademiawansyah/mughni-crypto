<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\ConfigService;
use App\Services\Trading\SignalActivationService;
use PHPUnit\Framework\TestCase;

class SignalActivationServiceTest extends TestCase
{
    public function test_it_applies_balanced_activation_adjustments(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $service = new SignalActivationService($configService);

        $adjusted = $service->adjust(1.0, 65, 'balanced');

        $this->assertSame(1.1, $adjusted['adjusted_score']);
        $this->assertSame(65, $adjusted['adjusted_confidence']);
        $this->assertSame(0.8, $adjusted['trigger_threshold']);
        $this->assertSame('balanced', $adjusted['mode']);
    }

    public function test_it_applies_conservative_activation_adjustments(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $service = new SignalActivationService($configService);

        $adjusted = $service->adjust(1.8, 70, 'conservative');

        $this->assertSame(1.44, $adjusted['adjusted_score']);
        $this->assertSame(60, $adjusted['adjusted_confidence']);
        $this->assertSame(1.5, $adjusted['trigger_threshold']);
        $this->assertSame('conservative', $adjusted['mode']);
    }

    public function test_it_applies_aggressive_activation_adjustments(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $service = new SignalActivationService($configService);

        $adjusted = $service->adjust(1.0, 65, 'aggressive');

        $this->assertSame(1.2, $adjusted['adjusted_score']);
        $this->assertSame(75, $adjusted['adjusted_confidence']);
        $this->assertSame(0.8, $adjusted['trigger_threshold']);
        $this->assertSame('aggressive', $adjusted['mode']);
    }
}
