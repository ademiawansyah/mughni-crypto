# MCP Implementation — Phase-by-Phase Plan

**Timeline**: 8 weeks (Phases 1-4)  
**Current Status**: Phase 1 — Ready to Start

**Source of Truth**: `.github/system-spec.instructions.md` is authoritative for system design. If this plan conflicts with that document, the system spec wins.

---

## PHASE 1: Foundation (Weeks 1-2)

### Task 1.1: Rename MCPService → SignalPreFilterService
- **Files to modify**:
  - `app/Services/MCP/MCPService.php` → `app/Services/Trading/SignalPreFilterService.php`
  - All imports in controllers, jobs, services
  - Config references
  - Tests

- **Verification**:
  - All tests pass
  - No "MCPService" references remain (except in comments)
  - Service behavior unchanged

### Task 1.2: Create MarketRegimeService
- **Create**: `app/Services/Market/MarketRegimeService.php`
- **Methods**:
  - `execute(): void` — Main entry point
  - `detectBtcStructure($candles): array` — HH/LL detection
  - `calculateEmaSlope($prices): float`
  - `calculateVolatility($data): array`
  - `detectRegime(): string`
  - `calculateMarketStrength(): string`
  - `calculateRiskLevel(): string`
  - `persistToRedis($context): void`

- **Output**: MarketRegimeDTO
  - `market_regime`, `volatility`, `btc_direction`
  - `market_strength`, `risk_level`
  - Redis key: `market_context:latest` (TTL: 300s)

- **Testing**:
  - Unit test: regime detection logic
  - Integration test: Redis persistence
  - Manual test: live BTC data

### Task 1.3: Create MarketRegimeJob
- **Create**: `app/Jobs/MarketRegimeJob.php`
- **Schedule**: `*/5 * * * *` (every 5 min)
- **Dispatch**: FetchMarketJob → MarketRegimeJob

- **Testing**:
  - Job dispatches correctly
  - Redis updates every 5 min
  - Execution ID traces job

### Task 1.4: Create CoinUniverseService
- **Create**: `app/Services/Market/CoinUniverseService.php`
- **Methods**:
  - `getCandidates(): Collection`
  - `validateCoin($coin): bool`
  - `fetchFromBinance(): Collection`
  - `cacheToRedis($coins): void`

- **Filters**:
  - Market cap > $100M
  - Volume (24h) > $5M
  - Open Interest > $1M
  - No stablecoins
  - Max 100 coins

- **Data sources**:
  - CoinGecko, Binance, Coinalyze (public APIs only)

- **Output**: Redis key `coin_universe:main` (TTL: 86400s)

### Task 1.5: Create UpdateCoinUniverseJob
- **Create**: `app/Jobs/UpdateCoinUniverseJob.php`
- **Schedule**: `0 0 * * *` (daily @00:00)

- **Testing**:
  - Job runs daily
  - Cache refreshes correctly
  - Filtering logic validated

### Task 1.6: Create config/models.php
- **Create**: Comprehensive model configuration
- **Sections**:
  - `counter_trend` — Model 1 scoring, thresholds
  - `pre_pump` — Model 2 scoring, thresholds
  - `momentum` — Model 3 scoring, thresholds
  - `market_regime` — Regime settings
  - `coin_universe` — Filtering settings
  - `ai` — Ollama config, confidence adjusters
  - `notifications` — Telegram settings

- **Verification**: All config values accessible via `config('models.*')`

### Phase 1 Deliverables:
- [ ] MCPService renamed → SignalPreFilterService
- [ ] MarketRegimeService complete + tested
- [ ] CoinUniverseService complete + tested
- [ ] Both services job-enabled + scheduled
- [ ] config/models.php complete
- [ ] All unit/integration tests passing

---

## PHASE 2: Model Services (Weeks 3-4)

### Task 2.1: Create CounterTrendModelService
- **Create**: `app/Services/Market/Models/CounterTrendModelService.php`
- **Methods**:
  - `evaluateCoin($coin, $indicators): Signal | null`
  - `detectLiquiditySweep($candles): array`
  - `detectMarketStructureShift($candles): bool`
  - `calculateCVDDivergence($trades): bool`
  - `calculateComponentScores(...): array`
  - `rankTopCoins($signals): Collection`

