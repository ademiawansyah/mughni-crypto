# Architecture Breakdown — Discovery & Analysis

**Date**: April 28, 2026

## Current System Architecture

### Services Existing
| Service | Purpose | Location |
|---------|---------|----------|
| MarketDataService | Orchestrates ingestion | `app/Services/Market/` |
| MCPService | Coin/TF pre-filter | `app/Services/MCP/` |
| IndicatorService | Calculate RSI, EMA | `app/Services/Indicator/` |
| AiAdvisorService | AI decision layer | `app/Services/AI/` |
| TradingCycleService | Full orchestrator | `app/Services/Trading/` |

### Job Pipeline
```
FetchMarketJob 
  → ProcessIndicatorJob 
  → RunAiDecisionJob 
  → EvaluateAiDecisionJob
```

### Key Models
- **MarketRaw**: Unaltered API responses (audit trail)
- **MarketIndicator**: Technical data (RSI, EMA, ATR, etc.)
- **AiDecision**: Trading decisions (action, confidence, reason)
- **MarketContext**: MTF context persistence

### Execution Properties
- **Execution ID**: UUID traces entire pipeline run
- **Timeframes**: 5m, 15m, 30m, 60m
- **Role timeframes**: Trigger, Setup, Context (weighted)

---

## Discovery: Key Findings

### Finding 1: Models Are Implicit
- Current system has single unified pipeline
- Model logic is NOT explicitly separated
- Recommendation: Extract into 3 explicit service classes

### Finding 2: MCP Naming Conflict
- Existing MCPService = coin/TF pre-filter
- User's MCP = global market regime
- Recommendation: Rename MCPService → SignalPreFilterService

### Finding 3: Sequential Execution
- Current: Jobs chain sequentially (blocking)
- Models not independent
- Recommendation: Parallel job execution

### Finding 4: AI Already Integrated
- AI used as MTF refinement layer
- Confidence adjustment only
- Future: Per-model AI interpretation

---

## Architecture Gap Analysis

| Aspect | Current | Needed | Gap |
|--------|---------|--------|-----|
| Market Context | Per-coin/TF | Global (BTC regime) | Add MarketRegimeService |
| Models | Implicit | Explicit (3 services) | Create Model services |
| Execution | Sequential | Parallel | Separate job queues |
| AI Role | MTF refinement | Per-model interpretation | Extend AI layer |
| Notifications | Single stream | Per-model labeled | Add model field |

---

## Design Decisions Made

| Decision | Chosen | Rationale |
|----------|--------|-----------|
| **Model Implementation** | Separate Job per model | True parallelism |
| **MCPService** | Rename to SignalPreFilterService | Avoid terminology confusion |
| **Coin Universe** | Shared pre-filtered | Deterministic, reduce API load |
| **AI Layer** | Optional per-model | Phase 1 flexibility |
| **Notifications** | Per-model labeled | Clarity on signal source |

---

## Service Responsibilities (SRP)

```
MarketRegimeService
  ↓ (output: market_context to Redis)
  ├─ CounterTrendModelService
  ├─ PrePumpModelService
  └─ MomentumModelService
       ↓ (each fetches market_context)
       ├─ Optional: Per-model AI Layer
       └─ Per-model NotificationService
```

---

## Data Flow

```
Raw Data (Binance/CoinGecko)
  ↓
FetchMarketDataService
  ├─ Store to market_raw (unaltered)
  ├─ Build candles
  └─ Calculate indicators
       ↓
  ├─ MarketRegimeService (global context)
  ├─ CounterTrendJob (Model 1)
  ├─ PrePumpJob (Model 2)
  └─ MomentumJob (Model 3)
       ↓
  ├─ Per-model AI (optional)
  └─ Per-model Notifications
       ↓
  SignalPersistenceService (ai_decisions table)
```