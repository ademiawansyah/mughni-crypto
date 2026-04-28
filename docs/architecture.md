# System Architecture

## Architecture Principles

| Principle | Detail |
|-----------|--------|
| **Thin Controllers** | Controllers orchestrate only; all logic in Services |
| **Single Responsibility** | Each class has one clearly defined purpose |
| **Jobs for async processing** | All heavy/background work runs via Laravel queues |
| **Models = data representation** | No business logic in Eloquent models |
| **AI is advisory** | AI produces a recommendation, never the final decision |

---

## Service Layer Map

```
App\Services\
├── Market\
│   ├── MarketDataService            ← Orchestrates raw data ingestion
│   ├── FetchMarketDataService       ← Fetches OHLCV from CoinGecko/Binance
│   ├── CandleBuilderService         ← Builds candles from raw data
│   ├── CandlePersistenceService     ← Persists candles to DB
│   ├── MarketRegimeService          ← Global BTC regime detection → Redis
│   ├── CoinUniverseService          ← Coin filtering → Redis
│   ├── MarketContextPersistenceService
│   ├── ModelSignalPersistenceService ← Persists per-model signals to DB
│   └── Models\
│       ├── AbstractMarketModelService   ← Base: resolve coins, indicators, regime
│       ├── CounterTrendModelService     ← Model 1: reversal logic + scoring
│       ├── PrePumpModelService          ← Model 2: squeeze logic + scoring
│       ├── MomentumModelService         ← Model 3: trend logic + scoring
│       └── ModelSignalDTO               ← Immutable signal payload
│
├── AI\
│   ├── PerModelAiLayer     ← Per-model AI refinement (optional)
│   ├── AiAdvisorService    ← Core AI orchestration (legacy pipeline)
│   ├── AIPromptService     ← Builds prompts for AI
│   ├── LmStudioClient      ← HTTP client to LM Studio / Ollama
│   └── AiResponseParser    ← Validates + parses AI JSON response
│
├── Indicator\
│   └── IndicatorService    ← Calculates RSI, EMA, ATR, MACD, trend
│
├── Trading\
│   ├── TradingCycleService      ← Legacy full pipeline orchestrator
│   ├── SignalPreFilterService    ← Pre-AI deterministic filtering (formerly MCPService)
│   ├── TradingDecisionService   ← Final decision authority (legacy)
│   └── (others: guardrails, risk, scoring)
│
├── MCP\
│   ├── MCPService              ← Multi-timeframe pre-filter (legacy)
│   └── MultiTimeframeMCPService
│
├── Notification\
│   ├── PerModelNotificationService ← Telegram per-model labeled alerts
│   └── NotificationService          ← Core Telegram sender (legacy pipeline)
│
└── External\
    └── CoinGeckoService    ← CoinGecko API client
```

---

## Decision Authority

```
Market Data
    │
    ▼
Trading Model (CounterTrend / PrePump / Momentum)
    │    └── Signal: action, score, component_scores, reasons
    │
    ▼
PerModelAiLayer (OPTIONAL)
    │    └── Advisory: AI confidence adjustment + agreement check
    │
    ▼
ModelSignalPersistenceService
    │    └── Stores final signal to ai_decisions table
    │
    ▼
PerModelNotificationService
         └── Sends Telegram alert if action=BUY|SELL + confidence ≥ threshold
```

> **The trading model is the source of truth for the signal.**
> The AI layer adjusts confidence only — it never overrides the action logic.

---

## Queue Architecture

| Queue Name | Jobs | Purpose |
|------------|------|---------|
| `market` | `MarketRegimeJob`, `UpdateCoinUniverseJob`, `FetchMarketJob` | Market data ingestion and regime detection |
| `models` | `CounterTrendJob`, `PrePumpJob`, `MomentumJob` | Independent model evaluation |
| `default` | All other jobs | General processing |

Model jobs run in **parallel** on the `models` queue — they do not chain or block each other.

---

## Data Models (Eloquent)

| Model | Table | Purpose |
|-------|-------|---------|
| `MarketRaw` | `market_raws` | Unaltered raw API responses (audit trail) |
| `MarketIndicator` | `market_indicators` | Calculated indicators (RSI, EMA, ATR, trend) |
| `AiDecision` | `ai_decisions` | Trading signals + AI decisions from all models |
| `MarketContext` | `market_contexts` | MTF context persistence |
| `Trade` | `trades` | Trade records (for tracking) |
| `GeneralConfig` | `general_configs` | Runtime config (cron enabled, model toggles) |

### AiDecision Key Fields

```
execution_id   → UUID tracing the pipeline run
model          → counter_trend | pre_pump | momentum
coin           → CoinGecko coin ID
action         → BUY | SELL | HOLD
confidence     → 0–100
timeframe      → Primary signal timeframe
market_regime  → JSON: global market context at signal time
ai_decision    → JSON: AI layer output (action, confidence, reasoning, agreement)
input_data     → JSON: full signal data + component scores
raw_response   → Raw AI response string
```

---

## Redis Keys

| Key | TTL | Owner | Consumer |
|-----|-----|-------|----------|
| `market_context:latest` | 300s (5 min) | `MarketRegimeService` | All 3 model services |
| `coin_universe:main` | 86400s (24h) | `CoinUniverseService` | All 3 model services |
| `trading_models:{model}:latest` | 1h | Each model service | Filament dashboard |

---

## Traceability (Execution ID)

Every pipeline run generates a UUID `execution_id` that is attached to:

1. Job start/end logs
2. Raw API responses
3. Indicator calculation results
4. Pre-filter evaluations
5. AI prompt/response
6. Final signal/decision
7. Telegram notifications

This enables full end-to-end traceability and backtesting reconstruction.
