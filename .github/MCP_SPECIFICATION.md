# MCP Strategy Specification

Version: 1.0
Source of truth: .github/system-spec.instructions.md

## Cross-Reference Matrix
| This document section | Canonical spec section |
|---|---|
| Model 1: Counter Trend | 5, 10.2, 10.3, 10.4 |
| Model 2: Pre-Pump | 6 |
| Model 3: Trend Momentum | 7 |
| Data Sources | 8 |
| Coin Universe Constraints | 10.5 |
| Output Requirements | 4.3, 11 |

## Model 1: Counter Trend
Reversal detection using liquidity sweep and exhaustion confirmation.

### Timeframes
| Component | Timeframe |
|---|---|
| Structure screening | 1H or 4H |
| Entry confirmation | 15M |
| Macro filter | 1D optional |

### Core Logic
Primary trigger sequence:
- Liquidity sweep
- MSS body-close confirmation
- Retrace into FVG or Order Block area

Derivatives confirmation:
- OI declines or flattens after sweep
- Funding moves from extreme negative toward neutral
- CVD bullish divergence while price prints lower low
- ATR spike then normalization

### Scoring
| Component | Weight |
|---|---|
| Liquidity sweep | 30% |
| MSS body break | 25% |
| OI decline | 15% |
| CVD divergence | 15% |
| Funding normalization | 10% |
| Volatility stabilization | 5% |

## Model 2: Pre-Pump
Short squeeze preparation detection.

### Timeframes
| Component | Timeframe |
|---|---|
| Funding screening | Real time or 8H |
| OI expansion | 1H |
| ATR compression | 4H |
| Momentum context | 4H or 1D |
| Entry timing | 1H or 15M |

### Core Logic
Primary filters:
- Funding below -0.05% target
- Funding negative for at least 3 periods

Confirmation:
- Relative strength vs BTC
- Relative volume above baseline
- OI expansion during consolidation
- ATR compression vs historical baseline
- Positive CVD slope

### Scoring
| Component | Weight |
|---|---|
| Extreme negative funding | 35% |
| ATR compression | 25% |
| OI expansion in sideways phase | 20% |
| Relative strength vs BTC | 10% |
| Positive CVD buying delta | 10% |

## Model 3: Trend Momentum
Breakout continuation confirmation.

### Timeframes
| Component | Timeframe |
|---|---|
| EMA trend filter | 1D or 4H |
| MACD and RSI synergy | 4H |
| BOS | 4H or 1H |
| OI and CVD confirmation | 1H |
| Entry timing | 1H or 15M |

### Core Logic
Trend structure:
- Close above EMA50 above EMA200 for bullish continuation
- Widening EMA spread
- Positive EMA slope

Momentum:
- RSI in healthy zone, generally 50-65
- MACD line above signal and preferably above zero
- Positive and increasing MACD histogram

Participation confirmation:
- OI rises with price
- CVD slope positive
- Continued BOS behavior

### Scoring
| Component | Weight |
|---|---|
| EMA trend filter | 25% |
| MACD strength | 20% |
| RSI momentum | 15% |
| OI expansion with price | 20% |
| BOS continuity | 10% |
| CVD consistency | 10% |

## Data Sources
Public endpoints only.

| Data | Provider | Endpoint |
|---|---|---|
| OHLCV | Binance | /api/v3/klines |
| OHLCV | CoinGecko | /coins/{id}/ohlc |
| Open Interest | Binance Futures | /fapi/v1/openInterest |
| OI history | Coinalyze | /futures/open-interest-history |
| Funding rate | Binance Futures | /fapi/v1/fundingRate |
| Funding rate | Bybit | /v5/market/funding/history |
| Agg trades | Binance Futures | /fapi/v1/aggTrades |
| Market cap and volume | CoinGecko | /coins/markets |
| 24H price change | Binance | /api/v3/ticker/24hr |

## Coin Universe Constraints
- Prefer broad Binance futures universe coverage.
- Min 24H volume above 5 million USD.
- Min OI above 1 million USD.
- Exclude stablecoin-like pairs.

## Output Requirements
- Top 10 per model.
- Include total_score and component-level score details.
- Keep output model-labeled and non-merged.