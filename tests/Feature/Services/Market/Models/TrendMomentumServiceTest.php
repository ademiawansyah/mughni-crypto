<?php

namespace Tests\Feature\Services\Market\Models;

use App\Models\Coin;
use App\Models\ModelScanResult;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\TrendMomentumService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TrendMomentumServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_uses_market_regime_for_futures_data_and_persists_result(): void
    {
        Coin::query()->create([
            'symbol' => 'TEST',
            'name' => 'Test Coin',
            'market_cap' => 150_000_000,
            'total_volume' => 75_000_000,
            'volume_24h' => 75_000_000,
            'current_price' => 200,
            'is_valid' => true,
            'raw_data' => [
                'market_cap_rank' => 20,
            ],
        ]);

        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->expects($this->exactly(2))
            ->method('getOhlcvDataForCoin')
            ->willReturnOnConsecutiveCalls(
                $this->trendKlines(),
                $this->entryKlines(),
            );
        $marketRegimeService->expects($this->once())
            ->method('getOpenInterestHistoryForCoin')
            ->willReturn([
                ['sumOpenInterest' => 100.0, 'timestamp' => 1_700_000_000_000],
                ['sumOpenInterest' => 110.0, 'timestamp' => 1_700_014_400_000],
            ]);
        $marketRegimeService->expects($this->once())
            ->method('getCvdMetricsForCoin')
            ->willReturn([
                'cvd' => 1500.0,
                'cvd_slope' => 10.0,
            ]);

        $service = new TrendMomentumService(
            $marketRegimeService,
            new ModelOutputStoreService,
        );

        $result = $service->execute('exec-trend-momentum-service-test');

        $this->assertSame('momentum', $result['model']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('TESTUSDT', $result['results'][0]['symbol']);

        $stored = ModelScanResult::query()
            ->where('model_name', 'momentum')
            ->where('execution_id', 'exec-trend-momentum-service-test')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame(1, $stored->supporting_data['shortlisted']);
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function trendKlines(): array
    {
        $klines = [];

        for ($index = 0; $index < 260; $index++) {
            $open = 100 + ($index * 0.5);
            $close = $open + 0.2;
            $high = $close + 0.3;
            $low = $open - 0.3;

            $klines[] = [
                1_700_000_000_000 + ($index * 86_400_000),
                (string) $open,
                (string) $high,
                (string) $low,
                (string) $close,
                '1000',
            ];
        }

        return $klines;
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function entryKlines(): array
    {
        $klines = [];

        for ($index = 0; $index < 160; $index++) {
            $open = 150 + ($index * 0.15);
            $close = $open + 0.1;
            $high = $close + 0.2;
            $low = $open - 0.2;

            $klines[] = [
                1_700_500_000_000 + ($index * 14_400_000),
                (string) $open,
                (string) $high,
                (string) $low,
                (string) $close,
                '1200',
            ];
        }

        return $klines;
    }
}
