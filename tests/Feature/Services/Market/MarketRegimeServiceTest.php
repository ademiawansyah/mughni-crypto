<?php

namespace Tests\Feature\Services\Market;

use App\Services\Market\MarketRegimeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketRegimeServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_detects_and_caches_market_regime(): void
    {
        $this->insertBitcoinIndicator('1h', 64000, 65, 64200, 64000, 1000000, now()->subHour());
        $this->insertBitcoinIndicator('4h', 64000, 62, 64100, 63900, 900000, now()->subHours(4));
        $this->insertBitcoinIndicator('1d', 64000, 60, 64050, 63800, 800000, now()->subDay());

        $service = app(MarketRegimeService::class);
        $result = $service->detectRegime('exec-market-regime');

        $this->assertSame('TRENDING_UP', $result['market_regime']);
        $this->assertSame('UP', $result['btc_direction']);
        $this->assertArrayHasKey('risk_level', $result);

        $cached = Cache::get('market_context:latest');

        $this->assertIsArray($cached);
        $this->assertSame($result, $cached);
        $this->assertSame($result, $service->getLatestRegime());
    }

    public function test_it_returns_default_regime_when_no_btc_data_exists(): void
    {
        $service = app(MarketRegimeService::class);

        $result = $service->detectRegime('exec-default-regime');

        $this->assertSame('RANGING', $result['market_regime']);
        $this->assertSame('SIDEWAYS', $result['btc_direction']);
        $this->assertSame('MEDIUM', $result['volatility']);
    }

    private function insertBitcoinIndicator(
        string $timeframe,
        float $price,
        float $rsi,
        float $ema9,
        float $ema21,
        float $volume,
        \DateTimeInterface $timestamp,
    ): void {
        DB::table('market_indicators')->insert([
            'coin' => 'bitcoin',
            'timeframe' => $timeframe,
            'price' => $price,
            'rsi' => $rsi,
            'ema9' => $ema9,
            'ema21' => $ema21,
            'volume' => $volume,
            'volume_ma' => $volume,
            'trend' => 'UP',
            'source' => 'test',
            'timestamp' => $timestamp,
            'created_at' => now(),
        ]);
    }
}
