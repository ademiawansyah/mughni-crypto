# Services Reference

Complete list of all service classes, their responsibilities, and key methods.

---

## Market Services (`App\Services\Market\`)

### `MarketRegimeService`
**Purpose**: Detect global market regime from BTC indicators across 1H, 4H, 1D timeframes.

| Method | Description |
|--------|-------------|
| `detectRegime(executionId)` | Fetch BTC data, analyze, cache to Redis, return regime array |
| `getLatestRegime()` | Read cached regime from Redis (fallback: default RANGING) |

**Redis key**: `market_context:latest` (TTL: 300s)

---

### `CoinUniverseService`
**Purpose**: Maintain a filtered list of eligible coins for trading signal evaluation.

| Method | Description |
|--------|-------------|
| `updateUniverse(executionId)` | Fetch from CoinGecko, apply filters, cache to Redis |
| `getCachedUniverse()` | Read cached coin list from Redis |

**Filters**: market_cap > $100M, volume_24h > $5M, no stablecoins, max 100 coins  
**Redis key**: `coin_universe:main` (TTL: 86400s)

---

### `ModelSignalPersistenceService`
**Purpose**: Persist per-model signals to the `ai_decisions` table with duplicate protection.

| Method | Description |
|--------|-------------|
| `persist(signal, marketRegime, aiDecision, executionId)` | Check for duplicate, persist record, return AiDecision or null |

**Duplicate key**: `model + coin + timestamp(now)`

---

### `Models/AbstractMarketModelService`
**Purpose**: Base class for all three trading model services. Provides shared infrastructure.

| Method | Description |
|--------|-------------|
| `evaluateUniverse(executionId)` | Evaluate all candidate coins, rank, cache results |
| `resolveCandidateCoins()` | Get coin list from Redis universe (fallback: GeneralConfig) |
| `resolveIndicators(coin)` | Fetch latest MarketIndicator for each required timeframe |
| `fetchLatestIndicator(coin, tf)` | Query DB for single indicator record |
| `calculateWeightedScore(components, weights)` | Apply weights to component scores → 0–100 |
| `resolveMarketRegime()` | Read market context from Redis |
| `cacheSignals(signals, executionId)` | Cache top signals to Redis for dashboard use |

**Redis key (per model)**: `trading_models:{model}:latest` (TTL: 1h)

---

### `Models/CounterTrendModelService`
**Purpose**: Evaluate coins for reversal setups using liquidity sweep detection.

| Method | Description |
|--------|-------------|
| `evaluateCoin(coin, indicators?)` | Main evaluation: sweep detection → scoring → signal |
| `detectLiquiditySweep(indicators)` | RSI extreme detection → BUY/SELL direction + strength |
| `detectMarketStructureShift(indicators)` | Check if entry/setup align against macro trend |
| `calculateCVDDivergence(indicators)` | CVD divergence check (placeholder: always false) |
| `calculateComponentScores(indicators)` | Return all 6 component scores |
| `rankTopCoins(signals)` | Sort by score DESC, take top 10 |

---

### `Models/PrePumpModelService`
**Purpose**: Evaluate coins for squeeze/accumulation setups.

| Method | Description |
|--------|-------------|
| `evaluateCoin(coin, indicators?)` | Main evaluation: gate check → scoring → signal |
| `calculateComponentScores(indicators, btcIndicator?)` | Return all 5 component scores |
| `determineAction(componentScores, indicators, btcIndicator?)` | Gate: all three conditions → BUY/SELL/HOLD |
| `rankTopCoins(signals)` | Sort by score DESC, take top 10 |

---

### `Models/MomentumModelService`
**Purpose**: Evaluate coins for trend-continuation momentum setups.

| Method | Description |
|--------|-------------|
| `evaluateCoin(coin, indicators?)` | Main evaluation: BOS gate → scoring → signal |
| `calculateComponentScores(indicators)` | Return all 6 component scores |
| `detectBreakOfStructure(indicators)` | True when all 4 TFs aligned in same direction |
| `determineAction(indicators)` | BOS + RSI zone → BUY/SELL/HOLD |
| `rankTopCoins(signals)` | Sort by score DESC, take top 10 |

---

## AI Services (`App\Services\AI\`)

### `PerModelAiLayer`
**Purpose**: Optional AI refinement layer for trading model signals.

| Method | Description |
|--------|-------------|
| `interpret(signal, marketRegime, executionId)` | Build prompt → call AI → parse → adjust confidence |
| `buildModelSpecificPrompt(signal, regime)` | Build context-aware prompt per model type |
| `adjustConfidence(base, regime, action, model, agreement?)` | Apply regime multiplier and agreement factor |

**Returns**: `{action, confidence, reasoning, agreement, ai_enabled, ai_response}`

---

### `AiAdvisorService` _(Legacy pipeline)_
**Purpose**: Core AI orchestration for the original single-pipeline flow.

---

### `LmStudioClient`
**Purpose**: HTTP client to LM Studio / Ollama API.

| Method | Description |
|--------|-------------|
| `chat(messages)` | POST to LM Studio chat endpoint, return raw response string or null |

---

### `AiResponseParser`
**Purpose**: Validate and parse AI JSON response.

| Method | Description |
|--------|-------------|
| `parse(rawResponse)` | Validate JSON, check required fields, return array or null |

Required fields: `action`, `confidence`, `type`, `reason`

---

## Notification Services (`App\Services\Notification\`)

### `PerModelNotificationService`
**Purpose**: Format and send per-model labeled Telegram notifications.

| Method | Description |
|--------|-------------|
| `notify(model, signal, aiDecision, marketRegime, executionId)` | Build message, send via Telegram |

**Message includes**: Model label, action, coin, timeframe, confidence bar, setup summary, market regime, AI status, execution ID.

---

### `NotificationService` _(Legacy pipeline)_
**Purpose**: Core Telegram sender for the original pipeline.

---

## Trading Services (`App\Services\Trading\`)

### `SignalPreFilterService` _(formerly `MCPService`)_
**Purpose**: Deterministic pre-filter for coins/timeframes before AI call (legacy pipeline).

Output: `{passed: bool, reason: string, score: float}`

---

### `TradingCycleService`
**Purpose**: Orchestrator for the legacy full trading pipeline (still scheduled every 5 min via `trading:run-cycle`).

---

## External Services (`App\Services\External\`)

### `CoinGeckoService`
**Purpose**: CoinGecko API client for market data and coin lists.

| Method | Description |
|--------|-------------|
| `fetchCoinMarkets(page, perPage)` | GET `/coins/markets`, return array of coin data |

---

## Indicator Services (`App\Services\Indicator\`)

### `IndicatorService`
**Purpose**: Calculate technical indicators (RSI, EMA, ATR, MACD, trend classification) from OHLCV data.

- Must NOT call external APIs
- Designed to be replaceable by an external Python service in the future
