<?php

namespace Tests\Feature\Services\Market\Models;

use App\Models\Coin;
use App\Models\ModelScanResult;
use App\Services\Market\MarketRegimeService;
use App\Services\Market\Models\PrePumpService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PrePumpServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_persists_standard_output_and_supporting_data_for_pre_pump_execution(): void
    {
        Coin::query()->create([
            'symbol' => 'test',
            'name' => 'Test Coin',
            'market_cap' => 120_000_000,
            'total_volume' => 20_000_000,
            'volume_24h' => 20_000_000,
            'current_price' => 101.25,
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

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendModelExecutionResult');

        $service = new PrePumpService(
            $marketRegimeService,
            new ModelOutputStoreService,
            $notificationService,
        );

        $result = $service->execute('exec-pre-pump-test');

        $this->assertSame('pre_pump', $result['model']);
        $this->assertSame(now()->toDateString(), $result['execution_date']);
        $this->assertCount(1, $result['results']);

        $stored = ModelScanResult::query()
            ->where('model_name', 'pre_pump')
            ->where('execution_id', 'exec-pre-pump-test')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('pre_pump', $stored->model_name);
        $this->assertSame('exec-pre-pump-test', $stored->execution_id);
        $this->assertSame('pre_pump', $stored->result['model']);
        $this->assertSame('2.0', $stored->result['version']);
        $this->assertCount(1, $stored->result['results']);
        $this->assertSame('TESTUSDT', $stored->result['results'][0]['symbol']);
        $this->assertSame(1, $stored->supporting_data['evaluated']);
        $this->assertSame(1, $stored->supporting_data['shortlisted']);
        $this->assertCount(1, $stored->supporting_data['all_scored_results']);
        $this->assertSame([], $stored->supporting_data['failed_coins']);

        // Verify component keys match config scoring keys exactly
        $components = $stored->result['results'][0]['components'];
        $this->assertArrayHasKey('funding', $components);
        $this->assertArrayHasKey('atr_compression', $components);
        $this->assertArrayHasKey('oi', $components);
        $this->assertArrayHasKey('rs', $components);
        $this->assertArrayHasKey('cvd', $components);
    }

    /**
     * 200 × 4H structure klines designed to produce strong Pre-Pump signals:
     * - Phase 1 (150 candles): wide ATR baseline (~16), alternating bull/bear
     * - Phase 2 (38 candles): declining price, ATR compressing via Wilder's smoothing
     * - Phase 3 (12 candles): tight sideways price at ~91, volume surge on last 6
     *
     * Expected scores: funding ≥75 (persistent bearish), atr_compression=100 (extreme),
     * oi=100 (volume +50%, price range <1%), total well above min_score=65.
     *
     * @return array<int, array<int, string|int|float>>
     */
    private function structureKlines(): array
    {
        $klines = [];
        $ts = 1_700_000_000_000;
        $interval = 4 * 3600 * 1000;

        // Phase 1: High-volatility baseline — alternating bull/bear, wide range
        for ($i = 0; $i < 150; $i++) {
            $bull = ($i % 2 === 0);
            $open = '100';
            $close = $bull ? '106' : '94';
            $high = $bull ? '108' : '102';
            $low = $bull ? '98' : '92';
            $klines[] = [$ts + $i * $interval, $open, $high, $low, $close, '1000'];
        }

        // Phase 2: Declining trend, ATR compressing (range ~1.6 per candle)
        for ($i = 0; $i < 38; $i++) {
            $price = 100.0 - $i * 0.24;
            $open = number_format($price, 4);
            $close = number_format($price - 0.3, 4);
            $high = number_format($price + 0.5, 4);
            $low = number_format($price - 0.8, 4);
            $klines[] = [$ts + (150 + $i) * $interval, $open, $high, $low, $close, '1100'];
        }

        // Phase 3: Sideways at ~91, very tight range
        // Prior 6 (volume=1000), Recent 6 (volume=1500), all bearish for funding score
        for ($i = 0; $i < 12; $i++) {
            $isRecent = $i >= 6;
            $open = '91.1';
            $close = '90.85';  // close < open = bearish every candle
            $high = '91.3';
            $low = '90.65';
            $volume = $isRecent ? '1500' : '1000';
            $klines[] = [$ts + (188 + $i) * $interval, $open, $high, $low, $close, $volume];
        }

        return $klines;
    }

    /**
     * 120 × 1H entry klines designed to produce positive RS and CVD scores:
     * - Indices 0–47: fast decline (100 → ~90)
     * - Indices 48–95: moderate decline (90 → ~87), used as RS prior window
     * - Indices 96–119: flat at ~87, mostly bullish candles (CVD accumulation)
     *
     * RS: recent 24H trend (-0.5%) outperforms prior 48H trend (-3.3%) → rsDiff ≈ +0.028 → 85
     * CVD: 16 bullish / 8 bearish in last 24 candles, price sideways → score = 85
     *
     * @return array<int, array<int, string|int|float>>
     */
    private function entryKlines(): array
    {
        $klines = [];
        $ts = 1_700_000_000_000;
        $interval = 3600 * 1000;

        // Indices 0–47: fast decline from 100 to 90
        for ($i = 0; $i < 48; $i++) {
            $close = number_format(100.0 - $i * (10.0 / 47), 4);
            $open = number_format(100.0 - ($i > 0 ? ($i - 1) * (10.0 / 47) : 0), 4);
            $high = number_format((float) $close + 0.1, 4);
            $low = number_format((float) $close - 0.1, 4);
            $klines[] = [$ts + $i * $interval, $open, $high, $low, $close, '800'];
        }

        // Indices 48–95: moderate decline from 90 to ~87
        for ($i = 0; $i < 48; $i++) {
            $close = number_format(90.0 - $i * (3.0 / 47), 4);
            $open = number_format(90.0 - ($i > 0 ? ($i - 1) * (3.0 / 47) : 0), 4);
            $high = number_format((float) $close + 0.1, 4);
            $low = number_format((float) $close - 0.1, 4);
            $klines[] = [$ts + (48 + $i) * $interval, $open, $high, $low, $close, '900'];
        }

        // Indices 96–119: flat at ~87, mostly bullish (2 bull, 1 bear pattern = 16 bull/8 bear)
        for ($i = 0; $i < 24; $i++) {
            $isBullish = ($i % 3 !== 2);  // bear every 3rd candle
            $open = '87.00';
            $close = $isBullish ? '87.02' : '86.98';
            $high = $isBullish ? '87.05' : '87.02';
            $low = $isBullish ? '86.98' : '86.95';
            $klines[] = [$ts + (96 + $i) * $interval, $open, $high, $low, $close, '1000'];
        }

        return $klines;
    }
}
