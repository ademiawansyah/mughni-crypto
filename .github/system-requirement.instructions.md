# CRYPTO TRADING SYSTEM
## Technical Specification and Developer Blueprint

Version: 2.0
Year: 2026
Audience: Developers and AI Coding Agents

---

## 0. System Overview

This document is the **source of truth** and technical blueprint for building an automated crypto signal system on a Linux VPS. It defines **4 independent trading models** that MUST NOT be merged with each other. Each model has its own logic, data sources, timeframes, and entry/exit conditions.

| Property | Detail |
|---|---|
| Models | 4 (independent, not integrated) |
| Data Sources | Public APIs only — CoinGecko, Binance, Coinalyze, CoinMarketCap |
| Implementation Language | Flexible (Python or Node.js recommended) |
| Deployment Target | Linux VPS |
| Output | Top 10 coins per model with total score and component-level details |

**Critical Rules:**
- All four models MUST run as separate services or processes.
- Each model produces its own ranked list — never a merged list.
- Derivatives data (OI, Funding, CVD) is confirmation only — NOT the primary trigger.
- All data used must be publicly accessible — no paid authentication required.
- Model 4 is SPOT trading only — no short positions, no leverage.

---

## 1. Purpose

This system generates ranked trade signals. It does NOT execute trades. Trade execution must remain manual or be handled by a separate risk-managed execution layer.

---

## 2. Non-Negotiable Rules

- Keep all four models fully independent.
- Never merge model outputs into one shared signal list.
- Use public market data only.
- Run each model as a separate service or process.
- Treat derivatives data as confirmation, not the primary trigger.
- Output Top 10 coins per model, with component-level scoring details.
- Model 4 is long-only spot — no short exposure, no leverage.

---

## 3. High-Level Architecture

### 3.1 Services

| Service | Schedule | Output |
|---|---|---|
| service-data-fetcher | Every 5 minutes | Shared OHLCV and derivatives cache |
| service-counter-trend (Model 1) | Every 15 minutes | Top 10 Counter Trend signals |
| service-pre-pump (Model 2) | Every 4 hours | Top 10 Pre-Pump signals |
| service-trend-momentum (Model 3) | Every 4 hours | Top 10 Trend Momentum signals |
| service-spot-gainers (Model 4) | Daily at 07:00 WIB (UTC+7) | Top 10 Spot Momentum signals |
| notifier | On demand (called by each model) | Alerts via Telegram or Discord |

### 3.2 Required Execution Pattern
- Use one centralized data-fetcher for all shared data.
- Store and reuse cached data via Redis and/or database storage.
- Model services consume cached data only.
- Model services MUST NOT call external APIs directly.

### 3.3 Standard Output Contract

Each model returns JSON with this structure:

```json
{
  "model": "model1_counter_trend",
  "timestamp": "2026-05-04T07:00:00+07:00",
  "results": [
    {
      "rank": 1,
      "symbol": "BNBUSDT",
      "total_score": 87.5,
      "components": {
        "liquidity_sweep": true,
        "mss_confirmed": true,
        "fvg_ob_entry": 0.75,
        "oi_declining": 0.85,
        "funding_extreme": 0.70
      }
    }
  ]
}
```

---

## 4. MODEL 1: COUNTER TREND

**Strategy:** Mia Style — Liquidity Sweep + Market Structure Shift + Exhaustion Reversal Detection

### 4.1 Description and Philosophy

This model detects price reversal points where price manipulation meets market exhaustion. The goal is not to chase aggression but to find the exact moment when one side's pressure reaches its maximum and begins to reverse.

**Core philosophy:** Smart money sweeps liquidity (retail stop-losses) before reversing. The system detects the moment after that sweep occurs.

### 4.2 Coin Universe

