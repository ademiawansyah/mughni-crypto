# Execution Flow

## Phase 1: Market Context (Every 5 Minutes)

```
Schedule::job(new MarketRegimeJob)->everyFiveMinutes()
                    │
                    ▼
          MarketRegimeService::detectRegime($executionId)
                    │
                    ├─ Fetch BTC MarketIndicator for 1h, 4h, 1d
                    ├─ determineBtcDirection() → UP | DOWN | SIDEWAYS
                    ├─ detectStructure()       → higher_highs, higher_lows, ema_slope
                    ├─ classifyVolatility()    → LOW | MEDIUM | HIGH
                    ├─ classifyRegime()        → TRENDING_UP | TRENDING_DOWN | RANGING | CHOPPY
                    ├─ assessMarketStrength()  → WEAK | MODERATE | STRONG
                    └─ assignRiskLevel()       → LOW | MEDIUM | HIGH
                                │
                    Cache::put('market_context:latest', $regime, 300)
```

**Output (Redis `market_context:latest`):**
```json
{
  "market_regime": "TRENDING_UP",
  "btc_direction": "UP",
  "volatility": "MEDIUM",
  "market_strength": "STRONG",
  "risk_level": "LOW",
  "btc_structure": {
    "higher_highs": true,
    "higher_lows": true,
    "ema_slope_positive": true
  }
}
```

---

## Phase 2: Coin Universe Refresh (Daily 00:00 UTC)

```
Schedule::job(new UpdateCoinUniverseJob)->dailyAt('00:00')
                    │
                    ▼
          CoinUniverseService::updateUniverse($executionId)
                    │
                    ├─ CoinGeckoService::fetchCoinMarkets() (up to 4 pages × 250)
                    ├─ Filter: market_cap > $100M
                    ├─ Filter: volume_24h > $5M
                    ├─ Filter: exclude stablecoins (ID + symbol list)
                    ├─ Sort by market_cap DESC
                    └─ Take top 100
                                │
                    Cache::put('coin_universe:main', $coins, 86400)
```

**Output (Redis `coin_universe:main`):**
```json
[
  {"coin": "bitcoin", "symbol": "btc", "market_cap": 1500000000000, "volume_24h": 50000000000},
  {"coin": "ethereum", "symbol": "eth", "market_cap": 250000000000, "volume_24h": 15000000000},
  ...
]
```

---

## Phase 3: Model Execution (Parallel, Independent)

Each of the three model jobs follows the same pattern:

```
┌─── CounterTrendJob (every 15 min)
├─── PrePumpJob      (every 30 min)   ← All run in PARALLEL on 'models' queue
└─── MomentumJob     (every 1h)
```

### Per-Job Execution Flow

```
1. Generate execution_id (UUID)
2. Check: GeneralConfig::isCronEnabled() + isModelEnabled($model)
3. marketRegimeService->getLatestRegime()   ← Read from Redis
4. modelService->evaluateUniverse($executionId)
   │
   ├─ resolveCandidateCoins()
   │   └─ coinUniverseService->getCachedUniverse() ← Read from Redis
   │       (fallback: GeneralConfig::getCoins())
   │
   ├─ For each coin: evaluateCoin($coin)
   │   ├─ resolveIndicators($coin)
   │   │   └─ MarketIndicator::query()->where(coin, timeframe)->latest()
   │   ├─ Apply model-specific signal logic
   │   ├─ calculateComponentScores()
   │   ├─ calculateWeightedScore() × 100
   │   ├─ Apply market regime confidence adjuster
   │   └─ Return ModelSignalDTO or null
   │
   ├─ Filter nulls (coins that didn't pass)
   └─ rankTopCoins() → top 10 by score
5. For each signal in top 10:
   a. PerModelAiLayer::interpret($signal, $marketRegime)
   b. ModelSignalPersistenceService::persist(...)  → ai_decisions table
   c. If BUY|SELL + confidence ≥ threshold:
      PerModelNotificationService::notify(...)     → Telegram
```

---

## Model Scoring Formula

```
base_score = Σ (component_score × component_weight) × 100
final_score = clamp(base_score + regime_adjuster, 0, 100)
```

