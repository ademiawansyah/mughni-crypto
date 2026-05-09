<?php

namespace Tests\Feature\Services\External;

use App\Services\External\BinanceFuturesService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinanceFuturesServiceTest extends TestCase
{
    public function test_it_calculates_cvd_and_slope_from_agg_trades(): void
    {
        $service = app(BinanceFuturesService::class);

        $metrics = $service->calculateCvdMetrics([
            ['p' => '100', 'q' => '1', 'm' => false],
            ['p' => '101', 'q' => '1', 'm' => false],
            ['p' => '102', 'q' => '0.5', 'm' => true],
        ]);

        $this->assertGreaterThan(0.0, $metrics['cvd']);
        $this->assertGreaterThan(0.0, $metrics['cvd_slope']);
    }

    public function test_it_detects_usdt_perpetual_symbols_from_exchange_info(): void
    {
        config(['market.binance_futures.enabled' => true]);

        Cache::flush();

        Http::fake([
            'https://fapi.binance.com/fapi/v1/exchangeInfo*' => Http::response([
                'symbols' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'status' => 'TRADING',
                        'contractType' => 'PERPETUAL',
                        'quoteAsset' => 'USDT',
                    ],
                    [
                        'symbol' => 'ETHUSD',
                        'status' => 'TRADING',
                        'contractType' => 'CURRENT_QUARTER',
                        'quoteAsset' => 'USD',
                    ],
                ],
            ], 200),
        ]);

        $service = app(BinanceFuturesService::class);

        $this->assertTrue($service->hasPerpetualUsdtSymbol('BTCUSDT'));
        $this->assertFalse($service->hasPerpetualUsdtSymbol('ETHUSD'));
    }
}
