<?php

namespace Tests\Feature\Services\Market\Models;

use App\Models\Coin;
use App\Models\ModelScanResult;
use App\Services\External\CoinGeckoService;
use App\Services\External\CoinMarketCapService;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\SpotMomentumGainerService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SpotMomentumGainerServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_persists_standard_output_and_supporting_data_for_spot_momentum_gainer_execution(): void
    {
        Coin::query()->create([
            'symbol' => 'test',
            'name' => 'Test Coin',
            'market_cap' => 200_000_000,
            'total_volume' => 20_000_000,
            'volume_24h' => 20_000_000,
            'current_price' => 10.5,
            'is_valid' => true,
            'raw_data' => [
                'market_cap_rank' => 80,
            ],
        ]);

        $coinMarketCapService = $this->createMock(CoinMarketCapService::class);
        $coinMarketCapService->expects($this->once())
            ->method('fetchListingsLatest')
            ->with(200)
            ->willReturn([
                [
                    'symbol' => 'TEST',
                    'name' => 'Test Coin',
                    'market_cap' => 200_000_000,
                    'volume_24h' => 30_000_000,
                    'price' => 11.0,
                    'percent_change_24h' => 12.5,
                ],
            ]);

        $coinGeckoService = $this->createMock(CoinGeckoService::class);
        $coinGeckoService->expects($this->never())
            ->method('fetchCoinMarkets');

        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->expects($this->once())
            ->method('getOhlcvDataForCoin')
            ->with('test', '1d', 7)
            ->willReturn($this->passingDailyKlines());

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendSystemMessage');

        $service = new SpotMomentumGainerService(
            $coinMarketCapService,
            $coinGeckoService,
            $marketRegimeService,
            new ModelOutputStoreService,
            $notificationService,
        );

        $result = $service->execute('exec-spot-momentum-service-test');

        $this->assertSame('spot_momentum_gainer', $result['model']);
        $this->assertSame(now()->toDateString(), $result['execution_date']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('TESTUSDT', $result['results'][0]['symbol']);

        $stored = ModelScanResult::query()
            ->where('model_name', 'spot_momentum_gainer')
            ->where('execution_id', 'exec-spot-momentum-service-test')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('spot_momentum_gainer', $stored->result['model']);
        $this->assertSame('2.0', $stored->result['version']);
        $this->assertSame(1, $stored->supporting_data['evaluated']);
        $this->assertSame(1, $stored->supporting_data['shortlisted']);
        $this->assertSame('coinmarketcap', $stored->supporting_data['source_used']);
        $this->assertArrayHasKey('change_24h', $stored->result['results'][0]['components']);
        $this->assertArrayHasKey('volume_ratio', $stored->result['results'][0]['components']);
        $this->assertArrayHasKey('body_ratio', $stored->result['results'][0]['components']);
    }

    public function test_it_uses_coingecko_fallback_and_sends_no_setup_notification_when_gate_fails(): void
    {
        Coin::query()->create([
            'symbol' => 'fail',
            'name' => 'Fail Coin',
            'market_cap' => 300_000_000,
            'total_volume' => 50_000_000,
            'volume_24h' => 50_000_000,
            'current_price' => 12.2,
            'is_valid' => true,
            'raw_data' => [
                'market_cap_rank' => 30,
            ],
        ]);

        $coinMarketCapService = $this->createMock(CoinMarketCapService::class);
        $coinMarketCapService->expects($this->once())
            ->method('fetchListingsLatest')
            ->with(200)
            ->willReturn([]);

        $coinGeckoService = $this->createMock(CoinGeckoService::class);
        $coinGeckoService->expects($this->once())
            ->method('fetchCoinMarkets')
            ->with(1, 200)
            ->willReturn([
                [
                    'symbol' => 'FAIL',
                    'name' => 'Fail Coin',
                    'market_cap' => 300_000_000,
                    'total_volume' => 40_000_000,
                    'current_price' => 11.9,
                    'price_change_percentage_24h' => 9.0,
                ],
            ]);

        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->expects($this->once())
            ->method('getOhlcvDataForCoin')
            ->with('fail', '1d', 7)
            ->willReturn($this->failingDailyKlines());

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendSystemMessage');

        $service = new SpotMomentumGainerService(
            $coinMarketCapService,
            $coinGeckoService,
            $marketRegimeService,
            new ModelOutputStoreService,
            $notificationService,
        );

        $result = $service->execute('exec-spot-momentum-service-fallback-test');

        $this->assertSame('spot_momentum_gainer', $result['model']);
        $this->assertSame(0, $result['shortlisted']);
        $this->assertCount(0, $result['results']);

        $stored = ModelScanResult::query()
            ->where('model_name', 'spot_momentum_gainer')
            ->where('execution_id', 'exec-spot-momentum-service-fallback-test')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('coingecko_fallback', $stored->supporting_data['source_used']);
        $this->assertSame(1, $stored->supporting_data['failed_count']);
        $this->assertCount(1, $stored->supporting_data['failed_coins']);
        $this->assertSame('bullish_gate_failed', $stored->supporting_data['failed_coins'][0]['reason']);
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function passingDailyKlines(): array
    {
        return [
            [1, '8.5', '9.0', '8.2', '8.7', '1000'],
            [2, '8.7', '9.1', '8.5', '8.9', '1050'],
            [3, '8.9', '9.2', '8.6', '9.0', '1100'],
            [4, '9.0', '9.3', '8.8', '9.1', '1150'],
            [5, '9.1', '9.4', '8.9', '9.2', '1200'],
            [6, '9.2', '9.5', '9.0', '9.3', '1250'],
            [7, '9.2', '10.6', '9.1', '10.4', '3200'],
        ];
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function failingDailyKlines(): array
    {
        return [
            [1, '8.5', '9.0', '8.2', '8.7', '1000'],
            [2, '8.7', '9.1', '8.5', '8.9', '1000'],
            [3, '8.9', '9.2', '8.6', '9.0', '1000'],
            [4, '9.0', '9.3', '8.8', '9.1', '1000'],
            [5, '9.1', '9.4', '8.9', '9.2', '1000'],
            [6, '9.2', '9.5', '9.0', '9.3', '1000'],
            [7, '9.3', '9.45', '9.1', '9.28', '900'],
        ];
    }
}