If `final_score < min_score` → signal is discarded (returns `null`).

### Component Weights

**Counter-Trend:**
| Component | Weight | Description |
|-----------|--------|-------------|
| sweep | 30% | Liquidity sweep detection (RSI extremes) |
| mss | 25% | Market structure shift (trend crossover) |
| oi | 15% | Open Interest (placeholder, 0 until Binance data) |
| cvd | 15% | CVD divergence (placeholder) |
| funding | 10% | Funding rate extreme (placeholder) |
| atr | 5% | ATR volatility spike |

**Pre-Pump:**
| Component | Weight | Description |
|-----------|--------|-------------|
| funding | 35% | Funding rate extreme (placeholder) |
| atr_compression | 25% | Volatility compression (ATR ratio) |
| oi | 20% | OI expansion (volume ratio proxy) |
| rs | 10% | Relative strength vs BTC |
| cvd | 10% | CVD momentum (placeholder) |

**Momentum:**
| Component | Weight | Description |
|-----------|--------|-------------|
| ema | 25% | EMA alignment across 4H + 1D |
| macd | 20% | MACD histogram (placeholder) |
| rsi | 15% | RSI zone 50–65 (bull) / 35–50 (bear) |
| oi | 20% | OI expansion (volume ratio proxy) |
| bos | 10% | Break of structure (all TFs aligned) |
| cvd | 10% | CVD momentum (placeholder) |

---

## AI Layer Flow

```
PerModelAiLayer::interpret($signal, $marketRegime, $executionId)
        │
        ├─ Check: config("models.{model}.ai_enabled")
        │
        ├─ [AI Disabled]
        │   └─ adjustConfidence(base, regime, action, model)
        │       └─ Apply regime multiplier from config
        │
        └─ [AI Enabled]
            ├─ buildModelSpecificPrompt($signal, $marketRegime)
            ├─ LmStudioClient::chat($messages)
            ├─ AiResponseParser::parse($rawResponse)
            │   ├─ Invalid JSON / missing fields → reject → fallback
            │   └─ Valid: {action, confidence, type, reason}
            ├─ Check AI agreement: ai_action === model_action
            └─ adjustConfidence(ai_confidence, regime, ai_action, model, agreement)

    Returns: {action, confidence, reasoning, agreement, ai_enabled, ai_response}
```

**AI Fallback behavior:**
- AI disabled → use model signal + regime adjustment
- AI returns null/invalid → use model signal + regime adjustment  
- AI exception → log error + fallback (never crash)

---

## Notification Trigger Logic

```
if (
    action ∈ ['BUY', 'SELL']
    AND confidence >= config('notifications.{model}.confidence_threshold')
    AND signal was persisted (not duplicate)
) {
    PerModelNotificationService::notify(...)
}
```

**Thresholds by model:**
| Model | Default Threshold |
|-------|-----------------|
| Counter-Trend | 70% |
| Pre-Pump | 75% |
| Momentum | 65% |

---

## Telegram Message Format

```
🔄 COUNTER-TREND MODEL — 🟢 BUY Signal

📊 ETHUSDT | 15m

[████████░░] 80%

Setup: Liquidity sweep (80%) + MSS + OI divergence

Market: TRENDING_UP (Risk: LOW)
AI Refinement: ✅ Agreed

Execution ID: a1b2c3d4
```

---

## Duplicate Protection

Before persisting, `ModelSignalPersistenceService` checks:

```sql
SELECT 1 FROM ai_decisions
WHERE model = ? AND coin = ? AND timestamp = NOW()
```

If a duplicate is found:
- Skip persistence
- Skip notification (notification only fires after successful persist)

---

## Error Handling Summary

| Scenario | Behavior |
|----------|----------|
| BTC indicators missing | Default regime: RANGING, MEDIUM, SIDEWAYS |
| Coin universe empty | Fall back to `GeneralConfig::getCoins()` |
| Indicator missing for coin | Skip coin (returns null signal) |
| AI call fails | Log error + fall back to model signal |
| AI invalid JSON | Log warning + fall back to model signal |
| Notification fails | Log error + continue (non-fatal) |
| Job exception | Re-throw → queue retries (max 2 attempts) |