- **Scoring**: 30% sweep + 25% MSS + 15% OI + 15% CVD + 10% funding + 5% ATR
- **Action**: UP sweep → BUY, DOWN sweep → SELL
- **Output**: Top 10 Signal objects

### Task 2.2: Create PrePumpModelService
- **Create**: `app/Services/Market/Models/PrePumpModelService.php`
- **Logic**: Funding < -0.05% + OI expansion + ATR compression
- **Scoring**: 35% funding + 25% ATR + 20% OI + 10% RS + 10% CVD
- **Action**: All aligned → BUY, easing → SELL

### Task 2.3: Create MomentumModelService
- **Create**: `app/Services/Market/Models/MomentumModelService.php`
- **Logic**: Price > EMAs, MACD positive, RSI 50-65, 3+ HH
- **Scoring**: 25% EMA + 20% MACD + 15% RSI + 20% OI + 10% BOS + 10% CVD
- **Action**: Bullish aligned → BUY, bearish aligned → SELL

### Task 2.4: Create CounterTrendJob
- **Create**: `app/Jobs/CounterTrendJob.php`
- **Schedule**: `*/15 * * * *` (every 15 min)
- **Flow**: Fetch coins → Evaluate → Top 10 → Return Signal collection

### Task 2.5: Create PrePumpJob
- **Create**: `app/Jobs/PrePumpJob.php`
- **Schedule**: `*/30 * * * *` (every 30 min)

### Task 2.6: Create MomentumJob
- **Create**: `app/Jobs/MomentumJob.php`
- **Schedule**: `0 * * * *` (every hour)

### Task 2.7: Register Job Scheduling
- Update `app/Console/Kernel.php` to schedule all jobs
- Jobs must run in PARALLEL (not chained)
- Models must read from centralized data-fetcher/Redis cache, not call external APIs directly

### Phase 2 Verification:
- [ ] Unit tests: Each model scoring logic
- [ ] Integration tests: Model evaluation on sample data
- [ ] Manual tests: Live data verification
- [ ] Jobs scheduled correctly
- [ ] Jobs can run independently

---

## PHASE 3: AI & Notifications (Weeks 5-6)

### Task 3.1: Create Per-Model AI Layer
- **Create**: `app/Services/AI/PerModelAiLayer.php`
- **Methods**:
  - `interpret($signal, $marketRegime): AIDecision`
  - `buildPrompt($signal, $regime): string` — Model-specific
  - `adjustConfidence($base, $regime, $action): int`
  - `callOllama($prompt): array`

- **Prompt Builders** (per model):
  - Counter-Trend: "Reversal setup in [regime] context..."
  - Pre-Pump: "Squeeze setup in [regime] context..."
  - Momentum: "Trend continuation in [regime] context..."

- **Confidence Adjusters** (per model):
  ```
  counter_trend:
    TRENDING_UP → 0.83 (risky, reduce)
    CHOPPY → 0.70 (very risky)
  pre_pump:
    TRENDING_UP → 1.15 (boost, breakout likely)
    CHOPPY → 0.75 (risky)
  momentum:
    TRENDING_UP → 1.10 (aligned)
    CHOPPY → 0.70 (risky)
  ```

### Task 3.2: Integrate AI into Model Jobs
- Update CounterTrendJob: Call AI if `config('models.counter_trend.ai_enabled')`
- Update PrePumpJob: Same
- Update MomentumJob: Same
- Optional: AI can be disabled per model via config flag

### Task 3.3: Update SignalPersistenceService
- Add columns to `ai_decisions` table (migration):
  - `model` (enum: counter_trend, pre_pump, momentum)
  - `market_regime` (JSON)
  - `ai_decision` (JSON: action, confidence, reasoning, agreement)

- **Persistence**: Each model job calls persistence after AI layer

