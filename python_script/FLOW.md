# System Flow — Python Pipeline

This document traces the full execution flow from `main.py` to the final JSON output files,
so you can follow, study, and modify any layer independently.

---

## Entry Point

```
python_script/main.py
```

Adds `src/` to `sys.path` so the `crypto_pipeline` package is importable, then calls
`crypto_pipeline.cli.main.main()`.

---

## CLI (`src/crypto_pipeline/cli/main.py`)

Parses the command argument and delegates to orchestrator functions:

| Command | What it does |
|---|---|
| `fetch` | Layer1 + Layer2 only, prints coin count |
| `model1` | fetch + run counter_trend model |
| `model2` | fetch + run pre_pump model |
| `model3` | fetch + run trend_momentum model |
| `model4` | run spot_momentum_gainers (no prefilter coins needed) |
| `all` | fetch + all 4 models, lists saved file paths |
| `compare` | diff Python output JSON vs Laravel output JSON |
| `validate-calls` | run all + assert every required endpoint was called |

`--force-refresh` bypasses the cache and forces live API calls for every provider.

---

## Build Runtime (`pipeline/orchestrator.py → build_runtime`)

Called before any model or fetch command. Wires together all dependencies:

```
load_config()            ← reads .env / environment variables
new_execution()          ← generates execution_id (UUID) + timestamp
EndpointCallLedger()     ← collects every API call made this run
CacheAdapter             ← file cache (or Redis) at artifacts/cache/
HttpClient               ← retries, backoff, circuit breaker, ledger callback
CoinGeckoProvider        ← wraps CoinGecko REST API
BinanceProvider          ← wraps Binance spot + futures REST APIs
CoinMarketCapProvider    ← wraps CoinMarketCap REST API
```

All artifacts for this run are stored under:
```
artifacts/<YYYY-MM-DD>/<execution_id>/
```

---

## Layer 1 — Raw Market Fetch (`pipeline/shared_fetch.py`)

**Triggered by:** `run_layer1_layer2()`

1. Calls `CoinGeckoProvider.get_markets_top_300()`
   - Endpoint: `GET /coins/markets?vs_currency=usd&per_page=300&order=market_cap_desc`
   - Cache TTL: 300s
2. Stores raw response (unaltered) to:
   ```
   layer1/raw_coingecko_markets.json
   ```

---

## Layer 2 — Pre-Filter (`pipeline/shared_fetch.py → apply_prefilter`)

Runs deterministic rules on every coin. Does **not** call any API.

| Rule | Reject reason |
|---|---|
| symbol in stablecoin list | `stablecoin` |
| name contains wrapped keyword | `wrapped_token` |
| `total_volume` < 1,000,000 | `low_volume` |
| `market_cap` < 50,000,000 | `low_market_cap` |
| volume = 0 or price = 0 | `missing_price_or_volume` |

**Stablecoins list:** usdt, usdc, dai, busd, tusd, frax, usdd, usdp, gusd, lusd

**Wrapped keywords:** wrapped, wbtc, weth, steth, reth, cbeth

Outputs two artifacts:
```
layer2/prefilter_audit.json    ← every coin with passed=true/false + reason
layer2/prefilter_passed.json   ← only passing coins (forwarded to models)
```

Typical output: ~87 coins from 300 raw.

---

## Layer 3 — Per-Model Rank Filter (inside each model)

Each model applies its own rank+volume window on the L2 passed list before any API call.
This is `BaseModelService.l3_filter()` in `models/base.py`.

| Model | Rank range | Min 24h volume |
|---|---|---|
| Model 1 — counter_trend | 50–300 | $5M |
| Model 2 — pre_pump | 20–150 | $10M |
| Model 3 — trend_momentum | 1–50 | $50M |
| Model 4 — spot_momentum_gainers | 1–200 | none (uses CMC top gainers instead) |

---

## Model 1 — Counter Trend (`models/model_counter_trend.py`)

**Goal:** Find coins where smart money has swept liquidity and structure has shifted.

**Timeframes:** 1h (structure) + 15m (entry)

**Binance calls per coin:**
- `GET /api/v3/klines` — 1h, 120 candles
- `GET /api/v3/klines` — 15m, 120 candles
- `GET /futures/data/openInterestHist` — 1h period, 20 candles
- `GET /fapi/v1/fundingRate` — last 3
- `GET /api/v3/trades` — last 1000

**Hard gates (both must pass, else coin skipped):**
1. `liquidity_sweep` — last candle swept the 30-bar high or low AND closed back inside
2. `mss` (market structure shift) — close broke above/below prior 10-bar range

