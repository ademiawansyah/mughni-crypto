<?php

namespace Tests\Feature\Services\Market;

use App\Models\Coin;
use App\Services\External\BinanceFuturesService;
use App\Services\Market\MarketRegimeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketRegimeServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_persists_latest_funding_rate_when_response_is_available(): void
    {
        Cache::flush();

        $coin = Coin::query()->create([
            'symbol' => 'btc',
            'name' => 'Bitcoin',
            'market_cap' => 1_000_000_000,
            'total_volume' => 100_000_000,
            'volume_24h' => 100_000_000,
            'current_price' => 65_000,
            'is_valid' => true,
            'raw_data' => ['market_cap_rank' => 1],
        ]);

        $binanceFuturesService = $this->createMock(BinanceFuturesService::class);
        $binanceFuturesService->method('fetchLatestFundingRate')->willReturn([
            'funding_rate' => 0.0015,
            'request_params' => ['symbol' => 'BTCUSDT', 'limit' => 1],
            'raw_response' => ['rows' => [['fundingRate' => '0.0015']]],
        ]);
        app()->instance(BinanceFuturesService::class, $binanceFuturesService);

        $service = app(MarketRegimeService::class);
        $payload = $service->getLatestFundingRateForCoin('BTCUSDT');

        $this->assertNotNull($payload);
        $this->assertSame(0.0015, $payload['funding_rate']);

        $this->assertDatabaseHas('coin_market_data', [
            'data_type' => 'funding_rate',
            'source' => 'binance_futures',
            'interval' => 'latest',
        ]);
    }

    public function test_it_persists_open_interest_history_when_response_is_available(): void
    {
        Cache::flush();

        $coin = Coin::query()->create([
            'symbol' => 'eth',
            'name' => 'Ethereum',
            'market_cap' => 800_000_000,
            'total_volume' => 80_000_000,
            'volume_24h' => 80_000_000,
            'current_price' => 3_500,
            'is_valid' => true,
            'raw_data' => ['market_cap_rank' => 2],
        ]);

        $binanceFuturesService = $this->createMock(BinanceFuturesService::class);
        $binanceFuturesService->method('fetchOpenInterestHistory')->willReturn([
            ['sumOpenInterest' => 1000.0, 'timestamp' => 1_700_000_000_000],
            ['sumOpenInterest' => 1100.0, 'timestamp' => 1_700_003_600_000],
        ]);
        app()->instance(BinanceFuturesService::class, $binanceFuturesService);

        $service = app(MarketRegimeService::class);
        $payload = $service->getOpenInterestHistoryForCoin('ETHUSDT', '1h', 5);

        $this->assertNotNull($payload);
        $this->assertCount(2, $payload);

        $this->assertDatabaseHas('coin_market_data', [
            'data_type' => 'oi_history',
            'source' => 'binance_futures',
            'interval' => '1h',
        ]);
    }
}
