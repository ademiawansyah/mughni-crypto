# Model 2: Pre-Pump

**Strategy**: Short squeeze and accumulation detection

**Job**: `PrePumpJob` | **Schedule**: Every 30 minutes | **Queue**: `models`

---

## Concept

The Pre-Pump model identifies coins in an accumulation phase just before an explosive move. It looks for:

1. **Extreme negative funding** → crowd is heavily short (squeeze fuel)
2. **ATR compression** → volatility contracting before breakout
3. **OI expansion** → open interest increasing during consolidation (smart money entering)
4. **Relative strength vs BTC** → coin outperforming broader market

When all three align, the probability of a sharp upward squeeze is elevated.

---

## Timeframes

| Role | Timeframe | Purpose |
|------|-----------|---------|
| Setup | 1H | Trend direction, RSI, volume |
| Macro Context | 4H | ATR compression, macro bias |

---

## Signal Logic

### Step 1: Action Gate

A BUY or SELL signal is only generated when the following simultaneously pass:

```
atr_compression ≥ 0.7  (ATR is compressed: current ATR ≤ 4% of price)
AND oi ≥ 0.6           (Volume ratio ≥ 1.2 on setup timeframe)
AND rs ≥ 0.6           (Coin is outperforming BTC)
```

If all three pass:
- `setup_trend = uptrend` → **BUY**
- `setup_trend = downtrend` → **SELL**
- Otherwise → **HOLD** (skipped)

### Step 2: Component Scoring

| Component | Score | Logic |
|-----------|-------|-------|
| `funding` | 0.0 | Placeholder (awaiting Binance funding data) |
| `atr_compression` | 0.0–1.0 | 1.0 if volatility ≤ 2%, 0.75 if ≤ 4%, else 0.2 |
| `oi` | 0.0–1.0 | 1.0 if volume_ratio ≥ 1.5, 0.6 if ≥ 1.2, else 0.0 |
| `rs` | 0.4–1.0 | Relative strength vs BTC (see table below) |
| `cvd` | 0.0 | Placeholder (awaiting trade flow data) |

**Relative Strength scoring:**
```
Coin uptrend + BTC NOT uptrend    → rs = 1.0 (coin outperforming)
Coin uptrend + coin RSI > BTC RSI → rs = 0.75
Coin downtrend + BTC NOT downtrend → rs = 0.7 (coin underperforming = short squeeze)
Otherwise → rs = 0.4 (neutral)
```

### Step 3: Final Score

```
base_score = (funding × 0.35) + (atr_compression × 0.25) + (oi × 0.20) + (rs × 0.10) + (cvd × 0.10)
             scaled to 0–100

regime_adjuster:
  TRENDING_UP   → +20 (pre-pump in uptrend = breakout highly likely)
  TRENDING_DOWN → -25 (pre-pump in downtrend = very risky)
  RANGING       → +10 (good accumulation environment)
  CHOPPY        → -15 (signals less reliable)

final_score = clamp(base_score + regime_adjuster, 0, 100)
```

Signal is **accepted** if `final_score ≥ 65`.

---

## Output (ModelSignalDTO)

```json
{
  "model": "pre_pump",
  "coin": "solana",
  "action": "BUY",
  "score": 71,
  "primary_timeframe": "1h",
  "component_scores": {
    "funding": 0.0,
    "atr_compression": 0.75,
    "oi": 1.0,
    "rs": 1.0,
    "cvd": 0.0
  },
  "context": {
    "market_regime": "TRENDING_UP",
    "btc_direction": "UP",
    "setup_trend": "uptrend",
    "macro_trend": "uptrend",
    "bitcoin_trend": "sideways"
  },
  "reasons": ["atr_compression_detected", "volume_expansion_proxy", "relative_strength_vs_btc"]
}
```

---

## Top 10 Ranking

Signals are sorted by `score` descending and the top 10 are returned.

---

## Market Regime Impact

| Regime | Adjuster | Interpretation |
|--------|----------|----------------|
| TRENDING_UP | +20 | Pre-pump in uptrend = strong breakout catalyst |
| TRENDING_DOWN | -25 | Squeeze setups fail in strong downtrends |
| RANGING | +10 | Ranging markets are ideal for accumulation detection |
| CHOPPY | -15 | Low confidence in chaotic conditions |

---

## Notification

Telegram alert is sent when:
- `action = BUY or SELL`
- `confidence ≥ 75` (highest threshold — pre-pump requires high conviction)
- Signal is not a duplicate

Message prefix: `💥 PRE-PUMP MODEL`
