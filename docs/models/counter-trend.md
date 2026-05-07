# Model 1: Counter-Trend

**Strategy**: Reversal detection using liquidity sweeps and exhaustion signals

**Job**: `CounterTrendJob` | **Schedule**: Every 15 minutes | **Queue**: `models`

---

## Concept

The Counter-Trend model looks for coins where price has swept a significant liquidity level (support or resistance) and is likely to reverse. The key is that price overshoots a level (creating extreme RSI readings), then closes back inside — signaling a failed breakout.

This is a **contrarian** strategy: we trade against the prevailing trend when exhaustion signals align.

---

## Timeframes

| Role | Timeframe | Purpose |
|------|-----------|---------|
| Entry Confirmation | 15M | RSI extreme, volume spike |
| Setup | 1H | Market structure direction |
| Macro Context | 4H | Overall trend bias |
| Macro Context | 1D | Long-term trend confirmation |

---

## Signal Logic

### Step 1: Liquidity Sweep Detection (Gate Condition)

The sweep is required — if no sweep is detected, the coin is skipped immediately.

```
RSI ≤ 30 on 15M → DOWN sweep detected → BUY signal
RSI ≥ 70 on 15M → UP sweep detected  → SELL signal

Early signals (softer gate):
RSI ≤ 40 AND macro is downtrend → BUY (early counter-trend)
RSI ≥ 60 AND macro is uptrend   → SELL (early counter-trend)
```

Sweep strength is boosted if `volume_ratio ≥ 1.5` (volume confirms the sweep).

### Step 2: Market Structure Shift (MSS)

MSS confirms the reversal is beginning:

```
MSS = true when:
  - entry_trend (15M) != sideways
  - entry_trend == setup_trend (1H)   ← lower TF aligning  
  - entry_trend != macro_trend (4H)   ← going against macro
```

This means: entry and setup are both shifting in a direction opposite to the macro trend — confirming a reversal setup.

### Step 3: Component Scoring

| Component | Score | Logic |
|-----------|-------|-------|
| `sweep` | 0.6–1.0 | 1.0 if volume_ratio ≥ 1.5, else 0.8 (sweep) / 0.6 (early) |
| `mss` | 0.0 or 1.0 | Binary: MSS detected or not |
| `oi` | 0.0 | Placeholder (awaiting Binance OI data) |
| `cvd` | 0.0 | Placeholder (awaiting trade flow data) |
| `funding` | 0.0 | Placeholder (awaiting Binance funding data) |
| `atr` | 0.0–1.0 | 1.0 if volatility ≥ 4%, else 0.5 |

### Step 4: Final Score

```
base_score = (sweep × 0.30) + (mss × 0.25) + (oi × 0.15) + (cvd × 0.15) + (funding × 0.10) + (atr × 0.05)
             scaled to 0–100

regime_adjuster:
  TRENDING_UP  → -15 (risky: counter-trend against strong uptrend)
  TRENDING_DOWN → +10 (safer: reversal in downtrend)
  RANGING       → +5  (neutral, slight boost)
  CHOPPY        → -20 (avoid reversals in chaos)

final_score = clamp(base_score + regime_adjuster, 0, 100)
```

Signal is **accepted** if `final_score ≥ 60`.

---

## Output (ModelSignalDTO)

```json
{
  "model": "counter_trend",
  "coin": "ethereum",
  "action": "BUY",
  "score": 74,
  "primary_timeframe": "15m",
  "component_scores": {
    "sweep": 0.8,
    "mss": 1.0,
    "oi": 0.0,
    "cvd": 0.0,
    "funding": 0.0,
    "atr": 0.5
  },
  "context": {
    "market_regime": "TRENDING_DOWN",
    "btc_direction": "DOWN",
    "entry_trend": "uptrend",
    "setup_trend": "uptrend",
    "macro_trend": "downtrend"
  },
  "reasons": ["oversold_sweep", "market_structure_shift"]
}
```

---

## Top 10 Ranking

Signals are sorted by `score` descending and the top 10 are returned.

---

## Market Regime Impact

| Regime | Adjuster | Interpretation |
|--------|----------|----------------|
| TRENDING_UP | -15 | Going against strong uptrend — highest risk |
| TRENDING_DOWN | +10 | Reversal in downtrend — natural bounce |
| RANGING | +5 | Good environment for reversals |
| CHOPPY | -20 | Whipsaw risk — avoid |

---

## Notification

Telegram alert is sent when:
- `action = BUY or SELL`
- `confidence ≥ 70` (default threshold)
- Signal is not a duplicate

Message prefix: `🔄 COUNTER-TREND MODEL`
