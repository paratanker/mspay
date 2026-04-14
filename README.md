# MSPay – Payment Pipeline Simulation (Coding Exercise)

A Laravel-based CLI application that simulates a payment processing pipeline. It supports **stdin or file-based command execution**, configurable currencies, pre-settlement review rules, and pluggable persistence (database or in-memory).

The full specification and evaluation rules are defined in:
📄 `[payment_pipeline_simulation_test.md](payment_pipeline_simulation_test.md)`

This document focuses on **installation, usage, configuration, testing, and design notes**.

---

## 📌 Requirements (Summary)

This application implements the payment lifecycle described in the specification:

### 🔄 Payment States
- `INITIATED`
- `AUTHORIZED`
- `PRE_SETTLEMENT_REVIEW`
- `CAPTURED`
- `SETTLED`
- `VOIDED`
- `REFUNDED`
- `FAILED`

### 🧾 Supported Commands
- `CREATE`
- `AUTHORIZE`
- `CAPTURE`
- `VOID`
- `REFUND`
- `SETTLE`
- `SETTLEMENT`
- `STATUS`
- `LIST`
- `AUDIT`
- `EXIT`

### ⚙️ Core Behaviors
- Input via **stdin (interactive)** or **file argument**
- Processing continues even after invalid commands (no stack traces shown)
- **Idempotent CREATE** (same attributes)
- **Idempotent SETTLE**
- `SETTLEMENT` is **report-only (no state changes)**
- `AUDIT` is non-mutating
- Inline comments supported only when `#` appears **after the 3rd token**

---

## 🧰 Prerequisites

- 🐘 PHP **8.3+**
- 📦 Composer **2.x**
- 🗄️ Database (MySQL / MariaDB / SQLite) if using `database` storage driver

---

## 🚀 Installation

### 1️⃣ Clone Repository
```bash
git clone git@github.com:paratanker/mspay.git mspay
cd mspay
```

### 2️⃣ Install Dependencies
```bash
composer install
```

### 3️⃣ Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

### 4️⃣ Configure Database (if using DB storage)
Update `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=mspay
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations:
```bash
php artisan migrate
```

💡 For SQLite:
```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/database.sqlite
```

---

### 5️⃣ Optional Setup Script
```bash
composer run setup
```

---

## ▶️ Running the Simulation

### 🖥️ Interactive Mode (stdin)
```bash
php artisan payments:simulate
```

Exit using:
- `EXIT`
- or EOF (Ctrl+D / Ctrl+Z)

---

### 📄 File Input Mode
```bash
php artisan payments:simulate resources\simulation\command.txt
```

Each line represents a command (see spec for syntax).

---

## ⚙️ Configuration

Pipeline-related settings are driven by **environment variables** (see `.env.example` and `config/payment_pipeline.php`):


| Variable                                | Purpose                                                                                                                                            |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PAYMENT_PIPELINE_SUPPORTED_CURRENCIES` | JSON array of 3-letter codes, e.g. `["MYR","USD"]`. Empty or invalid values fall back to built-in defaults.                                        |
| `PAYMENT_PIPELINE_REVIEW_THRESHOLD`     | JSON object of **minor-unit** thresholds per currency, e.g. `{"MYR":1000000,"USD":1000000}`. Used to decide **pre-settlement review** (see below). |
| `PAYMENT_PIPELINE_STORAGE_DRIVER`       | `database` (default) or `in_memory` (no DB needed for the simulation run).                                                                         |
| `PAYMENT_PIPELINE_LOG_LEVEL`            | Defined in `config/payment_pipeline.php` (default `info`); optional hook if you wire pipeline-specific logging.

---

## 💰 Amount Handling (Minor Units)

The exercise requires a **positive decimal amount** with **fixed precision** and explicitly says to **avoid floating-point errors**. Commands still accept human-readable decimals (for example `10.00`), but the domain stores and compares amounts as **integers in the smallest currency unit** (minor units: cents, sen, and so on). That keeps arithmetic exact, makes `CREATE` idempotency and refund limits reliable, and is why review **thresholds** in configuration are expressed in **minor units**. Output continues to format amounts as decimals using each currency’s decimal places.

- Input: decimals (e.g. 10.00)
- Storage: integer minor units
- Thresholds: minor units
- Output: formatted decimals

---

## 🧪 PRE_SETTLEMENT_REVIEW (assumption)

After `AUTHORIZE`, a payment may enter `PRE_SETTLEMENT_REVIEW` instead of `AUTHORIZED` when its amount in **minor units** is **greater than or equal to** the configured threshold for that currency. Thresholds come from defaults merged with `PAYMENT_PIPELINE_REVIEW_THRESHOLD`. If thresholds are unset or not applicable, authorization goes straight to `AUTHORIZED`. This matches the exercise’s expectation to document a reasonable, configurable rule.

If:
amount >= threshold → PRE_SETTLEMENT_REVIEW  
else → AUTHORIZED

---

## 💸 Partial Refunds

Optional `REFUND <payment_id> <amount>` is supported: amounts are validated for currency precision and must not exceed the original capture amount. Behavior is documented here for reviewers; the domain still uses a single refunded state as allowed by the spec.

- `REFUND <payment_id> <amount>`
- Must not exceed captured amount
- Simplified refund state model

---

## 🔁 SETTLE vs SETTLEMENT

### SETTLE

- `**SETTLE <payment_id>`** moves a single payment `CAPTURED → SETTLED` (idempotent if already `SETTLED`).

- CAPTURED → SETTLED
- Idempotent

### SETTLEMENT

- `**SETTLEMENT <batch_id>`** records a batch acknowledgement and may summarize settled payments; it does **not** change individual payment states.

- Batch/report only
- No state changes

---

## 🧪 Testing

```bash
php artisan test
```

or

```bash
composer test
```


The exercise asks for coverage of happy paths, invalid transitions, idempotency, and parser edge cases; see `tests/` (e.g. `tests/Feature/Console/PaymentPipelineSimulationCommandTest.php`).

---

## 🏗️ Architecture Overview

- **Parsing** (`App\Domain\Payments\Parsing`): command lines and inline-comment rules separated from business logic.
- **Domain** (`App\Domain\Payments\Model`, `PaymentStateMachine`): state transitions and invariants.
- **Application/service** (`PaymentPipelineEngine`): orchestrates parse → dispatch → persist.
- **Persistence** (`App\Domain\Payments\Repository`): database or in-memory implementations selected by `PAYMENT_PIPELINE_STORAGE_DRIVER`.

This separation is intended to make new commands or states easier to add without entangling I/O and rules.

---

## 🏭 Production Improvements

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
