# MSPay - Payment Pipeline Simulation – Coding Exercise

Payment pipeline simulation: a Laravel-based CLI that processes payment lifecycle commands from **stdin** or a **text file**, with configurable currencies, pre-settlement review thresholds, and database or in-memory persistence.

The full problem statement, semantics, and evaluation criteria are in `[payment_pipeline_simulation_test.md](payment_pipeline_simulation_test.md)`. This README focuses on **how to install, run, configure, and test** the solution, plus a short note on design and assumptions.

## Requirements (summary)

The application implements the exercise described in `payment_pipeline_simulation_test.md`, including:

- Payment states: `INITIATED`, `AUTHORIZED`, `PRE_SETTLEMENT_REVIEW`, `CAPTURED`, `SETTLED`, `VOIDED`, `REFUNDED`, `FAILED`
- Commands: `CREATE`, `AUTHORIZE`, `CAPTURE`, `VOID`, `REFUND`, `SETTLE`, `SETTLEMENT`, `STATUS`, `LIST`, `AUDIT`, `EXIT`
- Input from **stdin** (interactive) or an **optional file path** argument; processing continues after errors (no raw stack traces to the user)
- Idempotent `CREATE` (same attributes) and idempotent `SETTLE` on already-settled payments
- `SETTLEMENT` as batch/reporting only (no per-payment state change)
- `AUDIT` acknowledged without mutating payment state
- Inline `#` comments only when `#` appears **after the third token** on the line (see parser tests)

## Prerequisites

- **PHP** 8.3+
- **Composer** 2.x
- **Database** supported by Laravel (e.g. MySQL/MariaDB, or SQLite for local dev) when using the default `database` storage driver

## Installation

### 1. Clone the repository

```bash
git clone git@github.com:paratanker/mspay.git mspay
cd mspay
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Environment

Copy the example environment file and generate an application key:

```bash
copy .env.example .env
php artisan key:generate
```

On Unix-like shells, use `cp .env.example .env` instead of `copy`.

### 4. Database (for default pipeline storage)

The payment simulation can persist to the database (`payment_pipeline_payments`, `payment_settlement_batches`). Configure your connection in `.env` (see `.env.example` for `DB_*` variables), then run migrations:

```bash
php artisan migrate
```

For a quick SQLite setup you can set `DB_CONNECTION=sqlite` and point `DB_DATABASE` to an absolute path of a database file (Laravel will create it if missing when configured).

### 5. Optional: one-shot setup script

The project includes a Composer `setup` script that installs dependencies, ensures `.env`, runs `key:generate`, migrates, and runs frontend tooling. If you only need the payment CLI, steps 2–4 are enough.

```bash
composer run setup
```

## Running the payment pipeline simulation

The entry point is an Artisan command:

```bash
php artisan payments:simulate
```

- **Interactive (stdin):** run the command with no arguments, then type commands line by line. End input with EOF (e.g. Ctrl+Z then Enter on Windows, or Ctrl+D on Unix) or send `EXIT` to stop cleanly.

**Example (file input):**

```bash
php artisan payments:simulate resources\simulation\command.txt
```

Each line is one command. See `payment_pipeline_simulation_test.md` for exact syntax and semantics.

## Configuration (no code edits required)

Pipeline-related settings are driven by **environment variables** (see `.env.example` and `config/payment_pipeline.php`):


| Variable                                | Purpose                                                                                                                                            |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PAYMENT_PIPELINE_SUPPORTED_CURRENCIES` | JSON array of 3-letter codes, e.g. `["MYR","USD"]`. Empty or invalid values fall back to built-in defaults.                                        |
| `PAYMENT_PIPELINE_REVIEW_THRESHOLD`     | JSON object of **minor-unit** thresholds per currency, e.g. `{"MYR":1000000,"USD":1000000}`. Used to decide **pre-settlement review** (see below). |
| `PAYMENT_PIPELINE_STORAGE_DRIVER`       | `database` (default) or `in_memory` (no DB needed for the simulation run).                                                                         |
| `PAYMENT_PIPELINE_LOG_LEVEL`            | Defined in `config/payment_pipeline.php` (default `info`); optional hook if you wire pipeline-specific logging.                                    |


### Amounts: why minor units (integers) internally