**Scoring (if gates pass):**
```
score = (liquidity_sweep × 0.40
       + mss            × 0.30
       + fvg_ob_bonus   × 0.15    ← fair value gap on 15m
       + oi_decline      × 0.08    ← OI dropped ≥ 5%
       + funding_extreme × 0.07)  ← funding ≤ -0.001 or ≥ 0.001
       × 100
```

---

## Model 2 — Pre Pump (`models/model_pre_pump.py`)

**Goal:** Find coins building up silently (low funding, rising OI, ATR compression).

**Timeframe:** 4h

**Binance calls per coin:**
- `GET /api/v3/klines` — 4h, 220 candles
- `GET /fapi/v1/fundingRate` — last 12
- `GET /futures/data/openInterestHist` — 4h period, 30 candles
- `GET /api/v3/trades` — last 1000

**No hard gate** — all 5 signals are scored.

**Scoring:**
```
score = (funding_persistent × 0.35   ← all 3 last funding < -0.0005
       + oi_sideways        × 0.25   ← OI ↑ >10% while price range < 3%
       + atr_compression    × 0.20   ← current ATR < 30-period ATR baseline
       + cvd_divergence     × 0.12   ← price flat, buy CVD rising
       + rsi_compression    × 0.08)  ← RSI stuck between 45–55 for 5 samples
       × 100
```

---

## Model 3 — Trend Momentum (`models/model_trend_momentum.py`)

**Goal:** Find top-cap coins in confirmed uptrend with momentum.

**Timeframes:** 1d (trend) + 4h (entry)

**Binance calls per coin:**
- `GET /api/v3/klines` — 1d, 260 candles
- `GET /api/v3/klines` — 4h, 180 candles
- `GET /futures/data/openInterestHist` — 4h period, 30 candles
- `GET /api/v3/trades` — last 1000

**Hard gate (must pass, else coin skipped):**
- `ema_gate` — `close > EMA50 > EMA200` AND `slope of EMA50 > 0` on daily chart
  > In a bear market (EMA50 < EMA200), this rejects nearly everything — that is correct behavior.

**Scoring (if gate passes):**
```
score = (ema_gate   × 0.30
       + macd_zone  × 0.25   ← MACD > signal > 0 on 4h
       + rsi_zone   × 0.20   ← RSI between 50–65 on 4h
       + bos        × 0.15   ← close > 20-bar high (break of structure)
       + oi_cvd     × 0.10)  ← OI ↑ + price ↑ + net buy CVD
       × 100
```

BTC and ETH are always injected into candidates even if outside L3 rank range.

---

## Model 4 — Spot Momentum Gainers (`models/model_spot_gainers.py`)

**Goal:** Find top 24h gainers with a confirmed daily bullish candle.

**Data source:** CoinMarketCap top 200 (fallback: CoinGecko `price_change_percentage_24h` desc)

**CMC/CoinGecko call:**
- `GET /v1/cryptocurrency/listings/latest` (CMC) — top 200 by market cap
- Filters: no stablecoins, no wrapped tokens, market cap ≥ $100M
- Sorts by `percent_change_24h` descending, takes top 10

**Binance call per candidate:**
- `GET /api/v3/klines` — 1d, 7 candles (spot only)

**Hard gate (bullish candle on daily):**
All of the following must be true:
1. `close > open` (green candle)
2. `body_ratio ≥ 0.60` — body takes ≥ 60% of full range
3. `upper_wick_ratio ≤ 0.20` — minimal rejection at top
4. `close > previous candle high` (breakout)
5. `volume today > 5-day average volume`

> Note: Running intraday (before market close) means the current daily candle is incomplete.
> `vol_today < vol_avg5` will often be true and reject valid candidates.
> Re-run near or after daily close for best results.

**Scoring:**
```
score = (change_24h   × 0.40   ← normalized: change_24h / 25.0, capped at 1.0
       + volume_ratio × 0.35   ← vol_today / vol_avg5, capped at 3.0
       + body_ratio   × 0.25)  ← candle body quality
       × 100
```

---

## Indicators (`pipeline/indicators.py`)

Pure functions, no external calls. Used by all models.

| Function | Description |
|---|---|
| `ema(values, period)` | Exponential moving average, returns full series |
| `rsi(values, period=14)` | Standard RSI, returns single float |
| `macd(values)` | MACD(12,26,9), returns (macd, signal, histogram) |
| `atr(highs, lows, closes, period=14)` | Average True Range |
| `linear_slope(values)` | Least-squares slope of a value series |
| `normalize_score(raw)` | Clamps to [0.0, 100.0] |

