# MCP Architecture Extension — Requirements and Objectives

Date: April 28, 2026
Status: Planning baseline
Source of truth: .github/system-spec.instructions.md

## Cross-Reference Matrix
| This document section | Canonical spec section |
|---|---|
| Objective | 1, 2 |
| Mandatory Constraints | 2, 4.2, 13 |
| MCP Definition | 4, 9 |
| Data Scope | 3, 8 |
| Execution Pattern | 4.1, 4.2, 9, 10.5 |
| Success Criteria | 2, 4.3, 11, 12 |

## Objective
Extend the system with a shared market context processor and keep three model services independent:
- Counter Trend
- Pre-Pump
- Trend Momentum

## Mandatory Constraints

### Do Not
- Do not merge model outputs into a single combined signal list.
- Do not convert the system into auto-trading.
- Do not let MCP calculate model-specific indicators.
- Do not run model jobs in a blocking sequential chain.
- Do not call external APIs directly from model workers.

### Must Do
- Keep model services independent.
- Keep the system signal-only.
- Use one centralized data-fetcher service.
- Use cache-first model execution (Redis or equivalent).
- Preserve per-model Top 10 ranked outputs with component scores.
- Preserve execution traceability across jobs and outputs.

## MCP Definition
MCP is a global context service, independent from per-coin model logic.

### MCP Output Contract
```json
{
  "market_regime": "TRENDING_UP|TRENDING_DOWN|RANGING|CHOPPY",
  "btc_direction": "UP|DOWN|SIDEWAYS",
  "volatility": "LOW|MEDIUM|HIGH",
  "market_strength": "WEAK|MODERATE|STRONG",
  "risk_level": "LOW|MEDIUM|HIGH"
}
```

## Data Scope
Public APIs only.
- CoinGecko
- Binance public endpoints
- Coinalyze
- Optional equivalent public providers

## Execution Pattern
- Data fetcher runs every 5 minutes and updates shared cache/history.
- Market context is recalculated from shared data.
- Coin universe is refreshed on schedule using volume and OI filters.
- Counter Trend runs every 15 minutes.
- Pre-Pump runs every 30 minutes.
- Trend Momentum runs every 1 hour.

## Success Criteria

### Functional
- Three independent Top 10 lists are produced.
- Output schema is stable and model-labeled.
- Derivatives remain confirmation signals, not primary triggers.

### Performance
- Shared cache lowers external API pressure.
- Model execution remains parallel and isolated.

### Quality
- Single responsibility boundaries are preserved.
- Test coverage includes scoring, trigger gates, and threshold behavior.
- No regression in output format or signal transparency.