The exercise requires a **positive decimal amount** with **fixed precision** and explicitly says to **avoid floating-point errors**. Commands still accept human-readable decimals (for example `10.00`), but the domain stores and compares amounts as **integers in the smallest currency unit** (minor units: cents, sen, and so on). That keeps arithmetic exact, makes `CREATE` idempotency and refund limits reliable, and is why review **thresholds** in configuration are expressed in **minor units**. Output continues to format amounts as decimals using each currency’s decimal places.

### `PRE_SETTLEMENT_REVIEW` (assumption)

After `AUTHORIZE`, a payment may enter `PRE_SETTLEMENT_REVIEW` instead of `AUTHORIZED` when its amount in **minor units** is **greater than or equal to** the configured threshold for that currency. Thresholds come from defaults merged with `PAYMENT_PIPELINE_REVIEW_THRESHOLD`. If thresholds are unset or not applicable, authorization goes straight to `AUTHORIZED`. This matches the exercise’s expectation to document a reasonable, configurable rule.

### Partial refunds

Optional `REFUND <payment_id> <amount>` is supported: amounts are validated for currency precision and must not exceed the original capture amount. Behavior is documented here for reviewers; the domain still uses a single refunded state as allowed by the spec.

### `SETTLE` vs `SETTLEMENT`

- `**SETTLE <payment_id>`** moves a single payment `CAPTURED → SETTLED` (idempotent if already `SETTLED`).
- `**SETTLEMENT <batch_id>`** records a batch acknowledgement and may summarize settled payments; it does **not** change individual payment states.

## Testing

Run the automated test suite (includes payment pipeline feature tests):

```bash
php artisan test
```

Or:

```bash
composer test
```

The exercise asks for coverage of happy paths, invalid transitions, idempotency, and parser edge cases; see `tests/` (e.g. `tests/Feature/Console/PaymentPipelineSimulationCommandTest.php`).

## Architecture (brief)

- **Parsing** (`App\Domain\Payments\Parsing`): command lines and inline-comment rules separated from business logic.
- **Domain** (`App\Domain\Payments\Model`, `PaymentStateMachine`): state transitions and invariants.
- **Application/service** (`PaymentPipelineEngine`): orchestrates parse → dispatch → persist.
- **Persistence** (`App\Domain\Payments\Repository`): database or in-memory implementations selected by `PAYMENT_PIPELINE_STORAGE_DRIVER`.

This separation is intended to make new commands or states easier to add without entangling I/O and rules.

## Production vs This Exercise

This implementation is intentionally simplified and CLI-driven to match the exercise scope. In a production-grade payment system, I would enhance it in the following areas:

---

### 🔐 1. Idempotency & Delivery Guarantees

- Introduce **explicit idempotency keys for all state-changing operations**, not just creation
- Implement request de-duplication at API and persistence layers
- Clearly define **delivery semantics per operation**:
  - at-least-once processing (with safe retries)
  - or exactly-once effect via idempotency enforcement

---

### 📜 2. Durable Audit Trail (Event Log)

- Maintain an **append-only event/transaction log**
- Capture:
  - who performed the action
  - when it occurred
  - what changed
- Support:
  - debugging and investigation
  - state reconstruction (replay capability)
  - compliance and traceability

---

### 🔄 3. Concurrency & Consistency Control

- Use **transactional updates** to ensure atomic state changes
- Apply **optimistic locking (versioning)** to prevent race conditions
- Safeguard critical transitions such as:
  - CAPTURE
  - REFUND
  - VOID
  - SETTLEMENT

---

### 🗄️ 4. Database Scalability Improvements

- Separate core concerns into dedicated tables:
  - `payments` (current state)
  - `payment_transactions` (actions history)
  - `payment_events` (event log / audit trail)
- Add proper indexing for:
  - `payment_id`
  - `status`
  - `created_at`
- Prepare for horizontal scaling via partitioning if needed

---

### ⚡ 5. Event-Driven Architecture

- Introduce domain events to decouple business logic:
  - `PaymentAuthorized`
  - `PaymentCaptured`
  - `PaymentRefunded`
- Use a queue system for async processing (e.g. notifications, external integrations)
- Improve scalability, resilience, and fault isolation

