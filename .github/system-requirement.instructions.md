# CRYPTO TRADING SYSTEM - System Requirements and Developer Blueprint

Version: 3.0
Year: 2026
Audience: Developers and AI Coding Agents
Status: PRIMARY IMPLEMENTATION INSTRUCTION

## Source Authority

1. Primary source of truth: .github/TradingOS_Blueprint_Final.docx.html
2. Supplementary breakdown: .github/flow.html
3. Conflict rule: if any requirement conflicts, Blueprint wins.
4. Override from owner instruction: this system is for PERPETUAL coins. Never assume SPOT execution scope.

## Table of Contents

1. System Overview
2. Non-Negotiable Rules
3. Scope and Trading Type Policy
4. High-Level Architecture
5. Shared Data Fetch Requirements
6. Shared Pre-Filter Requirements
7. Model Requirements
8. Shared OHLCV and Derivatives Cache Requirements
9. Watchlist Requirements
10. Final Confluence Filter Requirements
11. Entry Confirmation Panel Requirements
12. Data Sources and Public API Policy
13. Caching, Rate Limiting, Retry, and Fallback
14. Scheduling Requirements
15. Logging, Traceability, and Persistence
16. Performance and Optimization Targets
17. Future Expansion Requirements
18. Developer Checklist
19. Deprecated Notes
20. Final Scope Reminder

## 1. System Overview

This system generates ranked crypto trade signals using four independent model services.

Each model must run as its own process/service and produce its own Top 10 result list.

System purpose:

1. Minimize API usage through shared caching and layered filtering.
2. Improve signal quality through multi-stage filtering and final confluence validation.
3. Provide trader-ready manual confirmation payloads for perpetual-market execution.
4. Keep full historical traceability for backtesting, forward testing, and AI optimization.

## 2. Non-Negotiable Rules

1. Keep all four models independent. Never merge into one ranked super-list.
2. All external market data must come from public APIs.
3. Model services consume shared cached data. Do not call external APIs directly from model logic when shared fetch exists.
4. Derivatives features (OI, funding, CVD) are confirmation layers, not standalone trigger authority.
5. Final entry eligibility must pass confluence validation before trader confirmation.
6. No auto-trade execution in this phase. Execution is manual by trader.
7. Do not assume missing rules. If a rule is not defined, treat it as unresolved and explicit.

## 3. Scope and Trading Type Policy

Primary operational market scope:

1. Perpetual contracts (perpetual futures) for decision output and trader action.
2. Long and short can be supported where model logic allows.
3. Leverage recommendations are part of decision payload and confluence checks.

Model 4 interpretation (owner-approved):

1. Spot Gainers remains a spot-momentum detection method.
2. Its output is used as a signal source for perpetual opportunity qualification.
3. It is not a mandate for SPOT execution flow in this system.

## 4. High-Level Architecture

Mandatory sequence:

1. Layer 1: Shared market data fetch.
2. Layer 2: Shared pre-filter.
3. Layer 3: Model-specific secondary filter and heavy analysis.
4. Layer 4: Watchlist generation and logging.
5. Layer 5: Final confluence validation.
6. Entry Layer: Trader confirmation panel with manual execution.

Execution pattern:

1. One shared fetch path populates cache.
2. All model services read from that cache.
3. Heavy calls are delayed until candidates pass earlier layers.
4. Watchlist candidates fan-in to confluence gate.
5. Only confluence-approved candidates move to entry confirmation panel.

## 5. Shared Data Fetch Requirements

Source:

1. CoinGecko Top 300 market data endpoint.
2. Single shared request must serve all models.

Required fields:

1. coin_id
2. symbol
3. market_cap
4. total_volume_24h
5. price_change_percentage_24h
6. market_cap_rank
7. current_price

Cache strategy:

1. TTL: 5 minutes.
2. All downstream models must reuse this cache.
3. Duplicate upstream requests across models are prohibited.

## 6. Shared Pre-Filter Requirements

Objective:

1. Reduce about 300 assets to about 150-200 tradable candidates.

Mandatory removal criteria:

