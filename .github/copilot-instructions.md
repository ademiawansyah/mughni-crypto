# AI Trading Advisor — Copilot Instructions

## Project Overview

This project is a Laravel-based AI-assisted crypto trading advisor system.

Core responsibilities:

* Fetch and store CoinGecko market data (raw and processed)
* Calculate technical indicators (RSI, EMA)
* Generate AI-based trading signals via LM Studio
* Log all decisions and evaluation steps
* Provide a dashboard using Filament

---

## Architecture Principles

* Controllers must be thin (only orchestration)
* All business logic must reside in Service classes under `App\Services`
* Use Jobs for asynchronous and background processing
* Models are strictly for data representation (no heavy logic)
* Each class must have a single responsibility
* Code must be modular, testable, and easy to extend

---

## System Flow (MANDATORY)

All implementations must follow this pipeline:

1. Fetch market data from CoinGecko
2. Store raw API response (unaltered)
3. Process and calculate indicators
4. Apply pre-filter rules (non-AI filtering)
5. Send filtered data to AI service
6. Validate AI response (strict format)
7. Store decision result
8. Trigger notifications if applicable

Do not skip or reorder steps.

---

## Folder Structure Rules

* Market logic → `App\Services\Market`
* Indicator logic → `App\Services\Indicator`
* AI logic → `App\Services\AI`
* Trading decision logic → `App\Services\Trading`
* Notification logic → `App\Services\Notification`
* External integrations → `App\Services\External`

---

## Service Responsibilities

* MarketDataService:

  * Fetch and store market data only

* IndicatorService:

  * Calculate indicators only
  * Must not call external APIs

* AiAdvisorService:

  * Build prompts and communicate with AI (LM Studio)

* TradingDecisionService:

  * Combine indicator results and AI output
  * Produce final decision

* NotificationService:

  * Send notifications only
  * No business logic

---

## Coding Guidelines

* Use constructor-based dependency injection
* Avoid static calls unless absolutely necessary
* Prefer small, focused, and readable methods
* Do not mix multiple responsibilities in one class
* Do not place business logic in controllers or jobs
* Avoid hidden logic and implicit transformations
* Keep all data transformations explicit and traceable

---

## Data Handling Rules

* ALWAYS store raw CoinGecko API responses as JSON without modification
* Store processed/indicator data separately
* Never overwrite historical data
* Ensure all records are traceable for audit purposes

---

## Pre-AI Filtering Rules

Before calling AI:

* Apply basic indicator-based filtering
* Reduce dataset size
* Avoid unnecessary AI calls

AI must only process shortlisted candidates.

---

## AI Integration Rules

* AI is strictly advisory (must never execute trades directly)
* AI responses must be validated before use
* AI output must follow a strict JSON structure
* Invalid AI responses must be rejected and logged

---

## Indicator Rules

* Start with RSI and EMA only
* Keep implementation simple and readable
* Design in a way that allows migration to external Python service

---

## Error Handling

* All external API calls must handle timeouts and failures
* AI responses must be validated (schema and required fields)
* Invalid or failed responses must be logged
* The system must not crash due to external failures

---

## Logging Requirements

The system MUST log:

* Raw API responses
* Indicator calculation results
* AI prompts and responses
* Final decisions (including rejected ones)

All logs must be traceable per execution cycle.

---

## Performance Guidelines

* Use queues for heavy or long-running tasks
* Avoid blocking HTTP requests
* Cache frequently accessed data when appropriate

---

## Future-Proofing

* IndicatorService must support switching to an external Python service
* Do not tightly couple business logic to Laravel-specific implementations
* Keep architecture flexible for multi-service expansion

---

## What to Avoid

* Fat controllers
* Direct database queries in controllers
* Mixing raw and processed data
* Overengineering (keep Phase 1 simple)

---

## Preferred Style

* Use clear and explicit naming (e.g., `MarketDataService`, `AiAdvisorService`)
* Favor readability over clever or complex code
* Keep implementations straightforward and maintainable

---

## Code Documentation & Comments (MANDATORY)

* Every class must include a clear description of its purpose
* Every public method must include a short explanation of:

  * What it does
  * Expected inputs
  * Expected outputs
* Complex logic must include inline comments explaining the reasoning
* Avoid redundant or obvious comments; focus on intent and decision-making
* Write code that is understandable without external explanation

---

## Running Commands (Docker Environment)

* All PHP and Artisan commands must be executed via Makefile
* Do not run PHP or Composer directly on the host machine

Example:

make artisan cmd="migrate"
make artisan cmd="make:migration create_example_table"

---

## General Behavior for Copilot

* Follow all rules in this document strictly
* Do not introduce architecture outside these guidelines
* When in doubt, prefer simplicity and clarity
* Ensure generated code is consistent with the defined structure
