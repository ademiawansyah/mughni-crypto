# Architecture Breakdown — MCP Extension

Date: April 28, 2026
Source of truth: .github/system-spec.instructions.md

## Cross-Reference Matrix
| This document section | Canonical spec section |
|---|---|
| Core Design | 2, 4.2, 13 |
| Service Topology | 4.1, 4.2 |
| Data Flow | 4, 8, 9 |
| Model Isolation Rules | 2, 11 |
| Execution Properties | 4.1, 4.3 |
| Risks to Avoid | 4.2, 11 |

## Scope
This document describes the target MCP architecture aligned to the canonical system spec.

## Core Design
- Signal-only system.
- Three independent model services.
- Shared centralized data-fetcher.
- Cache-first model execution.
- No direct external API calls from model workers.

## Service Topology
```
DataFetcherService
  -> fetch public data (OHLCV, OI, funding, trades)
  -> store raw and normalized cache/history

MarketRegimeService
  -> consume cached data
  -> publish market context snapshot

CoinUniverseService
  -> apply market cap, volume, and OI filters
  -> publish candidate coin list

CounterTrendService (15m)
PrePumpService (30m)
TrendMomentumService (1h)
  -> consume shared cache and context
  -> emit model-specific Top 10 output
```

## Data Flow
```
Public APIs (CoinGecko, Binance, Coinalyze, others)
  -> DataFetcherService (5m)
  -> Shared cache/history (Redis and or DB)
  -> MarketRegimeService
  -> CoinUniverseService
  -> Model services in parallel
  -> API gateway and notification layer
```

## Model Isolation Rules
- Each model keeps its own trigger and scoring logic.
- Derivatives are confirmation inputs, not primary triggers.
- Outputs are never merged into one ranked list.

## Execution Properties
- Parallel model scheduling: 15m, 30m, 1h.
- Deterministic output schema per model.
- Traceability required from fetch to ranked output.

## Risks to Avoid
- Reintroducing sequential model dependencies.
- Allowing model services to bypass cache.
- Coupling model logic in a shared decision function.
- Hiding component-level scores in output.