1. Stablecoins.
2. Wrapped tokens.
3. Volume below 1,000,000 USD.
4. Market cap below 50,000,000 USD.
5. Incomplete/null critical market fields.

Output requirements:

1. Shared filtered dataset available to all models.
2. Dataset must be cached and reusable for model runs.

## 7. Model Requirements

### 7.1 Model 1 - Counter Trend

Universe:

1. Rank 50-300
2. Volume above 5,000,000 USD

Strategy:

1. Counter-trend reversal detection.

Heavy analysis components:

1. OHLCV 15m and 4h
2. Open Interest
3. CVD
4. Liquidity sweep detection
5. Market Structure Shift (MSS)

### 7.2 Model 2 - Pre-Pump Detection

Universe:

1. Rank 20-150
2. Volume above 10,000,000 USD

Strategy:

1. Early accumulation and breakout preparation.

Heavy analysis components:

1. Funding rate
2. Open Interest
3. ATR
4. CVD compression
5. OHLCV 4h

### 7.3 Model 3 - Trend Momentum

Universe:

1. Rank 1-50
2. Volume above 50,000,000 USD

Strategy:

1. Trend continuation momentum.

Heavy analysis components:

1. EMA 50/200
2. MACD
3. RSI
4. Break of Structure (BOS)
5. OHLCV 1d and 4h

### 7.4 Model 4 - Spot Gainers as Perpetual Signal Source

Universe:

1. Rank 1-200 gainers scope
2. Top 24h gainers logic

Strategy:

1. Spot-momentum pattern detection used to qualify perpetual opportunities.

Heavy analysis components:

1. OHLCV 1d
2. Candle pattern criteria
3. Volume spike validation
4. Stop-loss baseline calculation

## 8. Shared OHLCV and Derivatives Cache Requirements

1. OHLCV cache is centralized and shared across models.
2. Cache key format for OHLCV: symbol_interval (example: BTC_1d, AKT_4h, AKT_15m).
3. Repeated symbol/timeframe requests within TTL must not refetch.
4. OI and funding cache should be symbol-based.
5. CVD cache must be shared to prevent duplicate trade-stream computation.

## 9. Watchlist Requirements

### 9.1 Generation

1. Each model generates Top 10 candidates.
2. Watchlist rows must be logged to database each run.

### 9.2 Monitoring

1. Monitor watchlist every 4 hours.
2. Track Open Interest changes.
3. Track funding rate changes.
4. Track CVD changes.

### 9.3 Persistence

1. Candidates rejected by confluence must remain in watchlist history.
2. Historical watchlist and signal states must be retained for audit and model improvement.

## 10. Final Confluence Filter Requirements

Objective:

1. Only high-confidence candidates can proceed to entry confirmation.

Mandatory conditions:

1. All required model-side checks must pass simultaneously.
2. Stop-loss distance must be valid.
3. Raw leverage score must be >= 10x equivalent threshold.
4. Skip-rule condition must not be triggered.

Actions when confluence runs:

1. Store final confluence status in database.
2. Trigger notification flow for approved candidate states.
3. Send approved candidate payload to trader UI.
4. Keep rejected candidates in watchlist lifecycle, do not hard-delete from history.

## 11. Entry Confirmation Panel Requirements

Panel payload must include:

1. Entry price
2. Stop-loss level
3. Target T1, T2, T3
4. Recommended leverage
5. Recommended position size (5 USD to 10 USD)

Execution policy:

1. Trader executes manually at exchange.
2. No auto-trading in this phase.

Data retention:

1. Save all confirmed entries.
2. Keep data reusable for backtesting.
3. Keep data reusable for forward testing.
4. Keep data reusable for AI optimization training.

## 12. Data Sources and Public API Policy

Allowed sources (public endpoints only):

1. CoinGecko: market list, market chart, OHLC alternatives
2. Binance: klines and trade stream for CVD
3. Coinalyze or equivalent public derivatives endpoints: OI and funding
4. CoinMarketCap: gainers list for Model 4 detection path

Rules:

1. Use public endpoints only.
2. No paid-only dependency may become mandatory core path without explicit change request.

## 13. Caching, Rate Limiting, Retry, and Fallback

Cache targets:

1. Shared market data TTL: 5 minutes
2. Shared pre-filter cache aligned to shared fetch lifecycle
3. OHLCV shared cache active across models
4. Derivatives cache active across models

Reliability policy:

1. Timeout: 10 seconds per external request.
2. Retry: up to 3 attempts with exponential backoff.
3. Circuit-break behavior: temporarily skip failing source after repeated failures.
4. Fallback: prefer stale cache over full pipeline failure.

Error behavior:

1. If one candidate fails data fetch, skip that candidate and continue pipeline.
2. Do not crash entire model cycle for per-coin failures.
3. Log failure reason with execution trace fields.

## 14. Scheduling Requirements

Baseline schedule:

1. Shared data fetcher: every 5 minutes
2. Model 1: every 15 minutes
3. Model 2: every 4 hours
4. Model 3: every 4 hours
5. Model 4: daily at 07:00 WIB (UTC+7)

## 15. Logging, Traceability, and Persistence

Mandatory traceability:

1. Every pipeline run must generate execution_id.
2. execution_id must be attached to raw fetch, filter outputs, model analysis, watchlist rows, confluence result, and entry payload.

Mandatory logs:

1. Start and end of each service run
2. Shared fetch result status
3. Filter pass/fail summaries
4. Model scoring output summary
5. Confluence pass/reject and reason
6. Notification send status
7. Entry confirmation storage status

Persistence rules:

1. Keep historical records immutable for audit/backtest use.
2. Do not overwrite prior signal history.

## 16. Performance and Optimization Targets

Hard targets:

1. Daily API usage target: 5,000 to 8,000 calls/day
2. Optimization target: around 95 percent reduction versus naive per-model full-scan architecture
3. Pipeline must remain asynchronous and scalable
4. Shared cache and layered filtering are mandatory architecture, not optional

## 17. Future Expansion Requirements

1. AI scoring optimization layer
2. Adaptive filtering thresholds
3. Automated backtesting engine
4. Forward-testing analytics
5. Telegram and mobile push notifications
6. Multi-exchange support
7. Risk management automation

## 18. Developer Checklist

### 18.1 Core Architecture

1. Shared fetch service implemented with 5-minute cache
2. Shared pre-filter implemented and reused by all models
3. Independent services for Model 1-4 implemented
4. Heavy analysis delayed until candidate subset stage
5. Shared cache for OHLCV and derivatives enabled

### 18.2 Watchlist and Confluence

1. Top 10 watchlist output per model stored each run
2. 4-hour watchlist monitoring snapshots implemented
3. OI/funding/CVD delta logging implemented for watchlist rows
4. Confluence gate implemented with mandatory checks
5. Rejected candidates retained in watchlist history

### 18.3 Entry and Manual Execution

1. Entry panel payload fields complete (entry, SL, T1-T3, leverage, size)
2. Manual execution policy enforced (no order placement automation)
3. Confirmed entries persisted for backtest and optimization

### 18.4 Reliability and Performance

1. Retry, timeout, and fallback behavior implemented
2. Per-coin error isolation implemented (skip and continue)
3. API usage trend monitored against 5k-8k daily target
4. End-to-end logging with execution_id verified

### 18.5 Perpetual Scope Validation

1. Output decision scope is perpetual-market oriented
2. Model 4 is treated as spot-derived signal source for perpetual qualification
3. No active requirement enforces spot-only execution flow

## 19. Deprecated Notes

The following legacy instruction patterns are deprecated and must not be treated as active requirements:

1. Any requirement that makes flow.html override Blueprint.
2. Any active requirement that frames this system as spot-only execution.
3. Any standalone model design that bypasses shared-fetch and shared-cache architecture.
4. Any architecture that skips watchlist lifecycle or final confluence stage before entry panel.
5. Any requirement implying automated order execution in this phase.

## 20. Final Scope Reminder

This is a signal generation and trader confirmation system.

1. It does not auto-execute trades.
2. It does not manage exchange account orders directly.
3. It produces perpetual-market candidate signals with full traceability.
4. Model 4 spot-momentum logic is supplementary signal intelligence for perpetual qualification.