| Parameter | Value |
|---|---|
| Target Coins | Volatile altcoins (market cap rank 50–300) |
| Minimum Filter | 24H volume > $5 million, exclude stablecoins |
| Source | CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=300` |

### 4.3 Timeframes

| Component | Timeframe |
|---|---|
| Structure Screening | 1H or 4H |
| Entry Confirmation | 15M |
| Macro Context Filter | 1D (optional) |

### 4.4 Signal Components and Logic

#### A. Price Action — Mia Style (Primary Trigger)

These three conditions MUST be fulfilled in sequence:

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| Liquidity Sweep | Price breaks an Old High/Low or Equal H/L then reverses | Wick breaks level, candle body closes back inside range | OHLCV via CoinGecko `/coins/{id}/ohlc` or Binance `/api/v3/klines` |
| MSS (Market Structure Shift) | Sudden trend character change with a body break | Candle CLOSE crosses a swing point in the opposite direction | Calculate swing highs/lows from OHLCV (pandas-ta / ta-lib) |
| FVG / OB Entry | Price retraces into an imbalance zone after MSS | Fair Value Gap: gap across 3 candles. Order Block: last candle before impulse move | Local calculation from OHLCV data |

#### B. Derivatives — Confirmation (Applied After Primary Trigger)

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| Open Interest | OI declines when price sweeps (exhaustion signal) | OI drops >5% concurrent with price spike | Coinalyze `/futures/open-interest` or Bybit `/v5/market/open-interest` |
| Funding Rate | Extreme funding = potential reversal | Funding < -0.1% or > +0.1% (calibratable threshold) | Coinalyze `/futures/funding-rate` |
| CVD (Cumulative Volume Delta) | CVD divergence with price = exhaustion confirmation | Price makes new high but CVD declines (bearish divergence) | Calculated from trade data: Binance `/api/v3/trades` (buy vs sell side) |

### 4.5 Scoring Weights

| Signal | Weight | Notes |
|---|---|---|
| Liquidity sweep confirmed | 40% | **Required** — skip coin if absent |
| MSS formed | 30% | **Required** |
| FVG/OB as entry zone | 15% | Optional but raises score |
| OI declining during sweep | 8% | Derivatives confirmation |
| Extreme funding rate | 7% | Derivatives confirmation |

> All scores normalized to 0–100. Include component scores in output for transparency.

---

## 5. MODEL 2: PRE-PUMP DETECTOR

**Strategy:** Pressure Cooker + Momentum Runner — Short Squeeze Expansion Setup

### 5.1 Description and Philosophy

This model detects coins in a compression phase before a major breakout. Persistent short pressure (negative funding), drying volume, and low volatility create a "pressure cooker" condition — when it explodes, the move is significant.

### 5.2 Coin Universe

| Parameter | Value |
|---|---|
| Target Coins | Mid-cap (market cap rank 20–150) |
| Minimum Filter | 24H volume > $10 million, futures market available (OI accessible) |
| Source | CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=150` |

### 5.3 Timeframes

| Component | Timeframe |
|---|---|
| Funding Rate Screening | Real-time / 8H intervals |
| OI and Volume Screening | 4H |
| Entry Confirmation | 1H |

### 5.4 Signal Components and Logic

#### A. Funding Rate Squeeze

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| Persistent Negative Funding | Short sellers dominate — potential short squeeze | Funding < -0.05% per 8H for 3 consecutive periods | Coinalyze `/futures/funding-rate` |
| OI Rising + Price Sideways | Positions building while price does not rise = time bomb | OI rises >10% in 24H, price in range <3% | Coinalyze `/futures/open-interest` |
| Low ATR | Volatility compressing = before an explosion | ATR 14-period below 30-day average | Calculated from OHLCV (ta-lib) |

#### B. Momentum Runner

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| Drying Volume | Volume drops drastically = silent accumulation | 24H volume drops >50% from 7-day average | CoinGecko `/coins/{id}/market_chart` |
| CVD Divergence | CVD quietly rising while volume drops = accumulation | CVD trending up in last 24H while price flat | Binance public trade data |
| RSI Compression | RSI trapped in neutral zone = ready for breakout | RSI 14 between 45–55 for >5 candles on 4H | Calculated from OHLCV (pandas-ta) |

