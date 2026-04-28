# MCP Architecture Extension — Requirements & Objectives

**Date**: April 28, 2026  
**Status**: Planning Phase Complete → Ready for Implementation  

## 🎯 OBJECTIVE

Extend existing Laravel system to introduce:
1. **Shared Market Context Processor (MCP)** — Global market regime detection
2. **3 parallel independent models** — Counter-Trend, Pre-Pump, Momentum  
3. **Per-model AI interpretation** — Optional signal refinement
4. **Per-model notifications** — Clear labeling

## ⚠️ MANDATORY CONSTRAINTS

### DO NOT
- ❌ Merge models at signal level
- ❌ Centralize decision-making
- ❌ Convert to auto-trading system
- ❌ Have MCP calculate model-specific indicators
- ❌ Run models sequentially

### DO
- ✅ Keep models completely independent
- ✅ Make MCP shared, single source of truth
- ✅ Make AI optional, per-model
- ✅ Maintain execution_id traceability
- ✅ Keep signal-only (no auto-execution)

## 🧠 MCP DEFINITION

**What**: Global service determining market regime independent of coin/model

**Output**: 
```json
{
  "market_regime": "TRENDING_UP|TRENDING_DOWN|RANGING|CHOPPY",
  "btc_direction": "UP|DOWN|SIDEWAYS",
  "volatility": "LOW|MEDIUM|HIGH",
  "market_strength": "WEAK|MODERATE|STRONG",
  "risk_level": "LOW|MEDIUM|HIGH"
}
```

**Data Sources**:
- BTC OHLCV (1H, 4H, 1D)
- BTC RSI/EMA slope
- Total market cap trend
- Average ATR across top 10 coins
- BTC dominance trend

**NOT MCP's Role**:
- ❌ Calculate Model 1/2/3 indicators
- ❌ Pre-filter by model logic
- ❌ Make trading decisions

## 🔄 EXECUTION FLOW

### Phase 1: Market Context (Every 5 min)
- Fetch BTC OHLCV → Calculate regime → Persist Redis

### Phase 2: Coin Universe (Daily @00:00)
- Filter by cap/volume → Cache Redis (24h)

### Phase 3: Models PARALLEL (No blocking)
- CounterTrendJob (15m) | PrePumpJob (30m) | MomentumJob (1h)
- Each: Fetch candidates → Evaluate → Generate Top 10 → AI (opt) → Notify

## ✅ SUCCESS CRITERIA

**Functional**:
- 3 independent Top 10 lists
- Market regime broadcasts to all
- AI optional per-model
- Per-model notifications (labeled)
- Full execution_id traceability

**Performance**:
- Parallel reduces latency
- Independent job failure/retry
- Redis caching (5m/24h TTL)

**Code Quality**:
- SRP maintained
- Laravel best practices
- Comprehensive tests
- Backtesting validation