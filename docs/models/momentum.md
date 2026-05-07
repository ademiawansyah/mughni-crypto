# Model 3: Momentum

**Strategy**: Trend continuation confirmation

**Job**: `MomentumJob` | **Schedule**: Every 1 hour | **Queue**: `models`

---

## Concept

The Momentum model rides established trends. It requires:

1. **EMA structure alignment** across multiple timeframes (4H + 1D) — confirms the trend is mature
2. **RSI in momentum zone** — not overbought/oversold, showing sustainable momentum
3. **Break of structure (BOS)** — all four timeframes aligned in the same direction

Unlike Counter-Trend, this model **goes with** the prevailing market direction.

---

## Timeframes

| Role | Timeframe | Purpose |
|------|-----------|---------|
| Entry | 15M | Short-term trend confirmation |
| Setup | 1H | RSI, volume ratio |
| Macro | 4H | EMA alignment, RSI zone |
| Context | 1D | Long-term EMA direction |

---

## Signal Logic

### Step 1: Break of Structure Detection (Gate Condition)

```
BOS = true when ALL four timeframes are aligned:
  - 15M trend != sideways
  - 15M trend == 1H trend == 4H trend == 1D trend
```

If BOS is not detected, the signal is skipped immediately.

### Step 2: Action Determination

```
IF BOS detected:
  Bullish alignment (4H uptrend) + RSI 50–65 → BUY
  Bearish alignment (4H downtrend) + RSI 35–50 → SELL

Otherwise → HOLD
```

**Why RSI 50–65?** This range confirms the trend has momentum but is not yet overbought — it's the "sweet spot" for continuation trades.

### Step 3: Component Scoring

| Component | Score | Logic |
|-----------|-------|-------|
| `ema` | 0.0 or 1.0 | 1.0 if 4H and 1D trends are aligned (both bull or both bear) |
| `macd` | 0.0 | Placeholder (MACD histogram not yet in MarketIndicator) |
| `rsi` | 0.0–1.0 | 1.0 if RSI in ideal zone, 0.6 if in wider zone |
| `oi` | 0.0–1.0 | Volume ratio proxy: 1.0 if ≥ 1.5, 0.6 if ≥ 1.2, else 0.0 |
| `bos` | 0.0 or 1.0 | Binary: BOS detected or not |
| `cvd` | 0.0 | Placeholder (awaiting trade flow data) |

**RSI zone scoring:**
```
Bullish trend:
  RSI 50–65 → score = 1.0 (ideal momentum zone)
  RSI 45–70 → score = 0.6 (acceptable)
  Otherwise → score = 0.0

Bearish trend:
  RSI 35–50 → score = 1.0 (ideal momentum zone)
  RSI 30–55 → score = 0.6 (acceptable)
  Otherwise → score = 0.0
```

### Step 4: Final Score

```
base_score = (ema × 0.25) + (macd × 0.20) + (rsi × 0.15) + (oi × 0.20) + (bos × 0.10) + (cvd × 0.10)
             scaled to 0–100

regime_adjuster:
  TRENDING_UP   → +30 (momentum in strong uptrend = best case)
  TRENDING_DOWN → +30 (momentum in strong downtrend = equally strong)
  RANGING       → -10 (momentum fades in ranging markets)
  CHOPPY        → -20 (whipsaw risk)

final_score = clamp(base_score + regime_adjuster, 0, 100)
```

Signal is **accepted** if `final_score ≥ 55` (lowest threshold — trend signals are most reliable).

---

## Output (ModelSignalDTO)

```json
{
  "model": "momentum",
  "coin": "bitcoin",
  "action": "BUY",
  "score": 85,
  "primary_timeframe": "4h",
  "component_scores": {
    "ema": 1.0,
    "macd": 0.0,
    "rsi": 1.0,
    "oi": 0.6,
    "bos": 1.0,
    "cvd": 0.0
  },
  "context": {
    "market_regime": "TRENDING_UP",
    "btc_direction": "UP",
    "entry_trend": "uptrend",
    "setup_trend": "uptrend",
    "macro_trend": "uptrend",
    "context_trend": "uptrend"
  },
  "reasons": ["break_of_structure", "volume_expansion_proxy"]
}
```

---

## Top 10 Ranking

Signals are sorted by `score` descending and the top 10 are returned.

---

## Market Regime Impact

| Regime | Adjuster | Interpretation |
|--------|----------|----------------|
| TRENDING_UP | +30 | Momentum aligned with global regime = highest confidence |
| TRENDING_DOWN | +30 | Bearish momentum aligned with downtrend = equally valid |
| RANGING | -10 | False breakouts common in ranging markets |
| CHOPPY | -20 | Momentum signals fail in chaotic conditions |

---

## Notification

Telegram alert is sent when:
- `action = BUY or SELL`
- `confidence ≥ 65` (lowest threshold — trend signals have inherently higher hit rate)
- Signal is not a duplicate

Message prefix: `🚀 MOMENTUM MODEL`