### 5.5 Scoring Weights

| Signal | Weight | Notes |
|---|---|---|
| Persistent negative funding rate | 35% | Primary trigger |
| OI rising + price sideways | 25% | Accumulation confirmation |
| Low ATR (volatility compression) | 20% | Lower = better |
| CVD quietly rising | 12% | Hidden accumulation signal |
| RSI compression | 8% | Technical confirmation |

---

## 6. MODEL 3: TREND MOMENTUM

**Strategy:** MACD-RSI-EMA Confirmation System — Follow Confirmed Breakout Continuation

### 6.1 Description and Philosophy

This model detects coins already in a strong trend that still have room to continue. It does not catch bottoms — it follows proven momentum. Entry is only taken after trend structure is confirmed, not on speculation.

### 6.2 Coin Universe

| Parameter | Value |
|---|---|
| Target Coins | Large-cap (market cap rank 1–50); BTC and ETH always included |
| Minimum Filter | 24H volume > $50 million, listed for at least 6 months |
| Source | CoinGecko `/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=50` |

### 6.3 Timeframes

| Component | Timeframe |
|---|---|
| Trend Filter (EMA) | 1D |
| Entry Signal (MACD + RSI) | 4H |
| BOS Confirmation | 1D or 4H |

### 6.4 Signal Components and Logic

#### A. EMA Filter (Trend Direction Gate)

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| EMA 50 & 200 | Price above both EMAs = bullish trend | Close > EMA50 > EMA200, EMA spread widening | Calculated from daily OHLCV (ta-lib / pandas-ta) |
| EMA Slope | EMA rising = healthy trend | EMA50 slope positive for at least 3 consecutive days | Calculate delta EMA per period |

#### B. RSI and MACD Synergy

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| RSI Momentum Zone | RSI 50–65 = strong momentum without overbought | RSI 14 in range 50–65 on 4H | pandas-ta / ta-lib from OHLCV |
| MACD Confirmation | MACD line above signal, both above 0 | MACD > Signal > 0, histogram positive and expanding | Calculate MACD (12,26,9) from close price |
| BOS (Break of Structure) | Coin continues making higher highs = valid trend structure | 4H close breaks prior swing high | Detect swing highs from local OHLCV |

#### C. Derivatives — Trend Health Confirmation

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| OI Rising + Price Rising | New money entering = healthy trend | OI and price both rise >5% in 24H | Coinalyze `/futures/open-interest` |
| Positive CVD | Buyer aggression dominates | CVD trending positive over last 24H | Binance trade data |

### 6.5 Scoring Weights

| Signal | Weight | Notes |
|---|---|---|
| EMA filter satisfied (price > EMA50 > EMA200) | 30% | **Gate** — skip coin if not satisfied |
| MACD in positive zone | 25% | Momentum confirmation |
| RSI in zone 50–65 | 20% | Quality filter |
| BOS confirmed | 15% | Valid trend structure |
| OI + CVD positive | 10% | Derivatives confirmation |

---

## 7. MODEL 4: SPOT MOMENTUM GAINERS

**Strategy:** CMC Top Gainers + Bullish Candle + Volume Screening

> **This model is SPOT ONLY. No short positions. No leverage.**

### 7.1 Description and Philosophy

This model is a pure spot trading strategy exploiting short-term momentum. The logic is straightforward: coins that enter the top 24H gainers list with a strong confirmed bullish candle and high volume are likely in momentum that can continue for several days.

**Core philosophy:** Follow momentum that is proven today. Enter with measured risk, exit with discipline when momentum reverses or target is reached.

### 7.2 Coin Universe

| Parameter | Value |
|---|---|
| Target Coins | Top 200 by market cap |
| Minimum Filter | Market cap > $100 million, exclude stablecoins and wrapped tokens |
| Source | CoinMarketCap `/v1/cryptocurrency/listings/latest?limit=200&sort=market_cap` |
| Selection | Sorted by 24H percentage change descending — take top 10 |

