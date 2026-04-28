# Crypto Trading Strategy Specification

**Version**: 1.0  
**Source**: [crypto_trading_strategy.md](../crypto_trading_strategy.md)

## MODEL 1: COUNTER-TREND — Reversal Detection

### Timeframes
| Component | Timeframe |
|-----------|-----------|
| Structure | 1H / 4H |
| Entry Confirmation | 15M |
| Macro Context | 1D |

### Scoring (Total: 100)
| Component | Weight |
|-----------|--------|
| Liquidity Sweep | 30% |
| Market Structure Shift (MSS) | 25% |
| Open Interest (OI) | 15% |
| CVD Divergence | 15% |
| Funding Rate | 10% |
| ATR Volatility | 5% |

### Signal Logic

**A. Price Action (Primary)**
- Liquidity Sweep: Wick breaks level, close returns inside
- MSS: Body close breaks structure (HH or LL)
- FVG/Order Block: Last opposite candle before impulse

**B. Derivatives (Confirmation)**
- OI: Decreasing after sweep
- Funding: Moving negative → neutral
- CVD: Bullish divergence
- ATR: Spike → stabilization

### Action
- UP sweep (wick up, close below) → **BUY**
- DOWN sweep (wick down, close above) → **SELL**
- Else → HOLD

---

## MODEL 2: PRE-PUMP — Short Squeeze Detection

### Timeframes
| Component | Timeframe |
|-----------|-----------|
| Funding | Real-time |
| OI | 1H |
| ATR | 4H |
| Entry | 15M–1H |

### Scoring (Total: 100)
| Component | Weight |
|-----------|--------|
| Funding Extreme | 35% |
| ATR Compression | 25% |
| OI Expansion | 20% |
| Relative Strength vs BTC | 10% |
| CVD Momentum | 10% |

### Signal Logic

**A. Funding (Primary)**
- Extreme negative: < -0.05%
- Consistent: Last 3+ candles negative
- Momentum: Becoming MORE negative

**B. Compression + Expansion**
- OI: Increasing during consolidation
- ATR: < 80% of baseline (compression)
- Price: At order block/POI
- CVD: Positive slope

**C. Momentum**
- RS vs BTC: Outperforming
- Volume: > 150% of 20MA

### Action
- All aligned → **BUY** (squeeze setup)
- Funding easing → **SELL** or HOLD

---

## MODEL 3: MOMENTUM — Trend Continuation

### Timeframes
| Component | Timeframe |
|-----------|-----------|
| EMA Filter | 4H / 1D |
| MACD/RSI | 4H |
| BOS | 1H–4H |
| Entry | 15M |

### Scoring (Total: 100)
| Component | Weight |
|-----------|--------|
| EMA Structure | 25% |
| MACD Momentum | 20% |
| RSI Momentum | 15% |
| Open Interest | 20% |
| Break of Structure | 10% |
| CVD Momentum | 10% |

### Signal Logic

**A. Trend Structure (Gate)**
- Price > EMA50 > EMA200 (bullish) OR Price < EMA50 < EMA200 (bearish)
- EMA slope positive/negative
- EMA spread widening
- Min 3 HH/LL confirmed

**B. Momentum**
- RSI: 50–65 (uptrend) or 35–50 (downtrend)
- MACD: Histogram > signal AND > zero
- MACD slope: Increasing

**C. Confirmation**
- Higher highs/lows (1H/4H)
- OI: Rising with price
- CVD: Positive slope
- Volume: > 20MA

### Action
- Bullish EMA + MACD bullish + RSI zone → **BUY**
- Bearish EMA + MACD bearish + RSI zone → **SELL**
- Else → HOLD

---

## Data Sources

| Data | Provider | Endpoint |
|------|----------|----------|
| OHLCV | Binance | `/fapi/v1/klines` |
| OI | Binance | `/fapi/v1/openInterest` |
| Funding | Binance | `/fapi/v1/fundingRate` |
| Trades | Binance | `/fapi/v1/aggTrades` |
| Market Data | CoinGecko | `/coins/markets` |

---

## Coin Universe Filters

- Binance futures pairs only
- Min market cap: $100M
- Min volume (24h): $5M
- Exclude stablecoins
- Result: ~50-100 candidates