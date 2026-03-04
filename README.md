# My Laravel Production Code Samples

Extracted and lightly modified from production Laravel applications I've built and maintain, to remove proprietary business logic and client-identifying details. The architecture, patterns, and approach are faithful to what's running in production.

> **Note:** These are curated extracts, not a runnable application. They demonstrate how I structure, test, and reason about production Laravel code.

## Start Here

| File | What it shows |
|------|--------------|
| [`PayoffCalculator.php`](app/Services/PayoffCalculator/PayoffCalculator.php) | Engine orchestration — static factory, strategy injection, simulation loop |
| [`BasePayoffStrategy.php`](app/Services/PayoffCalculator/Strategies/Payoff/BasePayoffStrategy.php) | Template Method pattern — shared payment loop with strategy-specific prioritization |
| [`PayableDebt.php`](app/Services/PayoffCalculator/PayableDebt.php) | Decorator pattern wrapping Eloquent models with payment simulation behavior |
| [`ResourceBaseModel.php`](app/Models/StateMachine/ResourceBaseModel.php) | Custom Eloquent model events extending Laravel's event system for domain workflows |
| [`PayoffStrategyBehaviorTest.php`](tests/Feature/Services/PayoffCalculator/PayoffStrategyBehaviorTest.php) | Behavior-focused tests — verifies strategy outcomes, not implementation details |
| [`StatementService.php`](app/Services/Statement/StatementService.php) | Real-world complexity — DTOs, interface-driven PDF generation, financial data aggregation |

---

## Payoff Calculator Engine

`app/Services/PayoffCalculator/`

A queue-driven financial calculation engine that simulates debt payoff scenarios using interchangeable algorithms.

- **Strategy pattern for business flexibility** — The product regularly experimented with new payoff approaches. New algorithms are added by creating a single class; the [factory](app/Services/PayoffCalculator/Factories/PayoffFactory.php) auto-discovers strategies by naming convention (`snake_case` → `PascalCase` class resolution).
- **Decorator over inheritance** — [`PayableDebt`](app/Services/PayoffCalculator/PayableDebt.php) wraps Eloquent models to add payment simulation without polluting the domain model; clones via `replicate()` to keep source data immutable.
- **Excel-equivalent financial math** — [`Finance.php`](app/Services/PayoffCalculator/Finance.php) implements PMT, PV, FV, NPER from scratch. The app needed Excel-parity for users migrating from spreadsheets, and no reliable PHP library existed.
- **Typed collection for domain filtering** — [`DebtCollection`](app/Services/PayoffCalculator/DebtCollection.php) extends Eloquent's Collection with type enforcement and domain methods like `unpaid()`.
- **Async via queue jobs** — [`CalculatePayoff`](app/Jobs/CalculatePayoff.php) dispatches calculations to a dedicated queue, running simulations against both the user's chosen strategy and a baseline for comparison.

## Statement & PDF Generation

`app/Services/Statement/`

A service for generating monthly financial statements with PDF output.

- **Interface-driven PDF generation** — [`StatementPdfGenerator`](app/Services/Statement/Contracts/StatementPdfGenerator.php) contract with a [production implementation](app/Services/Statement/PdfgPdfGenerator.php) (external API) and a [test stub](app/Services/Statement/StubPdfGenerator.php). The real implementation calls an external PDF API, so the interface provides a testable seam without HTTP calls.
- **Readonly DTOs for clean boundaries** — [`Statement`](app/Services/Statement/DataTransferObjects/Statement.php) and [`StatementSummary`](app/Services/Statement/DataTransferObjects/StatementSummary.php) use PHP 8.2 `readonly` classes for type-safe data transfer between service, view, and PDF layers.
- **Real-world data integrity** — [`calculateNewDebtSinceStart()`](app/Services/Statement/StatementService.php) detects user-initiated balance changes by comparing month-over-month starting vs. ending balances, distinguishing normal payments from manual edits.

## Resource State Machine

`app/Models/StateMachine/`, `app/Services/StateMachine/`, `app/Events/StateMachine/`

A database-driven state machine for managing entity lifecycles through role-based approval workflows in a multi-tenant system.

- **Database-driven transitions, not hardcoded** — Valid transitions defined as `(resource_type, current_state, actor_role, action) → next_state`. New resource types or workflows added by inserting rows, no code deploys needed.
- **Custom Eloquent model events** — [`ResourceBaseModel`](app/Models/StateMachine/ResourceBaseModel.php) extends Laravel's event system with domain events (`submitted`, `approved`, `rejected`, `paid`) fired via `fireModelEvent()`.
- **Centralized event routing** — [`ResourceEventSubscriber`](app/Listeners/StateMachine/ResourceEventSubscriber.php) funnels all resource events through [`ResourceStateService`](app/Services/StateMachine/ResourceStateService.php), then broadcasts a generic `ResourceStateChanged` event for downstream listeners.
- **Pragmatic problem-solving** — `setState()` uses an `event_id` counter to force Laravel's dirty-checking to recognize same-state transitions, ensuring the PostgreSQL temporal trigger fires for every state change. Evaluated `touch()` as an alternative, chose the simpler single-column approach.
- **Surgical operational control** — `saveVeryQuietly()` bypasses both Laravel events and PostgreSQL temporal triggers for data fixes and migrations that shouldn't generate audit history.

**How it works:**

```
Define transitions in DB:  (resource_type, current_state, actor_role, action) → next_state

Model fires event:         $document->submitResource()
                           → fires 'submitted' model event
                           → SubmittedResource event dispatched

Subscriber handles it:     ResourceEventSubscriber::processChangeState()
                           → ResourceStateService::updateState()
                           → looks up valid transition in DB
                           → updates resource_state table
                           → temporal trigger logs history
                           → broadcasts ResourceStateChanged
```

## Testing

`tests/`

- **Behavior over implementation** — [Strategy tests](tests/Feature/Services/PayoffCalculator/PayoffStrategyBehaviorTest.php) verify which debt pays off first and that extra payments flow correctly, not internal method calls.
- **Reusable test scenarios** — [`PayoffCalculatorBase`](tests/Feature/Services/PayoffCalculator/PayoffCalculatorBase.php) provides named scenario builders (`createTieredInterestScenario`, `createExcessMinPaymentScenario`) for readable, maintainable tests.
- **Data providers for edge cases** — [`FinanceTest`](tests/Unit/Services/PayoffCalculatorService/FinanceTest.php) uses PHPUnit data providers to validate financial math across a range of inputs, boundary conditions, and invalid parameters.

## Stack

- Laravel 12 / PHP 8.2+
- PostgreSQL (with temporal tables for audit history)
- PHPUnit
- Queues (database/Redis driver)
