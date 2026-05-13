<?php

namespace Tests\Feature\Services\Market\Models;

use App\Models\Coin;
use App\Models\ModelScanResult;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\CounterTrendService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CounterTrendServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_persists_standard_output_and_supporting_data_for_counter_trend_execution(): void
    {
        Coin::query()->create([
            'symbol' => 'TEST',
            'name' => 'Test Coin',
            'market_cap' => 100_000_000,
            'total_volume' => 6_000_000,
            'volume_24h' => 6_000_000,
            'current_price' => 101.25,
            'is_valid' => true,
            'raw_data' => [
                'market_cap_rank' => 100,
            ],
        ]);

        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->expects($this->once())
            ->method('hasPerpetualUsdtSymbol')
            ->with('TESTUSDT')
            ->willReturn(true);

        $marketRegimeService->expects($this->exactly(3))
            ->method('getFuturesOhlcvDataForCoin')
            ->withAnyParameters()
            ->willReturnMap([
                ['TESTUSDT', '4h', 100, $this->structureKlines()],
                ['TESTUSDT', '15m', 100, $this->entryKlines()],
                ['TESTUSDT', '1d', 10, $this->macroKlines()],
            ]);

        $marketRegimeService->expects($this->once())
            ->method('getCounterTrendOpenInterestHistoryForCoin')
            ->willReturn([
                ['timestamp' => 1_700_000_000, 'open_interest' => 1000.0],
                ['timestamp' => 1_700_003_600, 'open_interest' => 920.0],
            ]);

        $marketRegimeService->expects($this->once())
            ->method('getCounterTrendFundingRateHistoryForCoin')
            ->willReturn([
                ['timestamp' => 1_700_000_000, 'funding_rate' => 0.0002],
                ['timestamp' => 1_700_086_400, 'funding_rate' => 0.0012],
            ]);

        $marketRegimeService->expects($this->once())
            ->method('getCvdMetricsForCoin')
            ->with('TESTUSDT', 500)
            ->willReturn([
                'cvd' => 100_000.0,
                'cvd_slope' => 1.25,
            ]);

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendModelExecutionResult');

        $service = new CounterTrendService(
            $marketRegimeService,
            new ModelOutputStoreService,
            $notificationService,
        );

        $result = $service->execute('exec-counter-trend-test');

        $this->assertSame('counter_trend', $result['model']);
        $this->assertSame(now()->toDateString(), $result['execution_date']);
        $this->assertSame(1, $result['signal_count']);
        $this->assertCount(1, $result['results']);
        $this->assertSame(105.0, $result['results'][0]['total_score']);

        $stored = ModelScanResult::query()
            ->where('model_name', 'counter_trend')
            ->where('execution_id', 'exec-counter-trend-test')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('counter_trend', $stored->model_name);
        $this->assertSame('exec-counter-trend-test', $stored->execution_id);
        $this->assertSame('counter_trend', $stored->result['model']);
        $this->assertSame('2.0', $stored->result['version']);
        $this->assertCount(1, $stored->result['results']);
        $this->assertSame('TESTUSDT', $stored->result['results'][0]['symbol']);
        $this->assertSame('bearish', $stored->result['results'][0]['components']['liquidity_sweep']);
        $this->assertSame('bearish', $stored->result['results'][0]['components']['mss']);
        $this->assertTrue($stored->result['results'][0]['components']['fvg_ob_15m']);
        $this->assertTrue($stored->result['results'][0]['components']['oi_declining']);
        $this->assertTrue($stored->result['results'][0]['components']['extreme_funding']);
        $this->assertTrue($stored->result['results'][0]['components']['cvd_positive']);
        $this->assertFalse($stored->result['results'][0]['components']['derivatives_skipped']);
        $this->assertArrayHasKey('stop_loss', $stored->result['results'][0]['metadata']);
        $this->assertSame('4H', $stored->result['results'][0]['metadata']['structure_timeframe']);
        $this->assertSame('15M', $stored->result['results'][0]['metadata']['entry_timeframe']);
        $this->assertSame('1D', $stored->result['results'][0]['metadata']['macro_timeframe']);
        $this->assertTrue($stored->result['results'][0]['metadata']['macro_aligned']);
        $this->assertTrue($stored->result['results'][0]['metadata']['coinalyze_available']);
        $this->assertSame('binance_futures', $stored->result['results'][0]['metadata']['ohlcv_source']);
        $this->assertFalse($stored->result['results'][0]['metadata']['derivatives_penalty_applied']);
        $this->assertSame(2, $stored->result['results'][0]['metadata']['oi_points']);
        $this->assertSame(2, $stored->result['results'][0]['metadata']['funding_points']);
        $this->assertSame(1.25, $stored->result['results'][0]['metadata']['cvd_slope']);
        $this->assertArrayHasKey('fvg_zone_15m', $stored->result['results'][0]['metadata']);
        $this->assertSame(1, $stored->supporting_data['evaluated']);
        $this->assertSame(1, $stored->supporting_data['shortlisted']);
        $this->assertCount(1, $stored->supporting_data['all_scored_results']);
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function structureKlines(): array
    {
        $klines = [];

        for ($index = 0; $index < 30; $index++) {
            $openTime = 1_700_000_000_000 + ($index * 3_600_000);
            $open = 100.0;
            $high = 105.0;
            $low = 95.0;
            $close = 100.0;

            if ($index === 10) {
                $open = 108.0;
                $high = 120.0;
                $low = 99.0;
                $close = 110.0;
            }

            if ($index === 14) {
                $open = 96.0;
                $high = 102.0;
                $low = 85.0;
                $close = 92.0;
            }

            if ($index === 25) {
                $open = 116.0;
                $high = 125.0;
                $low = 110.0;
                $close = 115.0;
            }

            if ($index === 27) {
                $open = 95.0;
                $high = 100.0;
                $low = 79.0;
                $close = 80.0;
            }

            $klines[] = [
                $openTime,
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
        return [
            [1_700_000_000_000, '85', '90', '80', '88', '1000'],
            [1_700_000_900_000, '88', '92', '87', '91', '1000'],
            [1_700_001_800_000, '95', '98', '94', '96', '1000'],
            [1_700_002_700_000, '97', '98', '95', '96', '1000'],
            [1_700_003_600_000, '95', '96', '92', '94', '1000'],
            [1_700_004_500_000, '93', '94', '91', '92', '1000'],
        ];
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function macroKlines(): array
    {
        return [
            [1_700_000_000_000, '100', '110', '95', '102', '1000'],
            [1_700_086_400_000, '102', '108', '100', '101', '1000'],
            [1_700_172_800_000, '101', '105', '99', '100', '1000'],
        ];
    }
}
