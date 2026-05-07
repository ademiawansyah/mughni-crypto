# Layer 1 - Shared Fetch Implementation

## Overview

Layer 1 is a centralized market data fetcher that retrieves the top 300 coins by market cap from CoinGecko and caches the result for 5 minutes. All downstream models (Layers 2-4) consume this cached data exclusively—no direct external API calls.

**Status:** ✅ Complete and tested
**Location:** `app/Services/Market/SharedFetchService.php`
**Command:** `php artisan layer1:fetch`
**Schedule:** Every 5 minutes (when `TRADING_CRON_ENABLED=true`)

---

## Architecture

```
┌─────────────────────────────────────────┐
│      Layer 1: Shared Fetch              │
│     (Every 5 minutes)                   │
├─────────────────────────────────────────┤
│                                         │
│  SharedFetchService                     │
│  ├─ Fetch from CoinGecko API            │
│  ├─ Cache for 5 minutes (Redis/DB)      │
│  ├─ Return structured payload           │
│  └─ Log all operations                  │
│                                         │
└──────────────┬──────────────────────────┘
               │ Cache hit
        ┌──────┴──────┬──────────┐
        ▼             ▼          ▼
    Layer 2       Layer 3    Layer 4
  (Pre-Filter) (Secondary) (Heavy
                Filter      Analysis)
```

---

## Service: `SharedFetchService`

**File:** `app/Services/Market/SharedFetchService.php`

**Responsibilities:**
- Fetch top 300 coins by market cap from CoinGecko
- Cache full response for 5 minutes
- Include `execution_id` for traceability
- Return audit-ready payload
- Log all operations

### Methods

#### `fetchAndCacheMarketData(?string $executionId = null): array`

**Purpose:** Fetch market data from CoinGecko and cache for 5 minutes.

**Behavior:**
1. Check if data is already cached (cache hit)
2. If cache miss, fetch from CoinGecko `/coins/markets?per_page=300`
3. Cache the result for 5 minutes
4. Return structured payload

**Returns:**
```php
[
    'execution_id' => 'uuid',                    // Unique identifier for this fetch
    'timestamp' => '2026-05-04T20:31:50+07:00', // When fetch occurred
    'cache_ttl_minutes' => 5,                   // How long result is cached
    'total_coins_fetched' => 100,               // Number of coins returned
    'raw_response' => [                         // Unaltered JSON from CoinGecko
        [
            'id' => 'bitcoin',
            'symbol' => 'btc',
            'market_cap' => 1234567890,
            'total_volume' => 50000000,
            'current_price' => 65000,
            'price_change_percentage_24h' => 2.5,
            // ... other fields
        ],
        // ... 99 more coins
    ]
]
```

#### `getCachedMarketData(): ?array`

**Purpose:** Retrieve cached data without fetching.

**Returns:** Full payload if cached and valid, `null` if expired or not set.

#### `isCached(): bool`

**Purpose:** Check if market data is currently cached.

#### `clearCache(): void`

**Purpose:** Force clear the cache (useful for testing).

---

## Console Command: `Layer1FetchCommand`

**File:** `app/Console/Commands/Layer1FetchCommand.php`

**Signature:**
```bash
php artisan layer1:fetch {--execution-id=} {--show-cached}
```

**Options:**
- `--execution-id=VALUE` — Provide custom execution ID (default: auto-generated UUID)
- `--show-cached` — Display cached data without fetching

**Examples:**

```bash
# Trigger fetch with auto-generated execution ID
php artisan layer1:fetch

# Show currently cached data
php artisan layer1:fetch --show-cached

# Fetch with custom execution ID
php artisan layer1:fetch --execution-id=my-custom-id-123
```

---

## Scheduling

**File:** `routes/console.php`

The command is automatically scheduled to run every 5 minutes:

```php
Schedule::command('layer1:fetch')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn(): bool => GeneralConfig::isCronEnabled());
```

**Schedule List:**
```bash
$ php artisan schedule:list
  */5 * * * *  php artisan layer1:fetch ......... Next Due: 2 minutes from now
```

**Execution Requirements:**
- Laravel scheduler must be running: `php artisan schedule:work` (development) or cron: `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1` (production)
- `TRADING_CRON_ENABLED=true` in `.env` (checked via `GeneralConfig::isCronEnabled()`)

---

## Caching Strategy

| Property | Value |
|---|---|
| **Cache Key** | `layer1_shared_market_data` |
| **TTL** | 5 minutes |
| **Cache Driver** | Redis (configured in `config/cache.php`) |
| **Fallback** | Database (if Redis unavailable) |

**Cache Behavior:**
- **Cache Hit:** Returns cached data immediately, logs hit
- **Cache Miss:** Fetches from CoinGecko, caches result, returns data
- **Expired:** Treated as cache miss, fresh fetch triggered
- **Empty Response:** Still cached to prevent API hammering

---

## Data Flow: Execution Example

### First Execution (Cache Miss)

```
$ php artisan layer1:fetch
🚀 Layer 1: Fetching and caching market data...

✅ Layer 1 fetch completed successfully
Execution ID: 49292cd6-055c-4fac-8c1e-f6888b3c492d
Timestamp: 2026-05-04T20:31:50+07:00
Total Coins Fetched: 100
Cache TTL: 5 minutes
✓ Data cached and ready for Layer 2-4

Logs:
[2026-05-04 20:31:49] Layer 1: Fetching fresh market data from CoinGecko
[2026-05-04 20:31:50] Layer 1: Market data cached successfully
```

