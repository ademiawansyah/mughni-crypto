# Mughni Crypto — AI Trading Advisor System

**Version**: 1.0 | **Stack**: Laravel 13 + Filament 5 + Redis + Telegram

---

## Overview

This system is a **signal-only** crypto trading advisor. It does **not** execute trades automatically. It analyzes market data, applies three independent trading models, optionally refines signals through an AI layer, and delivers labeled notifications per model.

```
SIGNAL SYSTEM — NOT AUTO-TRADING
Output per model: Top 10 coins with BUY/SELL/HOLD signal
```

---

## Three Independent Models

| Model | Strategy | Schedule | Timeframes |
|-------|----------|----------|------------|
| **Counter-Trend** | Reversal detection (liquidity sweep + exhaustion) | Every 15 min | 15M, 1H, 4H, 1D |
| **Pre-Pump** | Short squeeze / accumulation detection | Every 30 min | 15M, 1H, 4H |
| **Momentum** | Trend continuation confirmation | Every 1 hour | 15M, 1H, 4H, 1D |

> Models are **completely independent** — they never share signal logic or merge outputs.

---

## High-Level Data Flow

```
CoinGecko / Binance API
        │
        ▼
  FetchMarketJob (every 5 min)
        │
        ├──► MarketRegimeJob ──► Redis: market_context:latest (TTL 5m)
        │
        ├──► UpdateCoinUniverseJob (daily) ──► Redis: coin_universe:main (TTL 24h)
        │
        └──► (Indicator data stored in market_indicators table)

                     ┌──────────────────────────────────────────┐
                     │  PARALLEL (independent queues)           │
                     │                                          │
              CounterTrendJob      PrePumpJob      MomentumJob  │
              (every 15 min)     (every 30 min)   (every 1h)   │
                     │                  │               │       │
                     └──────────────────┴───────────────┘       │
                                        │                        └──
                              Each model job:
                              1. Read market_context:latest from Redis
                              2. Read coin_universe:main from Redis
                              3. Evaluate each candidate coin
                              4. Score and rank → Top 10
                              5. Optional AI refinement (per model config)
                              6. Persist to ai_decisions table
                              7. Send Telegram notification (if BUY/SELL + threshold met)
```

---

## Key Principles

- **Execution ID**: Every pipeline run generates a UUID `execution_id` attached to all logs, raw data, indicators, AI decisions, and notifications — full traceability
- **AI is Advisory**: AI layer refines confidence only — it does not override model logic
- **Duplicate Protection**: Signals are de-duplicated by `model + coin + timestamp`
- **Backtesting-ready**: All raw data stored unaltered, decisions reproducible
- **Multi-timeframe**: All models accept timeframe as a parameter, no hardcoding

---

## Quick Links

| Document | Description |
|----------|-------------|
| [Architecture](architecture.md) | Service classes, responsibilities, folder structure |
| [Execution Flow](execution-flow.md) | Step-by-step pipeline walkthrough |
| [Models](models/) | Counter-Trend, Pre-Pump, Momentum |
| [Services](services.md) | All service classes and their roles |
| [Configuration](configuration.md) | `config/models.php` walkthrough |

---

## Directory Structure (Key Files)

```
app/
├── Jobs/
│   ├── MarketRegimeJob.php        # Every 5 min → detect market regime
│   ├── UpdateCoinUniverseJob.php  # Daily → refresh eligible coin list
│   ├── CounterTrendJob.php        # Every 15 min → Model 1
│   ├── PrePumpJob.php             # Every 30 min → Model 2
│   └── MomentumJob.php            # Every 1h → Model 3
│
├── Services/
│   ├── Market/
│   │   ├── MarketRegimeService.php      # BTC structure → global regime
│   │   ├── CoinUniverseService.php      # Filter eligible coins
│   │   ├── FetchMarketDataService.php   # Ingest raw OHLCV
│   │   ├── ModelSignalPersistenceService.php
│   │   └── Models/
│   │       ├── AbstractMarketModelService.php
│   │       ├── CounterTrendModelService.php
│   │       ├── PrePumpModelService.php
│   │       ├── MomentumModelService.php
│   │       └── ModelSignalDTO.php
│   ├── AI/
│   │   ├── PerModelAiLayer.php    # Optional AI refinement per model
│   │   ├── AiAdvisorService.php
│   │   ├── LmStudioClient.php
│   │   └── AiResponseParser.php
│   ├── Notification/
│   │   ├── PerModelNotificationService.php  # Labeled Telegram per model
│   │   └── NotificationService.php
│   └── Trading/
│       ├── SignalPreFilterService.php   # (formerly MCPService)
│       └── TradingCycleService.php
│
config/
│   └── models.php                 # All model + regime + AI + notification config
│
routes/
│   └── console.php                # Artisan commands + job scheduling
```
