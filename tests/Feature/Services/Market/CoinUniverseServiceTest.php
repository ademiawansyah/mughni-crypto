<?php

namespace Tests\Feature\Services\Market;

use App\Services\External\BinanceFuturesService;
use App\Services\External\CoinGeckoService;
use App\Services\Market\CoinUniverseService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CoinUniverseServiceTest extends TestCase
{
    public function test_it_filters_candidates_with_open_interest_threshold_and_binance_perpetual_requirement(): void
    {
        Cache::forget('coin_universe:main');

        $coinGeckoService = $this->createMock(CoinGeckoService::class);
        $coinGeckoService->expects($this->once())
            ->method('fetchCoinMarkets')
            ->willReturn([
                [
                    'id' => 'bitcoin',
                    'symbol' => 'BTC',
                    'market_cap' => 1_000_000_000.0,
                    'total_volume' => 10_000_000.0,
                    'current_price' => 60_000.0,
                ],
                [
                    'id' => 'ethereum',
                    'symbol' => 'ETH',
                    'market_cap' => 500_000_000.0,
                    'total_volume' => 8_000_000.0,
                    'current_price' => 3_000.0,
                ],
            ]);

        $binanceFuturesService = $this->createMock(BinanceFuturesService::class);
        $binanceFuturesService->expects($this->exactly(2))
            ->method('hasPerpetualUsdtSymbol')
            ->willReturnMap([
                ['BTCUSDT', true],
                ['ETHUSDT', true],
            ]);

        $binanceFuturesService->expects($this->exactly(2))
            ->method('fetchOpenInterestUsd')
            ->willReturnMap([
                ['BTCUSDT', 60000.0, 2_500_000.0],
                ['ETHUSDT', 3000.0, 400_000.0],
            ]);

        $service = new CoinUniverseService($coinGeckoService, $binanceFuturesService);

        $result = $service->updateUniverse('exec-universe-test');

        $this->assertCount(1, $result);
        $this->assertSame('bitcoin', $result[0]['coin']);
        $this->assertTrue($result[0]['has_binance_futures']);
        $this->assertGreaterThanOrEqual(1_000_000.0, $result[0]['open_interest_usd']);
    }
}