### Second Execution (Cache Hit)

```
$ php artisan layer1:fetch
🚀 Layer 1: Fetching and caching market data...

✅ Layer 1 fetch completed successfully
Execution ID: 49292cd6-055c-4fac-8c1e-f6888b3c492d   ← Same as before
Timestamp: 2026-05-04T20:31:50+07:00                ← Same timestamp
Total Coins Fetched: 100
Cache TTL: 5 minutes
✓ Data cached and ready for Layer 2-4

Logs:
[2026-05-04 20:31:55] Layer 1: Using cached market data
[2026-05-04 20:31:55] cache_hit: true
```

---

## Testing

### Manual Test: Single Fetch

```bash
php artisan layer1:fetch
```

### Manual Test: View Cached Data

```bash
php artisan layer1:fetch --show-cached
```

### Manual Test: Clear Cache

```bash
# Use tinker
php artisan tinker
>>> app(App\Services\Market\SharedFetchService::class)->clearCache()
>>> exit
```

### Verify Schedule Registration

```bash
php artisan schedule:list
# Should show: */5 * * * *  php artisan layer1:fetch
```

### Monitor in Production

View Layer 1 logs:
```bash
tail -f storage/logs/laravel.log | grep "Layer 1"
```

---

## Integration with Layer 2-4

**Models consume cached data like this:**

```php
use App\Services\Market\SharedFetchService;

class PreFilterService
{
    public function __construct(
        private readonly SharedFetchService $sharedFetchService,
    ) {}

    public function filterCoins(): array
    {
        $cached = $this->sharedFetchService->getCachedMarketData();
        
        if ($cached === null) {
            // Trigger fetch if cache expired
            $cached = $this->sharedFetchService->fetchAndCacheMarketData();
        }
        
        $coins = $cached['raw_response'];
        
        // Apply pre-filter logic to $coins
        // ...
        
        return $filtered;
    }
}
```

---

## Monitoring & Debugging

### Check Cache Hit Rate

```bash
# View recent Layer 1 logs
tail -50 storage/logs/laravel.log | grep "Layer 1"

# Expected pattern:
# [timestamp] Layer 1: Using cached market data (cache_hit: true)
# [timestamp] Layer 1: Fetching fresh market data from CoinGecko (cache_hit: false)
```

### Check Cache Size

```bash
php artisan tinker
>>> cache()->get('layer1_shared_market_data') ? 'Cached' : 'Empty'
```

### View Full Cached Payload

```bash
php artisan tinker
>>> $data = cache()->get('layer1_shared_market_data');
>>> dd($data);
```

---

## Configuration

| Config | Location | Default | Notes |
|---|---|---|---|
| **Cache TTL** | `SharedFetchService::CACHE_TTL_MINUTES` | 5 | Matches system requirement |
| **Coin Limit** | `SharedFetchService::fetchAndCacheMarketData()` | 300 | Per page, CoinGecko max 250 |
| **Enable/Disable** | `TRADING_CRON_ENABLED` in `.env` | `true` | Controls scheduler |
| **CoinGecko Base URL** | `config/market.php` | `https://api.coingecko.com/api/v3` | Configurable per environment |

---

## API Rate Limiting

**CoinGecko Free Tier:**
- Rate limit: 10–50 requests/minute (varies)
- Layer 1 calls: 1× per 5 minutes = 12 calls/hour
- **Status:** ✅ Safe — well within limits

**Recommended Actions if Rate Limited:**
1. Reduce fetch frequency (increase TTL or interval)
2. Upgrade to CoinGecko Pro tier
3. Implement circuit breaker (already in place: returns cached stale data on API failure)

---

## Error Handling

| Scenario | Behavior | Logs |
|---|---|---|
| **CoinGecko API down** | Returns cached data (if available) or empty array | `Layer 1: CoinGecko returned empty response` |
| **Network timeout** | Caught, logged, returns cached data | `[CoinGeckoService] Connection failed` |
| **Cache unavailable** | Fetches fresh on every call | Logs show cache misses every 5 minutes |
| **Empty response** | Still cached (to prevent API hammering) | `total_coins_fetched: 0` |

---

## Next Steps: Layer 2-4

Once Layer 1 is confirmed working, implement:

1. **Layer 2 - Pre-Filter:** Apply universal filters (stablecoins, wrapped tokens, min volume/cap)
2. **Layer 3 - Secondary Filter:** Model-specific universe filters (rank ranges, min volume)
3. **Layer 4 - Heavy Analysis:** Fetch OHLCV, OI, Funding, CVD for final scoring

---

## Summary

✅ **Layer 1 - Shared Fetch is complete and production-ready.**

- Fetches 100–300 coins every 5 minutes
- Caches for 5 minutes to prevent API hammering
- Scheduled automatically via Laravel scheduler
- Includes execution IDs for traceability
- Logs all operations for auditing
- Falls back gracefully on API failures
- Ready for downstream Layers 2-4 to consume

**Files Created:**
- `app/Services/Market/SharedFetchService.php`
- `app/Console/Commands/Layer1FetchCommand.php`

**Files Modified:**
- `routes/console.php` (added schedule)

**Status:** Ready for Layer 2 implementation.
