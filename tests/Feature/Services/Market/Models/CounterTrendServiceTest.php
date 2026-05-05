<?php

namespace Tests\Feature\Services\Market\Models;

use App\Models\Coin;
use App\Models\ModelScanResult;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\CounterTrendService;
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
            'current_price' => 101,
            'is_valid' => true,
            'raw_data' => [
                'market_cap_rank' => 100,
            ],
        ]);

        $marketRegimeService = $this->createMock(MarketRegimeService::class);
        $marketRegimeService->expects($this->exactly(2))
            ->method('getOhlcvDataForCoin')
            ->willReturnOnConsecutiveCalls(
                $this->structureKlines(),
                $this->entryKlines(),
            );

        $service = new CounterTrendService(
            $marketRegimeService,
            new ModelOutputStoreService,
        );

        $result = $service->execute('exec-counter-trend-test');

        $this->assertSame('counter_trend', $result['model']);
        $this->assertSame(now()->toDateString(), $result['execution_date']);
        $this->assertCount(1, $result['results']);

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

        for ($index = 0; $index < 29; $index++) {
            $klines[] = [
                1_700_000_000_000 + ($index * 60_000),
                '105',
                '110',
                '100',
                '106',
                '1000',
            ];
        }

        $klines[] = [
            1_700_000_000_000 + (29 * 60_000),
            '100.5',
            '108',
            '99',
            '101',
            '1800',
        ];

        return $klines;
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function entryKlines(): array
    {
        $klines = [];

        for ($index = 0; $index < 27; $index++) {
            $klines[] = [
                1_700_100_000_000 + ($index * 60_000),
                '104',
                '109',
                '101',
                '105',
                '900',
            ];
        }

        $klines[] = [1_700_100_000_000 + (27 * 60_000), '104', '106', '100', '101', '950'];
        $klines[] = [1_700_100_000_000 + (28 * 60_000), '101', '107', '100', '106', '1000'];
        $klines[] = [1_700_100_000_000 + (29 * 60_000), '109', '114', '108', '112', '1500'];

        return $klines;
    }
}