### 7.3 Timeframes

| Component | Timeframe |
|---|---|
| Gainers Screening | 24H (every morning ~07:00 WIB / UTC+7) |
| Candle + Volume Validation | 1D (Daily chart) |
| Hold Period | Several days (short swing) |

### 7.4 Step-by-Step Workflow

#### Step 01 — Fetch Top Gainers

| Component | Description | Detection Method | Data Source |
|---|---|---|---|
| Fetch data | Top 200 market cap coins sorted by 24H% descending | Take 10 coins with highest 24H% | CoinMarketCap `/v1/cryptocurrency/listings/latest` or CoinGecko `/coins/markets?order=percent_change_24h_desc` |

#### Step 02 — Validate Bullish Candle (1D)

Each coin from the top 10 must be validated against the daily chart. A setup is valid only if **ALL** of the following conditions are satisfied:

| Criterion | Definition | System Detection |
|---|---|---|
| Green candle | Close is higher than open | `close > open` on the last daily candle |
| Large body | Candle body is at least 60% of total range (high-low) | `(close - open) / (high - low) >= 0.6` |
| Minimal upper wick | Upper wick is no more than 20% of body | `(high - close) / (close - open) <= 0.2` |
| Close > prior high | Today's close exceeds yesterday's high | `close[today] > high[yesterday]` |
| High volume | Today's volume exceeds the 5-bar prior average | `volume[today] > mean(volume[today-5 : today-1])` |

**If all 5 criteria are met:** asset enters the entry watchlist.
**If any single criterion fails:** skip, move to next coin.
**If 0 coins pass from the top 10:** skip that day entirely. No forced entry.

#### Step 03 — Entry and Stop Loss

| Parameter | Value / Formula |
|---|---|
| Entry Time | Morning after screening (~07:15–07:30 WIB) |
| Order Type | Market order or limit order near candle close price |
| Stop Loss | Below the low of the trigger bullish candle |
| Position Sizing | Based on user-defined risk per trade (e.g., 1–2% of capital) |
| Sizing Formula | `units = (capital × risk%) / (entry_price - stop_loss_price)` |

#### Step 04 — Exit Management

| Exit Condition | Action | Notes |
|---|---|---|
| Profit reaches +2R | Exit partial or full | 2R = profit 2× the risk taken |
| Bearish candle forms | Exit immediately | Opposite of bullish criteria: large red body, close < prior low |
| Trailing stop loss | Move SL below new higher low | Each time price makes a higher low, move SL there |
| No change | Hold | Re-evaluate next morning |

### 7.5 Bearish Candle Exit Trigger Definition

A bearish candle is a valid exit signal when ALL of these conditions are met:
- Red candle: `close < open`
- Large body: `(open - close) / (high - low) >= 0.6`
- Close is lower than prior candle's low
- Volume above 5-bar average (optional but strengthens the signal)

### 7.6 Core Logic Pseudocode

```
EVERY DAY AT 07:00 WIB:

1. top_gainers = top 10 from /listings/latest sorted by 24h_change DESC
2. Filter: market_cap > 100M, exclude stablecoin/wrapped token
3. For each coin in top_gainers:
   a. Fetch last 7 daily candles (OHLCV)
   b. candle_today = candle[-1], candle_prev = candle[-2]
   c. Check bullish criteria:
      - close > open                              (green candle)
      - body_ratio = (close-open)/(high-low) >= 0.6
      - upper_wick_ratio = (high-close)/(close-open) <= 0.2
      - close > candle_prev.high                 (breakout)
      - volume > mean(volume[-6:-1])             (volume spike)
   d. If all 5 satisfied: add to watchlist_entry
4. Output watchlist_entry to notification / dashboard
5. Record stop_loss = low[candle_today] for each entry
```

### 7.7 Scoring and Output

| Component | Weight | Notes |
|---|---|---|
| All 5 candle criteria satisfied | Gate (required) | Not satisfied = coin excluded entirely |
| 24H percentage change magnitude | 40% | Higher = more priority |
| Volume spike ratio | 35% | `volume_today / avg_volume_5_days` — higher = better |
| Candle body ratio | 25% | Larger body = stronger momentum |

