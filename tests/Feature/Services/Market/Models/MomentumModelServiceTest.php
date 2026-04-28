<?php

namespace Tests\Feature\Services\Market\Models;

use App\Services\Market\CoinUniverseService;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\MomentumModelService;
use Tests\TestCase;

class MomentumModelServiceTest extends TestCase
{
    public function test_it_builds_a_buy_signal_for_aligned_momentum(): void
    {
        $coinUniverseService = $this->createMock(CoinUniverseService::class);
        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->method('getLatestRegime')->willReturn([
            'market_regime' => 'TRENDING_UP',
            'btc_direction' => 'UP',
        ]);

        $service = new MomentumModelService($coinUniverseService, $marketRegimeService);

        $signal = $service->evaluateCoin('ethereum', [
            '15m' => ['trend' => 'uptrend'],
            '1h' => ['trend' => 'uptrend', 'volume_ratio' => 1.6],
            '4h' => ['trend' => 'uptrend', 'rsi' => 59.0],
            '1d' => ['trend' => 'uptrend'],
        ]);

        $this->assertNotNull($signal);
        $this->assertSame('BUY', $signal->action);
        $this->assertGreaterThanOrEqual(55, $signal->score);
        $this->assertSame(1.0, $signal->componentScores['ema']);
    }

    public function test_it_rejects_a_coin_when_timeframes_are_not_aligned(): void
    {
        $coinUniverseService = $this->createMock(CoinUniverseService::class);
        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->method('getLatestRegime')->willReturn([
            'market_regime' => 'RANGING',
            'btc_direction' => 'SIDEWAYS',
        ]);

        $service = new MomentumModelService($coinUniverseService, $marketRegimeService);

        $signal = $service->evaluateCoin('ethereum', [
            '15m' => ['trend' => 'uptrend'],
            '1h' => ['trend' => 'downtrend', 'volume_ratio' => 1.6],
            '4h' => ['trend' => 'uptrend', 'rsi' => 59.0],
            '1d' => ['trend' => 'uptrend'],
        ]);

        $this->assertNull($signal);
    }
}