---

## HTTP Client (`core/http_client.py`)

Handles all outbound requests.

- **Retries:** up to 3 attempts (configurable via `HTTP_RETRY_COUNT`)
- **Backoff:** 1s → 2s → 4s (configurable via `HTTP_BACKOFF_SECONDS`)
- **Circuit breaker:** opens after 5 consecutive 5xx/connection errors per provider, resets after 300s
  - 4xx errors (e.g. invalid trading pair) do **not** trip the circuit breaker
- **Ledger callback:** every request (success or failure) is recorded in `EndpointCallLedger`

---

## Cache (`core/cache.py`)

- **Default backend:** file-based (`artifacts/cache/<key>.json`)
- **Optional:** Redis if `CACHE_BACKEND=redis` and the `redis` package is installed
- Each cache entry stores `value` + `expires_at` timestamp
- `force_refresh=True` skips reading cache but still writes it after a live fetch
- On provider failure, falls back to stale cache (ignores expiry)

**TTLs (seconds):**
| Data | TTL |
|---|---|
| CoinGecko markets | 300 |
| OHLCV candles | 60 |
| OI / funding | 120 |
| Trades / CVD | 300 |

---

## Output (`output/writer.py` + `core/execution.py`)

Every run generates artifacts under a unique directory:

```
artifacts/
└── YYYY-MM-DD/
    └── <execution_id>/
        ├── layer1/
        │   └── raw_coingecko_markets.json
        ├── layer2/
        │   ├── prefilter_audit.json
        │   └── prefilter_passed.json
        ├── outputs/
        │   ├── counter_trend.json
        │   ├── pre_pump.json
        │   ├── trend_momentum.json
        │   └── spot_momentum_gainers.json
        └── logs/
            └── endpoint_call_ledger.json
```

**Model output schema:**
```json
{
  "model": "pre_pump",
  "version": "2.0",
  "timestamp": "2026-05-07T03:16:07Z",
  "execution_date": "2026-05-07",
  "results": [
    {
      "rank": 1,
      "symbol": "WLFIUSDT",
      "price": 0.067028,
      "total_score": 32.0,
      "components": {
        "funding_persistent": true,
        "oi_sideways": false,
        "atr_compression": true,
        "cvd_divergence": false,
        "rsi_compression": false
      },
      "metadata": {
        "entry_timeframe": "1h",
        "structure_timeframe": "4h"
      }
    }
  ]
}
```

**Endpoint ledger schema:**
```json
{
  "entries": [
    {
      "provider": "binance",
      "endpoint": "https://api.binance.com/api/v3/klines",
      "status_code": 200,
      "latency_ms": 312,
      "cache_status": "refresh",
      "timestamp": "2026-05-07T03:16:08Z"
    }
  ]
}
```

---

## Configuration (`.env`)

| Variable | Default | Description |
|---|---|---|
| `COINGECKO_API_KEY` | — | CoinGecko Pro/Demo API key |
| `COINMARKETCAP_API_KEY` | — | CMC Pro API key |
| `BINANCE_BASE_URL` | `https://api.binance.com` | Spot API base |
| `BINANCE_FUTURES_BASE_URL` | `https://fapi.binance.com` | Futures API base |
| `HTTP_TIMEOUT_SECONDS` | `10` | Per-request timeout |
| `HTTP_RETRY_COUNT` | `3` | Max retries per request |
| `HTTP_BACKOFF_SECONDS` | `1,2,4` | Backoff delays per attempt |
| `CACHE_BACKEND` | `file` | `file` or `redis` |
| `REDIS_URL` | `redis://127.0.0.1:6379/0` | Redis connection URL |
| `FORCE_REFRESH` | `false` | Skip cache reads on startup |
| `OUTPUT_DIR` | `./artifacts` | Root artifact directory |

---

## Common Issues

| Symptom | Cause | Fix |
|---|---|---|
| All model results empty | Circuit breaker tripped on invalid pairs (400s) | Fixed — 4xx no longer counts toward breaker |
| Model 3 returns 0 results | Bear market — EMA50 < EMA200 for most top coins | Expected; gates are strict by design |
| Model 4 returns 0 results | Running intraday — current daily candle has incomplete volume | Re-run near daily close (00:00 UTC) |
| `ModuleNotFoundError: redis` | `redis` package not installed | Safe to ignore if using file cache |
| Coin missing from results | No USDT pair on Binance (400 error, skipped) | Expected; pair simply does not exist |
