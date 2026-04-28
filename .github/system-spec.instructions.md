# CRYPTO TRADING SYSTEM
## Technical Specification and Developer Blueprint

Version: 1.0
Year: 2025
Audience: Developers and coding agents

## 1. Purpose
This document defines a crypto signal system with three independent models.
The system generates ranked trade signals. It does not execute trades.

## 2. Non-Negotiable Rules
- Keep three models fully independent.
- Never merge model outputs into one shared signal list.
- Use public market data only.
- Run each model as a separate service or process.
- Treat derivatives data as confirmation, not the primary trigger.
- Output Top 10 coins per model, with component-level scoring details.

## 3. System Summary
| Property | Detail |
|---|---|
| Models | 3 independent models |
| Data Sources | CoinGecko, Binance public endpoints, Coinalyze, similar public APIs |
| Implementation Language | Flexible |
| Deployment Target | Linux VPS |
| Output | Top 10 coins per model with total and component scores |

## 4. High-Level Architecture

### 4.1 Services
| Service | Schedule | Output |
|---|---|---|
| service-data-fetcher | Every 5 minutes | Shared OHLCV and derivatives cache |
| service-counter-trend | Every 15 minutes | Top 10 Counter Trend signals |
| service-pre-pump | Every 30 minutes | Top 10 Pre-Pump signals |
| service-trend-momentum | Every 1 hour | Top 10 Trend Momentum signals |
| service-api-gateway | On demand | REST response for dashboards and bots |

### 4.2 Required Execution Pattern
- Use one centralized data-fetcher.
- Store and reuse data via Redis and or database cache.
- Model services should consume cached data.
- Do not let each model call external APIs directly.

### 4.3 Output Contract
Each model should return data with this structure:

```json
{
  "model": "counter_trend",
  "timestamp": "2025-01-01T00:00:00Z",
  "top_coins": [
    {
      "symbol": "BTCUSDT",
      "total_score": 87.5,
      "components": {
        "liquidity_sweep": true,
        "mss_confirmed": true,
        "oi_declining": 0.85,
        "cvd_divergence": 0.90,
        "funding_normalizing": 0.75
      }
    }
  ]
}
```

## 5. MODEL 1: COUNTER TREND
Strategy: Mia style liquidity sweep plus exhaustion reversal.

### 5.1 Goal
Detect reversals after liquidity has been swept and trend pressure starts to fail.

### 5.2 Timeframes
| Component | Timeframe |
|---|---|
| Structure screening | 1H or 4H |
| Entry confirmation | 15M |
| Macro filter | 1D optional |

### 5.3 Trigger Logic
Primary trigger uses price action and must appear in sequence:
1. Liquidity sweep.
2. Market Structure Shift with body close confirmation.
3. Retrace into FVG or Order Block area.

### 5.4 Derivatives Confirmation
Apply after trigger appears:
- OI declines or flattens after sweep.
- Funding moves from extreme negative toward neutral.
- CVD diverges bullish while price makes a new low.
- ATR spikes during sweep and then normalizes.

### 5.5 Scoring Weights
| Component | Weight |
|---|---|
| Liquidity sweep confirmed | 30% |
| MSS body break confirmed | 25% |
| OI decline after sweep | 15% |
| CVD divergence | 15% |
| Funding normalization | 10% |
| Volatility stabilization | 5% |

Notes:
- Keep scoring normalized to 0-100.
- Include component scores in output for transparency.

## 6. MODEL 2: PRE-PUMP METHOD
Strategy: pressure cooker setup for short squeeze expansion.

### 6.1 Goal
Find coins under persistent short pressure and volatility compression before expansion.

### 6.2 Timeframes
| Component | Timeframe |
|---|---|
| Funding screening | Real time or 8H intervals |
| OI expansion check | 1H |
| ATR compression check | 4H |
| Momentum runner context | 4H or 1D |
| Entry timing | 1H or 15M |

### 6.3 Trigger and Filter Logic
Primary filters:
- Funding is extremely negative, target below -0.05% per 8H.
- Funding remains negative for at least three consecutive periods.

Momentum and confirmation:
- Relative strength vs BTC remains strong when BTC weakens.
- Volume is above normal baseline.
- OI expands while price remains in consolidation.
- Price sits near POI or Order Block zone.
- ATR is compressed versus historical baseline.
- CVD slope is positive.

### 6.4 Scoring Weights
| Component | Weight |
|---|---|
| Extreme negative funding | 35% |
| ATR compression | 25% |
| OI expansion in sideways phase | 20% |
| Relative strength vs BTC | 10% |
| Positive CVD buying delta | 10% |

## 7. MODEL 3: TREND MOMENTUM
Strategy: follow confirmed breakout continuation.

### 7.1 Goal
Validate that a breakout has enough momentum and participation to continue.

### 7.2 Timeframes
| Component | Timeframe |
|---|---|
| EMA trend filter | 1D or 4H |
| MACD and RSI synergy | 4H |
| Break of Structure | 4H or 1H |
| OI and CVD confirmation | 1H |
| Entry timing | 1H or 15M |

