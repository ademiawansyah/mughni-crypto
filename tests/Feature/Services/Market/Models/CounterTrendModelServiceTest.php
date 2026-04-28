<?php

namespace Tests\Feature\Services\Market\Models;

use App\Services\Market\CoinUniverseService;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\CounterTrendModelService;
use Tests\TestCase;

class CounterTrendModelServiceTest extends TestCase
{
    public function test_it_builds_a_buy_signal_for_a_strong_counter_trend_reversal(): void
    {
        $coinUniverseService = $this->createMock(CoinUniverseService::class);
        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->method('getLatestRegime')->willReturn([
            'market_regime' => 'RANGING',
            'btc_direction' => 'SIDEWAYS',
        ]);

        $service = new CounterTrendModelService($coinUniverseService, $marketRegimeService);

        $signal = $service->evaluateCoin('ethereum', [
            '15m' => ['rsi' => 24.0, 'trend' => 'uptrend', 'volume_ratio' => 1.8],
            '1h' => ['trend' => 'uptrend'],
            '4h' => ['trend' => 'downtrend', 'volatility' => 0.05],
            '1d' => ['trend' => 'downtrend'],
        ]);

        $this->assertNotNull($signal);
        $this->assertSame('BUY', $signal->action);
        $this->assertGreaterThanOrEqual(60, $signal->score);
        $this->assertSame(0.0, $signal->componentScores['oi']);
        $this->assertContains('market_structure_shift', $signal->reasons);
    }

    public function test_it_rejects_a_coin_without_a_reversal_extreme(): void
    {
        $coinUniverseService = $this->createMock(CoinUniverseService::class);
        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->method('getLatestRegime')->willReturn([
            'market_regime' => 'RANGING',
            'btc_direction' => 'SIDEWAYS',
        ]);

        $service = new CounterTrendModelService($coinUniverseService, $marketRegimeService);

        $signal = $service->evaluateCoin('ethereum', [
            '15m' => ['rsi' => 49.0, 'trend' => 'uptrend', 'volume_ratio' => 1.0],
            '1h' => ['trend' => 'uptrend'],
            '4h' => ['trend' => 'uptrend', 'volatility' => 0.02],
            '1d' => ['trend' => 'uptrend'],
        ]);

        $this->assertNull($signal);
    }
}