### 7.8 Model 4 vs Other Models

| Aspect | Model 1 | Model 2 | Model 3 | Model 4 |
|---|---|---|---|---|
| Trade type | Reversal | Pre-breakout | Trend following | Momentum spot |
| Position direction | Long / Short | Long / Short | Long / Short | Long only (SPOT) |
| Coin universe | Rank 50–300 | Rank 20–150 | Rank 1–50 | Rank 1–200 (top gainers) |
| Primary timeframe | 15M–4H | 4H–1D | 4H–1D | 1D |
| Leverage | Optional | Optional | Optional | None |
| Signal frequency | Several per day | Several per week | Several per week | Once per day (morning) |
| Hold period | Hours to days | Days to weeks | Weeks | Days |

---

## 8. Data Sources and API Endpoints

Use public APIs only.

| Model | Provider | Endpoint | Data |
|---|---|---|---|
| Model 1–3 | CoinGecko | `/coins/markets` | Coin list + market cap + volume |
| Model 1–4 | Binance | `/api/v3/klines` | OHLCV all timeframes |
| Model 1–3 | Coinalyze | `/futures/open-interest` | Open Interest per coin |
| Model 1–3 | Coinalyze | `/futures/funding-rate` | Historical funding rates |
| Model 1–3 | Binance | `/api/v3/trades` | Raw trades (for CVD calculation) |
| Model 4 | CoinMarketCap | `/v1/cryptocurrency/listings/latest` | Top 24H gainers |
| Model 4 | CoinGecko | `/coins/{id}/ohlc` | Alternative daily OHLCV |
| Model 4 | Binance | `/api/v3/klines?interval=1d` | Daily OHLCV |

### 8.1 Recommended Libraries

| Library | Purpose |
|---|---|
| pandas-ta / ta-lib | Calculate EMA, RSI, MACD, ATR, Swing H/L |
| ccxt | Unified interface for OHLCV from multiple exchanges |
| requests / axios | HTTP calls to REST APIs |
| APScheduler (Python) | Daily task scheduler (Model 4 at 07:00 WIB) |
| node-cron (Node.js) | Alternative scheduler for Node.js |

---

## 9. Caching and Rate Limit Policy

- Centralize all API requests in the data-fetcher service.
- Cache market data in Redis and/or database storage.
- Keep at least 200 OHLCV candles per symbol per timeframe.
- Batch CoinGecko requests where possible.
- Apply retry and timeout handling for all external requests.
- Model services read from cache only — never call external APIs directly.

---

## 10. Implementation Guidance and Edge Cases

### 10.1 CVD Calculation
- Pull trade/aggTrades data from Binance.
- `isBuyerMaker = false` → treat as buy volume.
- `isBuyerMaker = true` → treat as sell volume.
- CVD = cumulative (buy volume − sell volume).
- Use linear regression over recent CVD points for slope checks.
- Reset daily at 00:00 UTC for consistency.

### 10.2 Liquidity Sweep Detection
- Define old highs and lows from swings at least 10 candles back.
- Detect equal highs and lows within tolerance bands.
- A sweep is valid when the wick breaks the level but the candle body closes back inside the range.
- Prefer 1H or 4H timeframes for robust sweep detection.

### 10.3 MSS Detection
- Build swing highs and lows from OHLCV data.
- Bullish MSS: candle body CLOSE above prior swing high during a downtrend.
- Bearish MSS: candle body CLOSE below prior swing low during an uptrend.
- Wick-only breaks do NOT qualify as MSS.

### 10.4 Order Block Detection
- Bearish OB for bullish reversal = last bearish candle before a strong up impulse.
- Bullish OB for bearish reversal = last bullish candle before a strong down impulse.
- OB zone is defined by that candle's high and low boundaries.
- Price is "at OB" when close is inside the zone with a small tolerance.

