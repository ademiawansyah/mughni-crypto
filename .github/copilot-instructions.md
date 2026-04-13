# AI Trading Advisor — Copilot Instructions

## Project Overview

This is a Laravel-based AI-assisted crypto trading advisor system.

Core responsibilities:

* Fetch and store CoinGecko market data (raw + processed)
* Calculate indicators (RSI, EMA)
* Generate AI-based trading signals via LM Studio
* Log all decisions and trades
* Provide dashboard via Filament

---

## Architecture Principles

* Controllers must be thin (only orchestration)
* All business logic must live in Service classes under `App\Services`
* Use Jobs for async/background processing
* Models are only for data representation (no heavy logic)
* Keep code modular and testable

---

## Folder Structure Rules

* Market logic → `App\Services\Market`
* Indicator logic → `App\Services\Indicator`
* AI logic → `App\Services\AI`
* Trading logic → `App\Services\Trading`
* Notification → `App\Services\Notification`
* External integrations → `App\Services\External`

---

## Coding Guidelines

* Use dependency injection (constructor-based)
* Avoid static calls unless necessary
* Prefer small, focused methods
* Do not mix responsibilities in one class
* Avoid business logic in controllers or jobs

---

## Data Handling Rules

* ALWAYS store raw CoinGecko API response (JSON) without modification
* Processed indicator data must be stored separately
* Never overwrite historical data

---

## AI Integration Rules

* AI is advisory only (never directly execute trades)
* AI responses must be validated before use
* Output must follow strict JSON format

---

## Indicator Rules

* Start with RSI and EMA only
* Keep implementation simple and readable
* Prepare for future external Python integration

---

## Future-Proofing

* IndicatorService must support switching to external Python service
* Do not tightly couple indicator logic with Laravel-only implementation

---

## Performance

* Use queues for heavy tasks
* Avoid blocking HTTP requests
* Cache frequently accessed data if needed

---

## What to Avoid

* Fat controllers
* Direct DB queries in controllers
* Mixing raw and processed data
* Overengineering (keep Phase 1 simple)

---

## Preferred Style

* Clear naming: `MarketDataService`, `AiAdvisorService`, etc.
* Explicit over implicit
* Readability over cleverness
