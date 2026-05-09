<?php

namespace Tests\Feature\Services\Market;

use App\Services\External\BinanceFuturesService;
use App\Services\External\CoinGeckoService;
use App\Services\Market\CoinUniverseService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CoinUniverseServiceTest extends TestCase
{
    public function test_it_filters_candidates_with_base_rules_and_returns_ranked_universe(): void
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
        $binanceFuturesService->expects($this->never())
            ->method('hasPerpetualUsdtSymbol');

        $binanceFuturesService->expects($this->never())
            ->method('fetchOpenInterestUsd');

        $service = new CoinUniverseService($coinGeckoService, $binanceFuturesService);

        $result = $service->updateUniverse('exec-universe-test');

        $this->assertCount(2, $result);
        $this->assertSame('bitcoin', $result[0]['coin']);
        $this->assertSame('ethereum', $result[1]['coin']);
        $this->assertSame('BTC', $result[0]['name']);
    }
}
