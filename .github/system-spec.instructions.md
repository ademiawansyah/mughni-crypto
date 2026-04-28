# CRYPTO TRADING SYSTEM
## Technical Specification & Developer Blueprint

**Version:** 1.0  
**Year:** 2025  
**Usage:** Internal Developer Reference  

---

# 0. Overview

This document defines a crypto trading signal system consisting of **three independent models**.  
Each model operates separately and must NOT be combined at the signal level.

## Core Principles
- 3 independent models (no merging)
- Public data only (no paid/private APIs)
- Signal system (NOT auto-trading)
- Output = Top 10 coins per model

## System Properties

| Property        | Detail |
|----------------|--------|
| Models         | 3 (independent) |
| Data Sources   | CoinGecko, Binance, Coinalyze |
| Language       | Python / Node.js |
| Platform       | VPS (Linux) |
| Output         | Top 10 ranked coins |

---

# MODEL 1 — COUNTER TREND
## Reversal Detection (Liquidity Sweep + Exhaustion)

### Philosophy
Detect reversals after liquidity sweep and market exhaustion.

---

## Timeframes

| Component              | Timeframe |
|------------------------|----------|
| Structure              | 1H / 4H |
| Entry Confirmation     | 15M |
| Macro Context          | 1D (optional) |

---

## Signal Logic

### A. Price Action (Primary Trigger)

- Liquidity Sweep  
- MSS (Market Structure Shift)  
- FVG / Order Block entry  

### B. Derivatives (Confirmation Only)

| Parameter | Condition |
|----------|----------|
| OI       | Decreasing after sweep |
| Funding  | Moving from negative → neutral |
| CVD      | Bullish divergence |
| ATR      | Spike → stabilization |

---

## Scoring

| Component | Weight |
|----------|--------|
| Liquidity Sweep | 30% |
| MSS | 25% |
| OI | 15% |
| CVD | 15% |
| Funding | 10% |
| Volatility | 5% |

---

# MODEL 2 — PRE-PUMP
## Short Squeeze Detection

### Philosophy
Detect coins under pressure ready for breakout (short squeeze).

---

## Timeframes

| Component | Timeframe |
|----------|----------|
| Funding | Real-time |
| OI | 1H |
| ATR | 4H |
| Entry | 15M–1H |

---

## Signal Logic

### A. Funding (Primary Filter)
- Extreme negative funding (< -0.05%)
- Consistent negative periods

### B. Momentum Strength
- Relative strength vs BTC
- Volume > 150% average

### C. Compression + Expansion

| Parameter | Condition |
|----------|----------|
| OI | Increasing during sideways |
| ATR | Compression |
| Price | At OB/POI |
| CVD | Positive slope |

---

## Scoring

| Component | Weight |
|----------|--------|
| Funding | 35% |
| ATR | 25% |
| OI | 20% |
| RS vs BTC | 10% |
| CVD | 10% |

---

# MODEL 3 — TREND MOMENTUM
## Breakout Continuation Strategy

### Philosophy
Follow strong trends with confirmation.

---

## Timeframes

| Component | Timeframe |
|----------|----------|
| EMA Filter | 4H / 1D |
| MACD/RSI | 4H |
| BOS | 1H–4H |
| Entry | 15M |

---

## Signal Logic

### A. Trend Structure
- Price > EMA50 > EMA200
- EMA slope positive
- EMA spread widening

### B. Momentum

| Indicator | Condition |
|----------|----------|
| RSI | 50–65 |
| MACD | Above signal & zero |
| Histogram | Increasing |

### C. Confirmation

| Parameter | Condition |
|----------|----------|
| OI | Rising with price |
| CVD | Positive slope |
| BOS | Higher highs |

---

## Scoring

| Component | Weight |
|----------|--------|
| EMA Filter | 25% |
| MACD | 20% |
| RSI | 15% |
| OI | 20% |
| BOS | 10% |
| CVD | 10% |

---

# 4. Data Sources

| Data | Provider | Endpoint |
|------|--------|---------|
| OHLCV | Binance | /api/v3/klines |
| OHLCV | CoinGecko | /coins/{id}/ohlc |
| OI | Binance | /fapi/v1/openInterest |
| Funding | Binance | /fapi/v1/fundingRate |
| Trades | Binance | /fapi/v1/aggTrades |
| Market Data | CoinGecko | /coins/markets |

---

# 5. System Architecture

## Services

| Service | Interval | Output |
|--------|---------|--------|
| data-fetcher | 5 min | Raw data |
| counter-trend | 15 min | Top 10 |
| pre-pump | 30 min | Top 10 |
| momentum | 1 hour | Top 10 |
| api-gateway | on-demand | REST |

---

## Output Format

```json
{
  "model": "counter_trend",
  "timestamp": "...",
  "top_coins": [
    {
      "symbol": "BTCUSDT",
      "total_score": 87.5,
      "components": {
        "liquidity_sweep": true,
        "mss_confirmed": true
      }
    }
  ]
}
```

---

# 5.3 Rate Limiting

- Use centralized data-fetcher
- Cache in Redis
- Avoid direct API calls from models

---

# 6. Implementation Notes

## CVD Calculation
- From aggTrades
- buy_vol - sell_vol cumulative

## Liquidity Sweep
- Wick breaks level
- Close returns inside

## MSS
- Body close breaks structure

## Order Block
- Last opposite candle before impulse

---

# 6.5 Coin Universe

- Scan Binance futures pairs
- Min volume: $5M
- Min OI: $1M
- Exclude stablecoins

---

# 7. Developer Checklist

## Model 1
- [ ] Sweep detection
- [ ] MSS detection
- [ ] CVD divergence
- [ ] ATR logic
- [ ] Scoring

## Model 2
- [ ] Funding filter
- [ ] OI expansion
- [ ] ATR compression
- [ ] CVD slope

## Model 3
- [ ] EMA calc
- [ ] RSI/MACD
- [ ] BOS detection
- [ ] OI confirmation

## Infrastructure
- [ ] Data fetcher
- [ ] Redis cache
- [ ] Logging
- [ ] Error handling
- [ ] Unit testing

---

# IMPORTANT

This is a **signal system only**.

Execution must be:
- Manual OR
- With separate risk management layer

---

**Trading involves risk. Use responsibly.**
