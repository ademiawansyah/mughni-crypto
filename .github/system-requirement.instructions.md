# CRYPTO TRADING SYSTEM — System Requirements & Developer Blueprint

**Version:** 2.0  
**Year:** 2026  
**Audience:** Developers and AI Coding Agents  
**Status:** SOURCE OF TRUTH

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Purpose & Non-Negotiable Rules](#2-purpose--non-negotiable-rules)
3. [High-Level Architecture](#3-high-level-architecture)
4. [Model 1: Counter Trend](#4-model-1-counter-trend)
5. [Model 2: Pre-Pump Detector](#5-model-2-pre-pump-detector)
6. [Model 3: Trend Momentum](#6-model-3-trend-momentum)
7. [Model 4: Spot Momentum Gainers](#7-model-4-spot-momentum-gainers)
8. [Data Sources & API Endpoints](#8-data-sources--api-endpoints)
9. [Filter Pipeline Architecture](#9-filter-pipeline-architecture)
10. [Caching & Rate Limiting Policy](#10-caching--rate-limiting-policy)
11. [Implementation Guidance](#11-implementation-guidance)
12. [Agent Change Protocol](#12-agent-change-protocol)
13. [Developer Checklist](#13-developer-checklist)

---

## 1. System Overview

This document defines a **crypto signal generation system** with **4 independent trading models** that MUST NOT be merged. Each model operates as a separate service with its own logic, data sources, timeframes, and entry/exit conditions.

| Property | Specification |
|---|---|
| **Models** | 4 independent, isolated services |
| **Data Sources** | Public APIs only (CoinGecko, Binance, Coinalyze, CoinMarketCap) |
| **Implementation** | Flexible (Python 3.9+ or Node.js 18+ recommended) |
| **Deployment** | Linux VPS (Ubuntu 20.04+ or Debian 11+) |
| **Output** | Top 10 coins per model with total score + component breakdown |
| **Execution** | Each model as separate process/service |

### Critical Constraints

- **Model Independence:** All four models MUST run as independent services. Never merge outputs into a single ranked list.
- **Public Data Only:** All data must be from public APIs with no paid authentication required.
- **Derivatives as Confirmation:** Open Interest, Funding Rates, and CVD are confirmation signals only — never primary triggers.
- **Model 4 SPOT ONLY:** No short positions, no leverage, long entry only.

---

## 2. Purpose & Non-Negotiable Rules

### 2.1 Purpose

This system generates ranked trade signals. It does NOT execute trades, manage positions, or access exchange accounts.

**Trade execution must remain manual or be handled by a separate risk-managed execution layer.**

### 2.2 Non-Negotiable Rules

1. Keep all four models fully independent.
2. Never merge model outputs into one shared signal list.
3. Use public market data only.
4. Run each model as a separate service or process.
5. Treat derivatives data as confirmation, not the primary trigger.
6. Output Top 10 coins per model with component-level scoring details.
7. Model 4 is long-only spot — no short exposure, no leverage.
8. All API calls are centralized in a shared data-fetcher service.
9. Model services consume cached data only — no direct external API calls.



---

## 3. High-Level Architecture

### 3.1 Service Stack

| Service | Role | Schedule | Output |
|---|---|---|---|
| **service-data-fetcher** | Centralized market data fetcher | Every 5 minutes | Cached OHLCV + derivatives for all models |
| **service-counter-trend** | Model 1 scanner | Every 15 minutes | Top 10 Counter Trend signals (JSON) |
| **service-pre-pump** | Model 2 scanner | Every 4 hours | Top 10 Pre-Pump signals (JSON) |
| **service-trend-momentum** | Model 3 scanner | Every 4 hours | Top 10 Trend Momentum signals (JSON) |
| **service-spot-gainers** | Model 4 scanner | Daily at 07:00 WIB (UTC+7) | Top 10 Spot Momentum signals (JSON) |
| **notifier** | Alert dispatcher | On-demand (called by each model) | Telegram / Discord notifications |

### 3.2 Execution Pattern (MANDATORY)

```
┌─────────────────────┐
│  shared-data-fetch  │ (runs every 5 min)
│  - fetch 300 coins  │
│  - cache 5 min      │
│  - apply pre-filter │
└──────────┬──────────┘
           │ (cache → Redis/DB)
     ┌─────┼─────┬─────────┐
     ▼     ▼     ▼         ▼
  Model1 Model2 Model3   Model4
  (15m)  (4h)   (4h)    (daily 7:00)
  │      │      │         │
  └──────┴──────┴─────────┘
     (read cache only)
```

### 3.3 Standard Output Contract (JSON)

All models return JSON with this identical structure:

```json
{
  "model": "model_name",
  "version": "2.0",
  "timestamp": "2026-05-04T07:00:00+07:00",
  "execution_date": "2026-05-04",
  "results": [
    {
      "rank": 1,
      "symbol": "BNBUSDT",
      "price": 625.43,
      "total_score": 87.5,
      "components": {
        "component_1": 0.85,
        "component_2": true,
        "component_3": 0.90
      },
      "metadata": {
        "entry_point": "entry_value",
        "stop_loss": "sl_value",
        "entry_timeframe": "1H"
      }
    }
  ]
}
```


---

## 4. MODEL 1: COUNTER TREND

**Strategy:** Mia Style — Liquidity Sweep + Market Structure Shift + Exhaustion Reversal Detection

### 4.1 Description & Philosophy

This model detects price reversals when price manipulation meets market exhaustion. The goal is not to chase aggression but to find the exact moment when one side's pressure reaches maximum and begins to reverse.

**Core Philosophy:** Smart money sweeps liquidity (retail stop-losses) before reversing direction. This system detects the moment after that sweep completes.

### 4.2 Coin Universe

| Parameter | Specification |
|---|---|
| **Target Coins** | Volatile altcoins (market cap rank 50–300) |
| **Minimum Volume** | 24H volume > $5,000,000 |
| **Exclusions** | Stablecoins only |
| **Data Source** | CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=300` |

### 4.3 Timeframes

| Component | Timeframe |
|---|---|
| Structure Screening | 1H or 4H |
| Entry Confirmation | 15M |
| Macro Context Filter | 1D (optional) |

### 4.4 Signal Components & Logic

#### A. Price Action — Mia Style (Primary Trigger)

These three conditions MUST be fulfilled in sequence:

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **Liquidity Sweep** | Price breaks Old High/Low or Equal H/L, then reverses | Wick breaks level, candle body closes back inside range | OHLCV via CoinGecko `/coins/{id}/ohlc` or Binance `/api/v3/klines` |
| **MSS (Market Structure Shift)** | Sudden trend character change with body break | Candle CLOSE crosses swing point in opposite direction | Calculate swing highs/lows from OHLCV (pandas-ta / ta-lib) |
| **FVG / OB Entry** | Price retraces into imbalance zone after MSS | Fair Value Gap: gap across 3 candles. Order Block: last candle before impulse move | Local calculation from OHLCV |

#### B. Derivatives — Confirmation (Applied After Primary Trigger)

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **Open Interest** | OI declines when price sweeps (exhaustion signal) | OI drops >5% concurrent with price spike | Coinalyze `/futures/open-interest` or Bybit `/v5/market/open-interest` |
| **Funding Rate** | Extreme funding = potential reversal | Funding < -0.1% or > +0.1% (calibratable) | Coinalyze `/futures/funding-rate` |
| **CVD (Cumulative Volume Delta)** | CVD divergence with price = exhaustion confirmation | Price makes new high but CVD declines (bearish divergence) | Calculated from Binance `/api/v3/trades` (buy vs sell side) |

### 4.5 Scoring Weights

| Signal | Weight | Gate Status |
|---|---|---|
| Liquidity sweep confirmed | 40% | **Required** — skip if absent |
| MSS formed | 30% | **Required** |
| FVG/OB as entry zone | 15% | Optional, raises score |
| OI declining during sweep | 8% | Confirmation |
| Extreme funding rate | 7% | Confirmation |

> **Score Normalization:** All scores 0–100. Include component scores in output for transparency.

---

## 5. MODEL 2: PRE-PUMP DETECTOR

**Strategy:** Pressure Cooker + Momentum Runner — Short Squeeze Expansion Setup

### 5.1 Description & Philosophy

This model detects coins in a compression phase before major breakout. Persistent short pressure (negative funding), drying volume, and low volatility create a "pressure cooker" — when it explodes, the move is significant.

### 5.2 Coin Universe

| Parameter | Specification |
|---|---|
| **Target Coins** | Mid-cap (market cap rank 20–150) |
| **Minimum Volume** | 24H volume > $10,000,000 |
| **Requirement** | Futures market available (OI accessible) |
| **Data Source** | CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=150` |

### 5.3 Timeframes

| Component | Timeframe |
|---|---|
| Funding Rate Screening | Real-time / 8H intervals |
| OI & Volume Screening | 4H |
| Entry Confirmation | 1H |

### 5.4 Signal Components & Logic

#### A. Funding Rate Squeeze

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **Persistent Negative Funding** | Short sellers dominate — potential squeeze | Funding < -0.05% per 8H for 3 consecutive periods | Coinalyze `/futures/funding-rate` |
| **OI Rising + Price Sideways** | Positions building while price flat = time bomb | OI rises >10% in 24H, price in range <3% | Coinalyze `/futures/open-interest` |
| **Low ATR** | Volatility compressing = before explosion | ATR 14-period below 30-day average | Calculated from OHLCV (ta-lib) |

#### B. Momentum Runner

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **Drying Volume** | Volume drops drastically = silent accumulation | 24H volume drops >50% from 7-day average | CoinGecko `/coins/{id}/market_chart` |
| **CVD Divergence** | CVD rising quietly while volume drops = accumulation | CVD trending up in 24H while price flat | Binance public trade data |
| **RSI Compression** | RSI trapped in neutral zone = ready for breakout | RSI 14 between 45–55 for >5 candles on 4H | Calculated from OHLCV (pandas-ta) |

### 5.5 Scoring Weights

| Signal | Weight | Gate Status |
|---|---|---|
| Persistent negative funding rate | 35% | Primary trigger |
| OI rising + price sideways | 25% | Accumulation confirmation |
| Low ATR (volatility compression) | 20% | Lower = better |
| CVD quietly rising | 12% | Hidden accumulation |
| RSI compression (45–55) | 8% | Technical confirmation |

---

## 6. MODEL 3: TREND MOMENTUM

**Strategy:** MACD-RSI-EMA Confirmation System — Follow Confirmed Breakout Continuation

### 6.1 Description & Philosophy

This model detects coins already in a strong trend with room to continue. It does not catch bottoms — it follows proven momentum. Entry is only after trend structure confirmation, not speculation.

### 6.2 Coin Universe

| Parameter | Specification |
|---|---|
| **Target Coins** | Large-cap (market cap rank 1–50); BTC and ETH always included |
| **Minimum Volume** | 24H volume > $50,000,000 |
| **Requirement** | Listed for at least 6 months |
| **Data Source** | CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=50` |

### 6.3 Timeframes

| Component | Timeframe |
|---|---|
| Trend Filter (EMA) | 1D |
| Entry Signal (MACD + RSI) | 4H |
| BOS Confirmation | 1D or 4H |

### 6.4 Signal Components & Logic

#### A. EMA Filter (Trend Direction Gate)

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **EMA 50 & 200** | Price above both EMAs = bullish trend | Close > EMA50 > EMA200, EMA spread widening | Calculated from daily OHLCV (ta-lib / pandas-ta) |
| **EMA Slope** | EMA rising = healthy trend | EMA50 slope positive for ≥3 consecutive days | Calculate delta EMA per period |

#### B. RSI & MACD Synergy

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **RSI Momentum Zone** | RSI 50–65 = strong momentum without overbought | RSI 14 in range 50–65 on 4H | pandas-ta / ta-lib from OHLCV |
| **MACD Confirmation** | MACD line above signal, both above 0 | MACD > Signal > 0, histogram positive and expanding | Calculate MACD (12,26,9) from close price |
| **BOS (Break of Structure)** | Coin continues making higher highs = valid structure | 4H close breaks prior swing high | Detect swing highs from local OHLCV |

#### C. Derivatives — Trend Health Confirmation

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| **OI Rising + Price Rising** | New money entering = healthy trend | OI and price both rise >5% in 24H | Coinalyze `/futures/open-interest` |
| **Positive CVD** | Buyer aggression dominates | CVD trending positive over last 24H | Binance trade data |

### 6.5 Scoring Weights

| Signal | Weight | Gate Status |
|---|---|---|
| EMA filter satisfied (price > EMA50 > EMA200) | 30% | **Gate** — skip if not satisfied |
| MACD in positive zone | 25% | Momentum confirmation |
| RSI in zone 50–65 | 20% | Quality filter |
| BOS confirmed | 15% | Valid trend structure |
| OI + CVD positive | 10% | Confirmation |

---

## 7. MODEL 4: SPOT MOMENTUM GAINERS

**Strategy:** CMC Top Gainers + Bullish Candle + Volume Screening

> **SPOT ONLY:** No short positions. No leverage.

### 7.1 Description & Philosophy

Pure spot trading strategy exploiting short-term momentum. Coins entering top 24H gainers with strong bullish candle + high volume are likely in momentum that continues for several days.

**Core Philosophy:** Follow momentum proven today. Enter with measured risk, exit with discipline when momentum reverses or target reached.

### 7.2 Coin Universe

| Parameter | Specification |
|---|---|
| **Target Coins** | Top 200 by market cap, sorted by 24H% gain descending |
| **Selection** | Top 10 highest 24H percentage gain |
| **Market Cap Minimum** | > $100,000,000 |
| **Exclusions** | Stablecoins (USDT, USDC, DAI, etc.), wrapped tokens (WBTC, WETH, etc.) |
| **Data Source** | CoinMarketCap `/v1/cryptocurrency/listings/latest?limit=200&sort=market_cap` |

### 7.3 Timeframes

| Component | Timeframe |
|---|---|
| Gainers Screening | 24H (every morning ~07:00 WIB / UTC+7) |
| Candle + Volume Validation | 1D (Daily chart) |
| Hold Period | Several days (short swing) |

### 7.4 Step-by-Step Workflow

#### Step 01 — Fetch Top Gainers

| Component | Description |
|---|---|
| **Operation** | Fetch top 200 market cap coins, sort by 24H% descending |
| **Selection** | Take top 10 coins |
| **Filter** | Market cap > $100M, exclude stablecoins, exclude wrapped tokens |
| **Data Source** | CoinMarketCap `/v1/cryptocurrency/listings/latest` or CoinGecko `/coins/markets?order=percent_change_24h_desc` |

#### Step 02 — Validate Bullish Candle (1D)

Each of the top 10 coins MUST be validated against the daily chart. **ALL 5 criteria must be met simultaneously:**

| Criterion | Definition | System Detection |
|---|---|---|
| **Green Candle** | Close higher than open | `close > open` on last daily candle |
| **Large Body** | Candle body ≥60% of total range | `(close - open) / (high - low) >= 0.6` |
| **Minimal Upper Wick** | Upper wick ≤20% of body | `(high - close) / (close - open) <= 0.2` |
| **Close > Prior High** | Today's close > yesterday's high | `close[today] > high[yesterday]` (breakout) |
| **High Volume** | Today's volume > 5-bar average | `volume[today] > mean(volume[today-5 : today-1])` |

**Outcome:**
- **If all 5 met:** Coin enters entry watchlist.
- **If any single criterion fails:** Skip, move to next coin.
- **If 0 of 10 pass:** No entry today. Re-screen tomorrow.

#### Step 03 — Entry & Stop Loss

| Parameter | Specification |
|---|---|
| **Entry Time** | Morning after screening (~07:15–07:30 WIB) |
| **Order Type** | Market order or limit near candle close |
| **Stop Loss** | Below low of trigger bullish candle |
| **Position Sizing** | `units = (capital × risk%) / (entry_price - stop_loss_price)` |
| **Risk Per Trade** | User-defined (typically 1–2% of capital) |

#### Step 04 — Exit Management

| Exit Condition | Action | Notes |
|---|---|---|
| Profit reaches +2R | Exit partial or full | 2R = profit 2× the risk taken |
| Bearish candle forms | Exit immediately | Opposite of bullish: large red body, close < prior low |
| Trailing stop loss | Move SL below new higher low | Each new higher low, move SL |
| No change | Hold | Re-evaluate next morning |

### 7.5 Bearish Candle Exit Trigger (ALL conditions required)

- Red candle: `close < open`
- Large body: `(open - close) / (high - low) >= 0.6`
- Close lower than prior candle's low
- Volume above 5-bar average (optional, strengthens signal)

### 7.6 Core Pseudocode

```pseudocode
EVERY DAY AT 07:00 WIB:

1. top_gainers = fetch top 10 from /listings/latest sorted by 24h_change DESC
2. Filter: market_cap > 100M, exclude stablecoin, exclude wrapped_token
3. For each coin in top_gainers:
   a. Fetch last 7 daily candles (OHLCV)
   b. candle_today = candle[-1], candle_prev = candle[-2]
   c. Check bullish criteria:
      - close > open (green candle)
      - body_ratio = (close-open)/(high-low) >= 0.6
      - upper_wick_ratio = (high-close)/(close-open) <= 0.2
      - close > candle_prev.high (breakout)
      - volume > mean(volume[-6:-1]) (volume spike)
   d. If all 5 satisfied: add to watchlist_entry
4. Output watchlist_entry to notification / dashboard
5. Record stop_loss = low[candle_today] for each entry
```

### 7.7 Scoring & Output

| Component | Weight | Notes |
|---|---|---|
| All 5 candle criteria satisfied | **Gate (required)** | Not satisfied = coin excluded |
| 24H percentage change magnitude | 40% | Higher = more priority |
| Volume spike ratio | 35% | `volume_today / avg_volume_5_days` — higher = better |
| Candle body ratio | 25% | Larger body = stronger momentum |

### 7.8 Model Comparison Matrix

| Aspect | Model 1 | Model 2 | Model 3 | Model 4 |
|---|---|---|---|---|
| **Trade Type** | Reversal | Pre-breakout | Trend following | Momentum spot |
| **Position Direction** | Long/Short | Long/Short | Long/Short | Long only (SPOT) |
| **Coin Universe** | Rank 50–300 | Rank 20–150 | Rank 1–50 | Rank 1–200 |
| **Primary Timeframe** | 15M–4H | 4H–1D | 4H–1D | 1D |
| **Leverage** | Optional | Optional | Optional | **None** |
| **Signal Frequency** | Several/day | Several/week | Several/week | Once/day |
| **Hold Period** | Hours–days | Days–weeks | Weeks | Days |
| **Run Schedule** | Every 15m | Every 4h | Every 4h | Daily 07:00 WIB |



---

## 8. Data Sources & API Endpoints

Use public APIs only.

| Model(s) | Provider | Endpoint | Data | Purpose |
|---|---|---|---|---|
| 1–3 | CoinGecko | `/coins/markets` | List + market cap + volume | Coin universe |
| 1–4 | Binance | `/api/v3/klines` | OHLCV all timeframes | Price action |
| 1–3 | Coinalyze | `/futures/open-interest` | Open Interest history | Exhaustion confirmation |
| 1–3 | Coinalyze | `/futures/funding-rate` | Funding rate history | Reversal confirmation |
| 1–3 | Binance | `/api/v3/trades` | Raw trades | CVD calculation |
| 2 | CoinGecko | `/coins/{id}/market_chart` | Volume history | Drying volume detection (Model 2) |
| 4 | CoinMarketCap | `/v1/cryptocurrency/listings/latest` | Top 24H gainers | Momentum screening |
| 4 | CoinGecko | `/coins/{id}/ohlc` | Daily OHLCV | Alternative candle data |

### 8.1 Recommended Libraries

| Library | Purpose | Language |
|---|---|---|
| pandas-ta | EMA, RSI, MACD, ATR, Swing H/L | Python |
| ta-lib | Technical indicators | Python (or C binding) |
| ccxt | Unified exchange OHLCV interface | Python / Node.js |
| requests / axios | HTTP REST API calls | Python / Node.js |
| APScheduler | Task scheduler | Python |
| node-cron | Task scheduler | Node.js |

---

## 9. Filter Pipeline Architecture

### 9.1 Problem Statement

If each model fetches its own coin universe from CoinGecko, there are 4 redundant API calls for identical data. Solution: 4-layer shared pipeline.

### 9.2 Filter Pipeline (4 Layers)

| Layer | Name | Frequency | Input | Output | Cache TTL |
|---|---|---|---|---|---|
| **Layer 1** | Shared Fetch | 1× per run (centralized) | CoinGecko API | Raw 300 coins (market cap, volume, 24h%) | 5 min |
| **Layer 2** | Shared Pre-Filter | Immediately after L1 | L1 output | ~150–200 coins (stablecoins, wrapped removed) | Cache lifetime |
| **Layer 3** | Model Secondary Filter | Per model from L2 | L2 output | 10–80 coins per model (model-specific rank/volume) | Cache lifetime |
| **Layer 4** | Heavy Analysis | Per model, only for L3 output | OHLCV, OI, Funding, CVD APIs | Final 10 coins ranked + scored | Per-model cache |

### 9.3 Layer 1 — Shared Fetch

One API call, result cached for 5 minutes, shared to all models.

```python
# shared_fetch.py
import requests, time

_cache = {'data': None, 'ts': 0}
CACHE_TTL = 300  # 5 minutes

def get_market_data():
    now = time.time()
    if _cache['data'] and (now - _cache['ts']) < CACHE_TTL:
        return _cache['data']
    
    url = 'https://api.coingecko.com/api/v3/coins/markets'
    params = {
        'vs_currency': 'usd',
        'order': 'market_cap_desc',
        'per_page': 300,
        'page': 1,
        'sparkline': False,
        'price_change_percentage': '24h'
    }
    resp = requests.get(url, params=params, timeout=10)
    resp.raise_for_status()
    
    _cache['data'] = resp.json()
    _cache['ts'] = now
    return _cache['data']
```

> **Fallback:** If CoinGecko fails, use Binance `/api/v3/ticker/24hr` for volume and price change data.

### 9.4 Layer 2 — Shared Pre-Filter

Universal filters applied to all 300 coins. Result: ~150–200 coins.

| Criterion | Rule | Reason |
|---|---|---|
| **NOT Stablecoin** | symbol not in {usdt, usdc, dai, busd, tusd, frax, usdd, usdp, gusd, lusd} | No meaningful price action |
| **NOT Wrapped Token** | name not containing wrapped/wbtc/weth/steth/reth/cbeth | Price mirrors underlying |
| **Minimum Volume** | total_volume >= $1,000,000 | <$1M too illiquid |
| **Minimum Market Cap** | market_cap >= $50,000,000 | <$50M too risky, derivatives sparse |
| **Data Present** | current_price and total_volume not null/0 | New coins may have incomplete data |

```python
# pre_filter.py
STABLECOINS = {'usdt','usdc','dai','busd','tusd','frax','usdd','usdp','gusd','lusd'}
WRAPPED_KW = ['wrapped','wbtc','weth','steth','reth','cbeth']

def pre_filter(coins):
    result = []
    for c in coins:
        sym = c['symbol'].lower()
        name = c['name'].lower()
        
        if sym in STABLECOINS:
            continue
        if any(kw in name for kw in WRAPPED_KW):
            continue
        if (c.get('total_volume') or 0) < 1_000_000:
            continue
        if (c.get('market_cap') or 0) < 50_000_000:
            continue
        if not c.get('current_price'):
            continue
        
        result.append(c)
    
    return result  # ~150-200 coins
```

### 9.5 Layer 3 — Model Secondary Filter

Each model applies its own filters to Layer 2 output.

```python
# model_filters.py
def filter_model1(coins):
    """Model 1: Rank 50-300, volume >= 5M"""
    return [c for c in coins
            if 50 <= c.get('market_cap_rank', 999) <= 300
            and (c.get('total_volume') or 0) >= 5_000_000]

def filter_model2(coins):
    """Model 2: Rank 20-150, volume >= 10M"""
    return [c for c in coins
            if 20 <= c.get('market_cap_rank', 999) <= 150
            and (c.get('total_volume') or 0) >= 10_000_000]

def filter_model3(coins):
    """Model 3: Rank 1-50, volume >= 50M"""
    return [c for c in coins
            if c.get('market_cap_rank', 999) <= 50
            and (c.get('total_volume') or 0) >= 50_000_000]

def filter_model4(coins):
    """Model 4: Top 10 gainers by 24h %"""
    sorted_by_change = sorted(coins, 
                              key=lambda x: x.get('price_change_percentage_24h', 0),
                              reverse=True)
    return sorted_by_change[:10]
```

### 9.6 Layer 4 — Heavy Analysis (Per Model)

Only after Layer 3 filtering do we fetch expensive data (OHLCV, OI, Funding, CVD).

- **Model 1:** Fetch OHLCV (1H/4H/15M) + OI + Funding + CVD for ~50–80 coins from L3. Heavy.
- **Model 2:** Fetch OHLCV (4H/1H) + OI + Funding + CVD for ~40–60 coins from L3. Heavy.
- **Model 3:** Fetch OHLCV (1D/4H) + OI + CVD for ~30–40 coins from L3. Medium.
- **Model 4:** Fetch OHLCV (1D) for 10 coins from L3. Light.

**Each model caches its Layer 3 subset separately** to avoid re-fetching when running again.

#### OHLCV In-Memory Cache — Key Format

If the same coin appears as a candidate in two different models, its OHLCV must NOT be fetched twice. Use a shared in-memory cache with `symbol_interval` as key.

| Cache Key Format | Example | Notes |
|---|---|---|
| `symbol_interval` | `BTC_1d` | Daily candles for Model 3 and Model 4 |
| `symbol_interval` | `AKT_4h` | 4H candles for Model 1 and Model 2 |
| `symbol_interval` | `AKT_15m` | 15M candles for Model 1 entry confirmation |
| `symbol_oi` | `AKT_oi` | Open Interest — cached by symbol |
| `symbol_fr` | `AKT_fr` | Funding Rate — cached by symbol |

```python
# ohlcv_cache.py
import requests, time

_ohlcv_cache: dict = {}
OHLCV_TTL = 300

def get_ohlcv(symbol: str, interval: str, limit: int = 10) -> list:
    key = f'{symbol.upper()}_{interval}'
    now = time.time()
    cached = _ohlcv_cache.get(key)
    if cached and (now - cached['ts']) < OHLCV_TTL:
        return cached['data']
    url    = 'https://api.binance.com/api/v3/klines'
    params = {'symbol': f'{symbol.upper()}USDT', 'interval': interval, 'limit': limit}
    resp   = requests.get(url, params=params, timeout=8)
    resp.raise_for_status()
    _ohlcv_cache[key] = {'data': resp.json(), 'ts': now}
    return _ohlcv_cache[key]['data']

def parse_candles(raw: list) -> list[dict]:
    return [{'open': float(c[1]), 'high': float(c[2]),
             'low':  float(c[3]), 'close': float(c[4]), 'volume': float(c[5])}
            for c in raw]
```

### 9.7 API Call Estimation — With vs. Without Pipeline

| Scenario | Shared Fetch | OHLCV per Model | Derivatives per Model | Total per Day |
|---|---|---|---|---|
| Without pipeline (each model scans all) | 4×/run × 96 runs = 384× | ~300×/run × 4 models | ~60×/run × 4 models | ~150,000+ calls/day |
| With pipeline (v2.1) | 1×/run, cached | ~15–40× per run per model | ~10–20× per model per run | ~5,000–8,000 calls/day |
| **Savings** | **75%** | **~90–95%** | **~80–90%** | **~95% reduction** |

- CoinGecko free tier: ~10–30 req/min. With shared fetch, well within limits.
- Binance public API: 1200 req/min (weight-based). OHLCV cache prevents re-fetch within 5 min.
- Coinalyze free tier is stricter (~10 req/min). OI and Funding only fetched for coins that pass Layer 3.
- Add `time.sleep(0.2)` between requests in the heavy analysis loop to avoid burst.

### 9.8 Standard Coin Data Structure

Format flowing from Layer 2 to all models. All models use the same base fields.

```python
# Coin dict after pre_filter (Layer 2 output)
{
    'id':                          'akash-network',
    'symbol':                      'akt',
    'name':                        'Akash Network',
    'current_price':               0.6327,
    'market_cap':                  185_412_492,
    'market_cap_rank':             78,
    'total_volume':                75_654_668,
    'price_change_percentage_24h': 14.22,
}

# Fields added after heavy analysis (Layer 4 output):
# 'candles_1d':    [{open, high, low, close, volume}, ...]  — Model 3 & 4
# 'candles_4h':    [{open, high, low, close, volume}, ...]  — Model 1 & 2
# 'candles_15m':   [{open, high, low, close, volume}, ...]  — Model 1 entry confirmation
# 'open_interest': 0.0   — USD value
# 'funding_rate':  0.0   — % (e.g. -0.001 = -0.1%)
# 'cvd_24h':       0.0   — cumulative volume delta (24H)
# 'stop_loss':     0.0   — calculated per-model
# 'score':         0.0   — final score 0–100
```

---

### 9.9 Pipeline Orchestrator

Each model service calls `run_pipeline(model=N)` — no model fetches data independently.

```python
# pipeline.py
from shared_fetch  import get_market_data
from pre_filter    import pre_filter
from model_filters import filter_model1, filter_model2, filter_model3, filter_model4
from ohlcv_cache   import get_ohlcv, parse_candles
from analysis      import analyze_model1, analyze_model2, analyze_model3, analyze_model4

def run_pipeline(model: int) -> list[dict]:
    raw_coins   = get_market_data()
    clean_coins = pre_filter(raw_coins)
    filters     = {1: filter_model1, 2: filter_model2,
                   3: filter_model3, 4: filter_model4}
    candidates  = filters[model](clean_coins)
    results = []
    for coin in candidates:
        try:
            sym = coin['symbol']
            if model in (1, 2):
                c4h  = parse_candles(get_ohlcv(sym, '4h',  limit=20))
                c15m = parse_candles(get_ohlcv(sym, '15m', limit=20))
            else:
                c1d  = parse_candles(get_ohlcv(sym, '1d',  limit=10))
            if model == 1: score = analyze_model1(coin, c4h, c15m)
            if model == 2: score = analyze_model2(coin, c4h)
            if model == 3: score = analyze_model3(coin, c1d)
            if model == 4: score = analyze_model4(coin, c1d)
            if score > 0: results.append({**coin, 'score': score})
        except Exception as e:
            import logging; logging.warning(f'Skip {coin["symbol"]}: {e}')
            continue
    return sorted(results, key=lambda x: x['score'], reverse=True)[:10]
```

### 9.10 Scheduler — Run Schedule Per Model

| Service | Interval | First Run | Scheduler Library |
|---|---|---|---|
| service_model1.py | Every 15 minutes | 07:00 WIB | `IntervalTrigger(minutes=15)` |
| service_model2.py | Every 4 hours | 07:00 WIB | `IntervalTrigger(hours=4)` |
| service_model3.py | Every 4 hours | 07:00 WIB | `IntervalTrigger(hours=4)` |
| service_model4.py | Once daily | 07:00 WIB exactly | `CronTrigger(hour=7, minute=0, timezone='Asia/Jakarta')` |

```python
# service_model4.py — scheduler example
from apscheduler.schedulers.blocking import BlockingScheduler
from apscheduler.triggers.cron import CronTrigger
from pipeline import run_pipeline
from notifier import send_alert
import logging

logging.basicConfig(level=logging.INFO)

def job_model4():
    logging.info('Model 4: running pipeline...')
    results = run_pipeline(model=4)
    if not results:
        send_alert('Model 4: no setup today.')
        return
    msg = 'Model 4 — Spot Gainers:\n'
    for i, r in enumerate(results, 1):
        sl  = r.get('stop_loss', 0)
        msg += f"{i}. {r['symbol'].upper()} | Price: {r['current_price']} | 24h: +{r['price_change_percentage_24h']:.1f}% | SL: {sl} | Score: {r['score']:.1f}\n"
    send_alert(msg)

scheduler = BlockingScheduler(timezone='Asia/Jakarta')
scheduler.add_job(job_model4, CronTrigger(hour=7, minute=0, timezone='Asia/Jakarta'))
scheduler.start()
```

---

## 10. Caching & Rate Limiting Policy

### 10.1 Cache Strategy

| Data Type | TTL | Storage | Shared? |
|---|---|---|---|
| Market data (L1) | 5 min | Redis/DB | Yes (all models) |
| Pre-filter result (L2) | 5 min | Redis/DB | Yes (all models) |
| OHLCV | 60 sec | Redis/DB | Yes (same coin across models) |
| OI, Funding | 120 sec | Redis/DB | Yes (same coin across models) |
| CVD | 300 sec | Redis/DB | Yes (same coin across models) |
| Model L3 subset | 60 sec | In-memory | Per model |
| Model signal output | No cache | File/DB | Permanent (for audit trail) |

### 10.2 Rate Limiting

| API | Tier | Rate Limit | Strategy |
|---|---|---|---|
| **CoinGecko** | Free | 10–30 req/min | Batch requests, share Layer 1 fetch |
| **Binance** | Public | 1200 req/min | No issue for our usage |
| **Coinalyze** | Premium required | Custom | Batch historical requests during off-peak |

### 10.3 Retry & Timeout Policy

- **Timeout:** 10 seconds for all external API calls.
- **Retry:** 3 attempts with exponential backoff (1s, 2s, 4s).
- **Circuit Breaker:** If API fails 5 consecutive times, skip that data source for 5 minutes.
- **Fallback:** Use cached stale data rather than returning error to model.

### 10.4 Error Handling & Fallback Table

| Error Condition | Action | Impact |
|---|---|---|
| CoinGecko rate limit (429) | Retry 1× after 60 seconds. If still failing, serve last known cache | Data may be stale; pipeline continues running |
| Binance OHLCV fails for 1 coin | Log warning, skip that coin, continue to next | Coin excluded from output; other models unaffected |
| Coinalyze unavailable | Skip derivatives step; run model without OI/Funding | Derivative score = 0; add flag `no_deriv: true` to output |
| All coins fail analysis | Send notification: "Pipeline error — no output for model X" | Developer must check VPS logs |
| Cache expired + API down | Serve last cache entry with flag `stale_data: true` | User can filter stale notifications downstream |

```python
# Pattern: try/except in heavy analysis loop
for coin in candidates:
    try:
        raw     = get_ohlcv(coin['symbol'], '1d', limit=10)
        candles = parse_candles(raw)
        score   = analyze_model(coin, candles)
        if score > 0:
            results.append({**coin, 'score': score})
    except Exception as e:
        logging.warning(f"Skip {coin['symbol']}: {e}")
        continue  # never crash entire pipeline for 1 coin
```

---

## 11. Implementation Guidance

### 11.1 CVD Calculation

```
CVD = cumulative(buy_volume - sell_volume)

1. Fetch Binance /api/v3/trades or /fapi/v1/aggTrades
2. For each trade:
   - isBuyerMaker = false  → buy volume
   - isBuyerMaker = true   → sell volume
3. CVD = sum(buy_volume) - sum(sell_volume)
4. Use linear regression for slope (recent 24 candles)
5. Reset daily at 00:00 UTC
```

### 11.2 Liquidity Sweep Detection

1. Define old highs and lows from swings ≥10 candles back.
2. Detect equal highs/lows within tolerance (±0.5%).
3. Sweep valid when: **wick breaks level BUT body closes inside range**.
4. Prefer 1H or 4H for robustness.

### 11.3 MSS Detection

1. Build swing highs/lows from OHLCV.
2. **Bullish MSS:** Candle body CLOSE > prior swing high (during downtrend).
3. **Bearish MSS:** Candle body CLOSE < prior swing low (during uptrend).
4. **Wick-only breaks do NOT qualify.**

### 11.4 Order Block Detection

- **Bearish OB** (for bullish reversal): Last bearish candle before strong up impulse.
- **Bullish OB** (for bearish reversal): Last bullish candle before strong down impulse.
- **OB zone:** High and low of that candle.
- **Entry criterion:** Close inside zone with small tolerance.

### 11.5 ATR Compression (Model 2)

1. Calculate ATR 14-period from 4H OHLCV.
2. Compare against 30-day rolling average ATR.
3. **Compression:** ATR < 30-day average.
4. **Stronger signal:** Lower ATR relative to baseline.

---

## 12. Agent Change Protocol

When agents modify this system, they MUST follow this sequence:

1. **Identify scope:** Does change affect one model or shared infrastructure?
2. **Isolate model logic:** Keep changes isolated unless explicitly cross-model.
3. **Preserve contracts:** Never change JSON output schema without version bump.
4. **No direct API calls:** All model data must come from cache.
5. **Update cache policy:** If adding new data fields, update caching rules.
6. **Test scoring:** Add or update tests for scoring logic, thresholds, gates.
7. **Document impact:** Explain threshold changes and behavioral impact.

---

## 13. Developer Checklist

### Infrastructure

- [ ] Linux VPS (Ubuntu 20.04+ or Debian 11+) provisioned
- [ ] Python 3.9+ or Node.js 18+ installed
- [ ] Redis or PostgreSQL set up for caching
- [ ] Required libraries installed (pandas-ta, ccxt, requests, APScheduler)
- [ ] `.env` file created with API keys
- [ ] Cron/scheduler configured for all 5 services
- [ ] Notifier (Telegram/Discord) configured and tested
- [ ] Logging system implemented for all services
- [ ] Error handling with circuit breaker patterns implemented
- [ ] Unit and integration tests framework set up

### Shared Data Pipeline

- [ ] Layer 1 (Shared Fetch) implemented: CoinGecko `/coins/markets` call
- [ ] Layer 1 caching (5 min TTL) working
- [ ] Layer 2 (Pre-Filter) implemented: stablecoins, wrapped tokens, min vol/cap excluded
- [ ] Layer 2 result (~150–200 coins) cached
- [ ] Cache hit/miss logging in place
- [ ] Retry + timeout logic implemented (3 attempts, 10s timeout)
- [ ] Circuit breaker for API failures in place

### Model 1 — Counter Trend

- [ ] Separate service boundary: `service-counter-trend.py`
- [ ] Layer 3 filter: rank 50–300, volume >= $5M
- [ ] Liquidity sweep detection: wick vs body close logic
- [ ] MSS detection: swing high/low calculation, body close validation
- [ ] FVG/OB calculation: gap and order block logic
- [ ] OI decline tracking: >5% drop detection
- [ ] Funding rate extreme check: <-0.1% or >+0.1%
- [ ] CVD divergence calculation: bearish div when price up, CVD down
- [ ] Scoring weights applied: 40% sweep, 30% MSS, 15% FVG/OB, 8% OI, 7% funding
- [ ] JSON output format validated (model, timestamp, results, components)
- [ ] Score normalized to 0–100
- [ ] Schedule: every 15 minutes
- [ ] Logging: all signals with execution_id
- [ ] Tests: pass/fail criteria for each component

### Model 2 — Pre-Pump Detector

- [ ] Separate service boundary: `service-pre-pump.py`
- [ ] Layer 3 filter: rank 20–150, volume >= $10M
- [ ] Persistent negative funding screening: 3 consecutive 8H periods < -0.05%
- [ ] OI rising + price sideways: OI >10% in 24H, price <3% range
- [ ] ATR compression: ATR 14 < 30-day average
- [ ] Volume drying: 24H vol < 50% of 7-day avg
- [ ] CVD divergence: CVD up in 24H while price flat
- [ ] RSI compression: RSI between 45–55 for >5 candles on 4H
- [ ] Scoring weights applied: 35% funding, 25% OI, 20% ATR, 12% CVD, 8% RSI
- [ ] JSON output format validated
- [ ] Score normalized to 0–100
- [ ] Schedule: every 4 hours
- [ ] Logging: all signals with execution_id
- [ ] Tests: pass/fail for each component

### Model 3 — Trend Momentum

- [ ] Separate service boundary: `service-trend-momentum.py`
- [ ] Layer 3 filter: rank 1–50, volume >= $50M
- [ ] EMA50 & EMA200 calculated on daily OHLCV
- [ ] EMA gate: price > EMA50 > EMA200 required (hard skip if not)
- [ ] EMA slope positive for ≥3 consecutive days
- [ ] RSI 14 zone 50–65 check on 4H
- [ ] MACD (12,26,9) calculated: MACD > Signal > 0, histogram positive
- [ ] BOS detection: 4H close breaks prior swing high
- [ ] OI rising + price rising: both >5% in 24H
- [ ] CVD positive trending: last 24H
- [ ] Scoring weights applied: 30% EMA, 25% MACD, 20% RSI, 15% BOS, 10% OI+CVD
- [ ] JSON output format validated
- [ ] Score normalized to 0–100
- [ ] Schedule: every 4 hours
- [ ] Logging: all signals with execution_id
- [ ] Tests: pass/fail for each component

### Model 4 — Spot Momentum Gainers

- [ ] Separate service boundary: `service-spot-gainers.py`
- [ ] Fetch top 10 from CoinMarketCap sorted by 24H% descending
- [ ] Layer 3 filter: rank 1–200, market cap >= $100M
- [ ] Exclude stablecoins: USDT, USDC, DAI, BUSD, etc.
- [ ] Exclude wrapped tokens: WBTC, WETH, STETH, etc.
- [ ] Fetch 7 daily candles from Binance `/api/v3/klines?interval=1d`
- [ ] Bullish candle criteria implemented (all 5 required):
  - [ ] Green candle: close > open
  - [ ] Large body: (close-open)/(high-low) >= 0.6
  - [ ] Minimal wick: (high-close)/(close-open) <= 0.2
  - [ ] Close > prior high: close[today] > high[yesterday]
  - [ ] High volume: volume[today] > mean(volume[-6:-1])
- [ ] Volume ratio: volume_today / avg_5_day
- [ ] Body ratio: (close-open)/(high-low)
- [ ] Upper wick ratio: (high-close)/(close-open)
- [ ] Output format: symbol, price, 24h%, volume_ratio, body_ratio, stop_loss, score
- [ ] Stop loss: low of trigger daily candle
- [ ] Scoring weights: gate (all 5 criteria), 40% change, 35% vol ratio, 25% body ratio
- [ ] Notification if 1+ coins pass
- [ ] Notification if 0 coins pass ("No setup today")
- [ ] Schedule: daily at 07:00 WIB (UTC+7)
- [ ] **SPOT ONLY verification:** No short positions, no leverage flags
- [ ] Logging: all watchlist entries with execution_id
- [ ] Tests: pass/fail for each criterion

### Testing & Validation

- [ ] Unit tests for all scoring functions
- [ ] Integration tests for each model (fetch → filter → score → output)
- [ ] Cache hit/miss tests
- [ ] Retry logic tests (API failure scenarios)
- [ ] JSON schema validation tests
- [ ] End-to-end test: run all 5 services, verify output format
- [ ] Performance test: execution time per model

---

## 14. Final Scope Reminder

**This is a SIGNAL GENERATION SYSTEM ONLY.**

- Does NOT execute trades.
- Does NOT manage positions.
- Does NOT access exchange accounts.
- Does NOT place orders.

**Trade execution must be manual or handled by a separate risk-managed execution layer.**