### 7.3 Trigger and Filter Logic
Trend structure:
- Close above EMA50 above EMA200 for bullish case.
- EMA spread should widen.
- EMA slope should remain positive for bullish continuation.

Momentum:
- RSI in healthy momentum zone, typically 50-65.
- MACD line above signal and preferably above zero.
- MACD histogram positive and increasing.

Participation confirmation:
- Price rise should be accompanied by OI rise.
- CVD slope should remain positive.
- BOS should continue with repeated higher highs.

### 7.4 Scoring Weights
| Component | Weight |
|---|---|
| EMA trend filter | 25% |
| MACD strength | 20% |
| RSI momentum zone | 15% |
| OI expansion with price | 20% |
| BOS continuity | 10% |
| CVD consistency | 10% |

## 8. Data Sources and Endpoints
Use public APIs only.

| Data | Provider | Endpoint |
|---|---|---|
| OHLCV | Binance | /api/v3/klines |
| OHLCV | CoinGecko | /coins/{id}/ohlc |
| Open Interest | Binance Futures | /fapi/v1/openInterest |
| OI history | Coinalyze | /futures/open-interest-history |
| Funding rate | Binance Futures | /fapi/v1/fundingRate |
| Funding rate | Bybit | /v5/market/funding/history |
| Agg trades for CVD | Binance Futures | /fapi/v1/aggTrades |
| Market cap and volume | CoinGecko | /coins/markets |
| 24H price change | Binance | /api/v3/ticker/24hr |

## 9. Caching and Rate Limit Policy
- Centralize API requests in data-fetcher.
- Cache market data in Redis and or database storage.
- Keep at least 200 OHLCV candles per symbol and timeframe.
- Batch CoinGecko requests where possible.
- Apply retry and timeout handling for all external requests.

## 10. Implementation Guidance and Edge Cases

### 10.1 CVD Calculation
- Pull aggTrades data.
- Treat isBuyerMaker false as buy volume.
- Treat isBuyerMaker true as sell volume.
- CVD is cumulative buy volume minus sell volume.
- Use linear regression over recent CVD points for slope checks.
- Consider daily reset at 00:00 UTC for consistency.

### 10.2 Liquidity Sweep Detection
- Define old highs and lows from swings at least 10 candles back.
- Detect equal highs and lows within tolerance bands.
- Sweep is valid when wick breaks level but close returns inside range.
- Prefer 1H or 4H for robust sweep detection.

### 10.3 MSS Detection
- Build swing highs and lows from OHLCV.
- Bullish MSS requires body close above prior swing high during a downtrend.
- Bearish MSS requires body close below prior swing low during an uptrend.
- Do not treat wick-only breaks as MSS.

### 10.4 Order Block Detection
- Bearish OB for bullish reversal is last bearish candle before strong up impulse.
- Bullish OB for bearish reversal is last bullish candle before strong down impulse.
- OB zone uses that candle high and low boundaries.
- Consider price at OB when close is inside zone with small tolerance.

### 10.5 Coin Universe
- Prefer full Binance futures universe for broad coverage.
- Minimum volume filter: above 5 million USD per 24H.
- Minimum OI filter: above 1 million USD.
- Exclude stablecoin style pairs.

## 11. Agent Change Protocol
When future agents change this system, they must follow this order:
1. Verify whether the change affects one model or shared infrastructure.
2. Keep model logic isolated unless change is explicitly cross-model.
3. Preserve output schema and component scoring transparency.
4. Avoid adding direct API calls to model services.
5. Update cache and rate-limit handling when adding data fields.
6. Add or update tests for scoring, thresholds, and signal gates.
7. Document any threshold changes and expected behavioral impact.

## 12. Developer Checklist

### Counter Trend
- [ ] Separate service boundary maintained
- [ ] Sweep detection validated
- [ ] MSS body-close validation implemented
- [ ] FVG and OB logic implemented
- [ ] OI change and funding normalization tracked
- [ ] CVD divergence and ATR stabilization checked
- [ ] Weighted scoring and output formatting validated

### Pre-Pump
- [ ] Separate service boundary maintained
- [ ] Extreme and persistent negative funding screening implemented
- [ ] Relative strength vs BTC implemented
- [ ] OI expansion and ATR compression checks implemented
- [ ] CVD slope calculation implemented
- [ ] Weighted scoring and output formatting validated

### Trend Momentum
- [ ] Separate service boundary maintained
- [ ] EMA50 and EMA200 calculations implemented
- [ ] RSI and MACD synergy checks implemented
- [ ] OI and CVD participation checks implemented
- [ ] BOS continuity logic implemented
- [ ] Weighted scoring and output formatting validated

### Infrastructure
- [ ] Centralized data-fetcher in place
- [ ] Redis or equivalent cache in place
- [ ] API rate-limit and retry policy implemented
- [ ] Historical storage available for indicators and derivatives
- [ ] Error handling and logging implemented
- [ ] Unit and integration tests maintained

## 13. Final Scope Reminder
This is a signal system only.
Trade execution must remain manual or be handled by a separate risk-managed execution layer.