### Task 3.4: Create Per-Model NotificationService
- **Create**: `app/Services/Notification/PerModelNotificationService.php`
- **Methods**:
  - `notifyTopCoins($model, $signals, $marketRegime): void`
  - `formatMessage($signal, $model, $regime): string`
  - `filterByThreshold($signals, $threshold): Collection`
  - `sendTelegram($message): void`

- **Message Format** (per model):
  ```
  🔄 COUNTER-TREND MODEL — BUY Signal
  📊 ETHUSDT | 4H
  Confidence: 72%
  Setup: Liquidity sweep + MSS + declining OI
  Market: TRENDING_UP (reversal = risky)
  ```

- **Trigger**: action == BUY|SELL + confidence >= threshold

### Task 3.5: Integrate Notifications into Model Jobs
- Update CounterTrendJob: Send notification after persistence
- Update PrePumpJob: Same
- Update MomentumJob: Same

### Phase 3 Verification:
- [ ] Unit tests: AI confidence adjustment logic
- [ ] Unit tests: Notification formatting
- [ ] Integration tests: End-to-end job → notification
- [ ] Manual tests: Telegram message format
- [ ] AI layer enabled/disabled correctly

---

## PHASE 4: Integration & Testing (Weeks 7-8)

### Task 4.1: Update Filament Dashboard
- Add per-model signal views (3 separate tables)
- Add market regime indicator (status card)
- Add execution history by model
- Add consensus coins (appearing in multiple models)
- Add highest conviction signals (confidence > 85%)

### Task 4.2: Comprehensive Unit Tests
- MarketRegimeService: Regime detection edge cases
- CounterTrendModelService: Scoring logic validation
- PrePumpModelService: Threshold edge cases
- MomentumModelService: Structure detection
- AI Layer: Confidence adjustment formula
- Per-Model Notifications: Message formatting

### Task 4.3: Integration Tests
- Full pipeline: Job dispatch → signal generation → persistence
- Redis communication: market_regime → model consumption
- Parallel execution: Verify 3 jobs run independently
- Notification: Verify per-model labeling

### Task 4.4: Manual Testing
- **Live Data**:
  - Run 3 jobs with live Binance/CoinGecko data
  - Verify signals match strategy spec
  - Verify Top 10 differs per model
  - Verify AI adjustments make sense

- **Dashboard**:
  - View per-model signals
  - View market regime indicator
  - Filter by model, confidence, action

- **Telegram**:
  - Receive notifications per model
  - Verify labeling (MODEL: X)
  - Verify confidence displayed

- **Performance**:
  - Measure parallel job latency vs sequential
  - Verify Redis caching reduces API calls

### Task 4.5: Backtesting
- Generate signals for past 30 days
- Compare model outputs (verify differences)
- Verify execution_id traceability
- Document findings

### Task 4.6: Documentation
- API spec per model (input/output/methods)
- Configuration guide (config/models.php walkthrough)
- Operational runbook (scheduling, monitoring, troubleshooting)
- Strategy implementation notes (mapping spec → code)

### Phase 4 Deliverables:
- [ ] Filament dashboard updated
- [ ] Unit tests: 90%+ coverage
- [ ] Integration tests: Full pipeline validated
- [ ] Manual testing: Live data verified
- [ ] Backtesting: 30-day history generated
- [ ] Documentation: Complete and actionable

---

## Success Metrics (All Phases)

### Functional
- [ ] 3 models generate independent Top 10 lists
- [ ] Market regime broadcasts every 5 min
- [ ] AI layer optional, can be toggled per model
- [ ] Per-model notifications with clear labeling
- [ ] Full execution_id traceability

### Performance
- [ ] Parallel jobs (15m, 30m, 1h) reduce latency
- [ ] Independent job failure/retry possible
- [ ] Market regime Redis cache (5m)
- [ ] Coin universe Redis cache (24h)
- [ ] No direct model-to-external API calls

### Quality
- [ ] SRP maintained (each service = 1 responsibility)
- [ ] Framework best practices followed
- [ ] 90%+ test coverage
- [ ] No breaking changes to existing system