<?php

namespace Tests\Feature\Services\Market\Models;

use App\Services\Market\CoinUniverseService;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\PrePumpModelService;
use Tests\TestCase;

class PrePumpModelServiceTest extends TestCase
{
    public function test_it_builds_a_buy_signal_for_a_compressed_relative_strength_setup(): void
    {
        $coinUniverseService = $this->createMock(CoinUniverseService::class);
        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->method('getLatestRegime')->willReturn([
            'market_regime' => 'TRENDING_UP',
            'btc_direction' => 'UP',
        ]);

        $service = new PrePumpModelService($coinUniverseService, $marketRegimeService);

        $signal = $service->evaluateCoin('solana', [
            '15m' => ['trend' => 'uptrend'],
            '1h' => ['trend' => 'uptrend', 'rsi' => 58.0, 'volume_ratio' => 1.7],
            '4h' => ['trend' => 'uptrend', 'volatility' => 0.018],
        ]);

        $this->assertNotNull($signal);
        $this->assertSame('BUY', $signal->action);
        $this->assertGreaterThanOrEqual(65, $signal->score);
        $this->assertSame(0.0, $signal->componentScores['funding']);
    }

    public function test_it_rejects_a_coin_without_compression_and_volume_expansion(): void
    {
        $coinUniverseService = $this->createMock(CoinUniverseService::class);
        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->method('getLatestRegime')->willReturn([
            'market_regime' => 'RANGING',
            'btc_direction' => 'SIDEWAYS',
        ]);

        $service = new PrePumpModelService($coinUniverseService, $marketRegimeService);

        $signal = $service->evaluateCoin('solana', [
            '15m' => ['trend' => 'sideways'],
            '1h' => ['trend' => 'sideways', 'rsi' => 49.0, 'volume_ratio' => 1.0],
            '4h' => ['trend' => 'sideways', 'volatility' => 0.06],
        ]);

        $this->assertNull($signal);
    }
}
