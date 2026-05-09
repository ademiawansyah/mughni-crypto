<?php

namespace Tests\Unit\Unit\Services;

use App\Models\ExchangeRate;
use App\Services\External\IndodaxExchangeRateService;
use App\Services\Trading\ExchangeRateRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for ExchangeRateRepository and IndodaxExchangeRateService.
 *
 * Covers:
 * - Fetching rates from Indodax API
 * - CoinGecko fallback behavior
 * - Rate caching and storage
 * - Currency pair conversions
 * - Error handling
 */
class ExchangeRateServiceTest extends TestCase
{
    private ExchangeRateRepository $repository;

    private IndodaxExchangeRateService $indodaxService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indodaxService = new IndodaxExchangeRateService();
        $this->repository = new ExchangeRateRepository($this->indodaxService);

        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test fetching USD to IDR rate from Indodax API.
     */
    public function test_fetch_usd_to_idr_from_indodax(): void
    {
        Http::fake([
            'indodax.com/api/ticker/usdt_idr' => Http::response([
                'ticker' => [
                    'last' => '15850.50',
                ],
            ]),
        ]);

        $rate = $this->indodaxService->getUsdToIdrRate();

        $this->assertNotNull($rate);
        $this->assertEquals(15850.50, $rate);
    }

    /**
     * Test Indodax API failure returns null.
     */
    public function test_indodax_api_failure_returns_null(): void
    {
        Http::fake([
            'indodax.com/api/ticker/usdt_idr' => Http::response([], 500),
        ]);

        $rate = $this->indodaxService->getUsdToIdrRate();

        $this->assertNull($rate);
    }

    /**
     * Test Indodax API with invalid response structure returns null.
     */
    public function test_indodax_invalid_response_returns_null(): void
    {
        Http::fake([
            'indodax.com/api/ticker/usdt_idr' => Http::response([
                'unexpected' => 'structure',
            ]),
        ]);

        $rate = $this->indodaxService->getUsdToIdrRate();

        $this->assertNull($rate);
    }

    /**
     * Test storing and retrieving exchange rate from database.
     */
    public function test_store_and_retrieve_exchange_rate(): void
    {
        $this->repository->storeRate('USD', 'IDR', 15850.50, 'indodax');

        $rate = ExchangeRate::getRate('USD', 'IDR');

        $this->assertEquals(15850.50, $rate);
    }

    /**
     * Test exchange rate is cached after first retrieval.
     */
    public function test_exchange_rate_caching(): void
    {
        // Store initial rate
        $this->repository->storeRate('USD', 'IDR', 15850.50, 'indodax');

        // Retrieve rate (should cache it)
        $rate1 = $this->repository->getRate('USD', 'IDR');
        $this->assertEquals(15850.50, $rate1);

        // Update the database record
        ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'IDR')
            ->update(['rate' => 20000.00]);

        // Retrieve rate again (should still be cached at 15850.50, not 20000)
        $rate2 = $this->repository->getRate('USD', 'IDR');
        $this->assertEquals(15850.50, $rate2);
    }

    /**
     * Test price conversion USD to IDR.
     */
    public function test_convert_price_usd_to_idr(): void
    {
        $this->repository->storeRate('USD', 'IDR', 15850.50, 'indodax');

        $converted = $this->repository->convertPrice(1.0, 'USD', 'IDR');

        $this->assertNotNull($converted);
        $this->assertEquals(15850.50, $converted);
    }

    /**
     * Test price conversion with same currency returns same price.
     */
    public function test_convert_price_same_currency(): void
    {
        $converted = $this->repository->convertPrice(100.0, 'USD', 'USD');

        $this->assertEquals(100.0, $converted);
    }

    /**
     * Test price conversion when rate is unavailable returns null.
     */
    public function test_convert_price_unavailable_rate_returns_null(): void
    {
        $converted = $this->repository->convertPrice(100.0, 'USD', 'IDR');

        $this->assertNull($converted);
    }

    /**
     * Test fetch and store rate uses Indodax primary source.
     */
    public function test_fetch_and_store_uses_indodax_primary(): void
    {
        Http::fake([
            'indodax.com/api/ticker/usdt_idr' => Http::response([
                'ticker' => ['last' => '15850.50'],
            ]),
        ]);

        $rate = $this->repository->fetchAndStoreRate('USD', 'IDR');

        $this->assertNotNull($rate);
        $this->assertEquals(15850.50, $rate);

        // Verify it was stored with indodax source
        $record = ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'IDR')
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals('indodax', $record->source);
    }

    /**
     * Test fetch and store falls back to CoinGecko when Indodax fails.
     */
    public function test_fetch_and_store_fallback_to_coingecko(): void
    {
        Http::fake([
            'indodax.com/api/ticker/usdt_idr' => Http::response([], 500),
            'api.coingecko.com/api/v3/simple/price' => Http::response([
                'usd' => ['idr' => 15900.00],
            ]),
        ]);

        $rate = $this->repository->fetchAndStoreRate('USD', 'IDR');

        $this->assertNotNull($rate);
        $this->assertEquals(15900.00, $rate);

        // Verify it was stored with coingecko source
        $record = ExchangeRate::where('from_currency', 'USD')
            ->where('to_currency', 'IDR')
            ->first();

        $this->assertEquals('coingecko', $record->source);
    }

    /**
     * Test case-insensitive currency handling.
     */
    public function test_case_insensitive_currency_handling(): void
    {
        $this->repository->storeRate('usd', 'idr', 15850.50, 'indodax');

        // Retrieve with different cases
        $rate1 = $this->repository->getRate('USD', 'IDR');
        $rate2 = $this->repository->getRate('usd', 'idr');
        $rate3 = $this->repository->getRate('UsD', 'IdR');

        $this->assertEquals(15850.50, $rate1);
        $this->assertEquals(15850.50, $rate2);
        $this->assertEquals(15850.50, $rate3);
    }

    /**
     * Test ExchangeRate model isRecent() method.
     */
    public function test_exchange_rate_is_recent(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'IDR',
            'rate' => 15850.50,
            'source' => 'indodax',
            'refreshed_at' => now(),
        ]);

        $rate = ExchangeRate::where('from_currency', 'USD')->first();

        $this->assertTrue($rate->isRecent());
    }

    /**
     * Test ExchangeRate model isRecent() returns false for old rates.
     */
    public function test_exchange_rate_is_not_recent(): void
    {
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'IDR',
            'rate' => 15850.50,
            'source' => 'indodax',
            'refreshed_at' => now()->subHours(2),
        ]);

        $rate = ExchangeRate::where('from_currency', 'USD')->first();

        $this->assertFalse($rate->isRecent());
    }
}