### 10.5 ATR Compression Check (Model 2)
- Calculate ATR 14-period from 4H OHLCV.
- Compare against 30-day rolling average ATR.
- ATR below the 30-day average signals compression.
- Lower ATR relative to historical baseline = stronger compression signal.

---

## 11. Agent Change Protocol

When future agents modify this system, they MUST follow this order:

1. Verify whether the change affects one model or shared infrastructure.
2. Keep model logic isolated unless the change is explicitly cross-model.
3. Preserve the output schema and component scoring transparency.
4. Do not add direct API calls to model services — all data comes from cache.
5. Update cache and rate-limit handling when adding new data fields.
6. Add or update tests for scoring logic, thresholds, and signal gates.
7. Document any threshold changes and their expected behavioral impact.

---

## 12. Developer Checklist

### Infrastructure
- [ ] Centralized data-fetcher service in place
- [ ] Redis or equivalent cache in place
- [ ] API rate-limit and retry policy implemented
- [ ] Historical storage available for indicators and derivatives
- [ ] Error handling and logging implemented
- [ ] Notifier service implemented (Telegram or Discord)
- [ ] Unit and integration tests maintained

### Model 1 — Counter Trend
- [ ] Separate service boundary maintained
- [ ] Coin universe filter: rank 50–300, volume > $5M
- [ ] Liquidity sweep detection validated (wick vs body close)
- [ ] MSS body-close validation implemented (no wick-only)
- [ ] FVG and OB detection logic implemented
- [ ] OI decline confirmation tracked
- [ ] Extreme funding rate confirmation tracked
- [ ] Weighted scoring and JSON output validated

### Model 2 — Pre-Pump Detector
- [ ] Separate service boundary maintained
- [ ] Coin universe filter: rank 20–150, volume > $10M, OI available
- [ ] Persistent negative funding screening (3 consecutive 8H periods)
- [ ] OI rising + price sideways detection implemented
- [ ] ATR compression vs 30-day baseline implemented
- [ ] CVD slope (quietly rising) implemented
- [ ] RSI compression zone (45–55) implemented
- [ ] Weighted scoring and JSON output validated

### Model 3 — Trend Momentum
- [ ] Separate service boundary maintained
- [ ] Coin universe filter: rank 1–50, volume > $50M
- [ ] EMA50 and EMA200 calculations on 1D timeframe
- [ ] EMA gate: price > EMA50 > EMA200 required
- [ ] EMA slope positive for 3+ consecutive days
- [ ] RSI in 50–65 zone on 4H
- [ ] MACD positive zone confirmed (12,26,9)
- [ ] BOS continuity logic implemented
- [ ] OI + CVD positive confirmation implemented
- [ ] Weighted scoring and JSON output validated

### Model 4 — Spot Gainers
- [ ] Separate service boundary maintained
- [ ] Coin universe: top 200 market cap, sorted 24H% descending, take top 10
- [ ] Exclude: stablecoins (USDT, USDC, DAI, BUSD), wrapped tokens (WBTC, WETH), market cap < $100M
- [ ] Fetch 7 daily candles from Binance `/api/v3/klines`
- [ ] All 5 bullish candle criteria implemented (see Section 7.4 Step 02)
- [ ] Volume ratio calculated: `volume_today / mean(volume[-6:-1])`
- [ ] Body ratio calculated: `(close - open) / (high - low)`
- [ ] Upper wick ratio calculated: `(high - close) / (close - open)`
- [ ] Output: symbol, price, 24h%, volume_ratio, body_ratio, stop_loss, score
- [ ] Stop loss = low of the trigger daily candle
- [ ] Send notification if 1+ coins pass criteria
- [ ] Send "No setup today" notification if 0 coins pass
- [ ] No short positions, no leverage — SPOT ONLY

---

## 13. Final Scope Reminder

This is a **signal generation system only**.
Trade execution must remain manual or be handled by a separate risk-managed execution layer.
This system does not place orders, manage positions, or control any exchange account.