# 🧠 AI Trading Advisor — Copilot Instructions (v2)

## Project Overview

This project is a Laravel-based AI-assisted crypto trading advisor system.

Core responsibilities:

* Fetch and store CoinGecko market data (raw and processed)
* Calculate technical indicators (RSI, EMA)
* Apply deterministic pre-filtering (MCP layer)
* Generate AI-based signal recommendations
* Apply guardrails and produce final trading decisions
* Log all steps for traceability and backtesting
* Provide monitoring via Filament dashboard

---

## Architecture Principles (MANDATORY)

* Controllers must be thin (orchestration only)
* All business logic must reside in `App\Services`
* Each class must have a single responsibility
* Use Jobs for async/background processing
* Models are for data representation only (no business logic)

### Decision Authority (CRITICAL)

* AI is **advisory only**
* AI produces a **recommendation**, not a decision
* **TradingDecisionService is the single source of truth for final decisions**

---

## Execution Context (MANDATORY)

Every pipeline execution MUST:

* Generate a unique `execution_id`
* Attach `execution_id` to:

  * raw data
  * indicator results
  * AI prompt/response
  * final decision
  * logs

All steps must be traceable per execution.

---

## System Flow (STRICT ORDER)

1. Fetch market data from CoinGecko
2. Store raw API response (unaltered)
3. Process and calculate indicators
4. Apply pre-filter rules (MCP layer)
5. Send shortlisted data to AI service
6. Validate AI response (strict schema)
7. Apply guardrail validation (risk control)
8. Produce final decision
9. Store decision result
10. Trigger notification (if eligible)

**Do not skip, merge, or reorder steps.**

---

## Folder Structure Rules

* Market → `App\Services\Market`
* Indicator → `App\Services\Indicator`
* AI → `App\Services\AI`
* Trading → `App\Services\Trading`
* Notification → `App\Services\Notification`
* External → `App\Services\External`
* MCP → `App\Services\MCP`

---

## Service Responsibilities

### MarketDataService

* Fetch and store raw market data only

### IndicatorService

* Calculate indicators only
* Must NOT call external APIs
* Must be replaceable by external service (future Python migration)

### PreFilterService (MCP Layer)

* Apply deterministic filtering rules
* Output must include:

  * `passed` (true/false)
  * `reason`
  * `score` (optional)

### AiAdvisorService

* Build prompt and call AI (LM Studio / Ollama)
* Return structured recommendation only

### TradingDecisionService (FINAL AUTHORITY)

* Combine:

  * indicator data
  * pre-filter result
  * AI recommendation
* Apply guardrails
* Produce final decision:

  * action
  * confidence
  * decision_status (accepted/rejected)

### NotificationService

* Send notifications only
* No business logic

---

## AI Integration Rules (STRICT)

AI MUST return valid JSON with EXACT structure:

* `action`: BUY | SELL | HOLD
* `confidence`: integer (0–100)
* `type`: scalping | intraday | swing
* `reason`: string

### Validation Rules

* Invalid JSON → reject
* Missing fields → reject
* Invalid values → reject

### Fallback Behavior

* If AI response is invalid:
  → force decision to `HOLD`
  → log error with execution_id

---

## Guardrail Rules (MANDATORY)

Before final decision:

* Reject low-confidence signals
* Reject signals against strong trend (if defined)
* Reject abnormal or missing data

Guardrail outcome must be explicit:

* `accepted` or `rejected`
* include reason

---

## Pre-AI Filtering Rules (MCP)

* Must be deterministic and explainable
* Must reduce dataset before AI call
* Must NOT rely on AI
* If no candidates pass:
  → skip AI
  → produce HOLD decision

---

## Data Handling Rules

* ALWAYS store raw CoinGecko response (unaltered JSON)
* Store processed/indicator data separately
* NEVER overwrite historical data
* All decisions must be reproducible (backtesting-ready)

---

## Duplicate & Idempotency Rules

System MUST prevent duplicate decisions:

* Unique key: `symbol + timeframe + timestamp`
* Do not re-notify or re-store identical signals

---

## Multi-Timeframe Support

* System MUST support multiple timeframes (e.g., 5m, 15m, 1h)
* All services must accept `timeframe` as a parameter
* Do NOT hardcode timeframe logic

---

## Logging Requirements (MANDATORY)

Log EVERYTHING with `execution_id`:

* Raw API response
* Indicator results
* Pre-filter evaluation
* AI prompt & response
* Guardrail result
* Final decision (including rejected)

---

## Notification Rules

Trigger notification ONLY when:

* action = BUY or SELL
* decision_status = accepted
* NOT duplicate

---

## Error Handling

* External API failures must not crash system
* AI failures must fallback safely
* All errors must be logged with execution_id

---

## Performance Guidelines

* Use queues for heavy processing
* Avoid blocking HTTP requests
* Cache when appropriate

---

## Coding Guidelines

* Use constructor-based dependency injection
* Avoid static calls unless necessary
* Keep methods small and explicit
* No hidden transformations
* Prefer clarity over cleverness

---

## Code Documentation (MANDATORY)

Each class must include:

* Clear purpose description

Each public method must include:

* What it does
* Inputs
* Outputs

Complex logic must include reasoning comments.

---

## Docker / Command Rules

* Use Makefile for ALL commands
* Do NOT run PHP/Composer directly on host
* If a command fails due to host/container mismatch, do NOT retry the same failing pattern; switch immediately to Makefile wrapper commands.
* Use `make artisan cmd="..."` for Artisan commands.
* If Pint is unavailable via Artisan (`Command "pint" is not defined.`), use `make composer cmd="exec -- pint --dirty --format agent"`.

Example:

* `make artisan cmd="migrate"`
* `make artisan cmd="make:migration create_table"`

---

## What to Avoid

* Fat controllers
* Business logic in controllers/jobs
* Mixing raw and processed data
* Skipping pipeline steps
* Overengineering (keep Phase 1 simple)

---

## Copilot Behavior Rules

* Follow this document strictly
* Do NOT introduce new architecture
* Do NOT bypass system flow
* When in doubt → choose simplicity, traceability, and explicit logic

---

# 🚀 What Changed (Key Improvements)

* Clear **decision authority** (AI vs system)
* Added **execution_id (traceability backbone)**
* Introduced **guardrail layer**
* Formalized **AI contract + fallback**
* Defined **MCP output structure**
* Added **duplicate protection**
* Enabled **multi-timeframe future**
* Made system **backtesting-ready**
