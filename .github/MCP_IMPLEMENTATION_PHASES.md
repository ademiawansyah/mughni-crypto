# MCP Implementation Plan — Spec-Aligned Phases

Timeline: 8 weeks
Source of truth: .github/system-spec.instructions.md

## Cross-Reference Matrix
| This document section | Canonical spec section |
|---|---|
| Phase 1: Foundation and Shared Data Layer | 4, 8, 9, 10.5 |
| Phase 2: Independent Model Services | 5, 6, 7 |
| Phase 3: AI and Notifications | 4.3, 11, 13 |
| Phase 4: Integration, Testing, and Operations | 11, 12 |
| Success Criteria | 2, 4.3, 12, 13 |

If any phase item conflicts with the source spec, follow the source spec.

## Phase 1 — Foundation and Shared Data Layer

### 1.1 Centralized Data Fetcher
- Build one data-fetcher service running every 5 minutes.
- Fetch public OHLCV, OI, funding, trades, market cap, and volume data.
- Cache normalized datasets and preserve enough candle history for indicators.

### 1.2 Market Regime Service
- Build global market context service from cached data.
- Publish standardized regime snapshot:
  - market_regime
  - btc_direction
  - volatility
  - market_strength
  - risk_level

### 1.3 Coin Universe Service
- Build daily coin universe refresh.
- Apply filters:
  - market cap >= 100M USD
  - volume >= 5M USD
  - OI >= 1M USD
  - exclude stablecoin-like pairs

### 1.4 Verification
- Data fetcher writes stable cache snapshots.
- Market regime output schema is stable.
- Coin universe is reproducible and filtered correctly.

## Phase 2 — Independent Model Services

### 2.1 Counter Trend Service
- Implement liquidity sweep, MSS, FVG or OB context, and derivatives confirmation.
- Apply scoring weights: 30, 25, 15, 15, 10, 5.
- Emit Top 10 ranked output.

### 2.2 Pre-Pump Service
- Implement funding pressure, OI expansion, ATR compression, RS vs BTC, and CVD slope checks.
- Apply scoring weights: 35, 25, 20, 10, 10.
- Emit Top 10 ranked output.

### 2.3 Trend Momentum Service
- Implement EMA structure, MACD and RSI synergy, OI and CVD confirmation, BOS continuity.
- Apply scoring weights: 25, 20, 15, 20, 10, 10.
- Emit Top 10 ranked output.

### 2.4 Scheduling and Isolation
- Schedule model services independently:
  - Counter Trend every 15 minutes
  - Pre-Pump every 30 minutes
  - Trend Momentum every 1 hour
- Keep services parallel and isolated.
- Prevent direct external API calls from model services.

### 2.5 Verification
- Model outputs remain separate and non-merged.
- Component-level scores are present.
- Threshold behavior is test-covered.

## Phase 3 — AI and Notifications

### 3.1 Optional Per-Model AI Layer
- Add optional AI interpretation per model.
- Keep model output schema stable whether AI is enabled or disabled.
- Do not let AI replace deterministic scoring logic.

### 3.2 Notification Layer
- Add per-model notification formatting and thresholds.
- Preserve model label and confidence in message output.

### 3.3 Verification
- AI can be toggled without breaking output contract.
- Notifications stay model-specific.

## Phase 4 — Integration, Testing, and Operations

### 4.1 Integration Validation
- End-to-end pipeline validation from data-fetch to model output.
- Cache and retry behavior validated under API instability.

### 4.2 Testing Coverage
- Unit tests for indicators, trigger gates, and scoring.
- Integration tests for model schedules and parallel behavior.
- Contract tests for model output schema.

### 4.3 Backtesting and Monitoring
- Run historical replay and compare model behavior across windows.
- Capture metrics for cache hit rate, job latency, and signal consistency.

### 4.4 Deliverables
- Three independent model services in production flow.
- Shared data-fetch and market context services stable.
- Per-model Top 10 output available through API and notifications.

## Success Criteria

### Functional
- Independent Top 10 per model.
- Stable model output schema with component-level details.
- Signal-only behavior preserved.

### Performance
- Parallel scheduling works without cross-model blocking.
- Cache-first model execution reduces direct API pressure.

### Quality
- SRP boundaries are preserved.
- Tests cover edge cases and thresholds.
- Future changes can follow source spec with minimal ambiguity.