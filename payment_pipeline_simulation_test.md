# Payment Pipeline Simulation – Coding Exercise

## Overview

This exercise asks you to build a **command-line application** that simulates a simplified payment processing pipeline.

The purpose of the test is to understand how you:

- Model a domain and its state transitions  
- Structure and design code for extensibility and clarity  
- Handle input, output, edge cases and errors  
- Write tests and reason about correctness  

You are expected to treat this as you would a production-quality internal tool: clarity, robustness and maintainability matter.

You may use any programming language, as long as we can run the solution easily (e.g. via a simple CLI command or a small container).

---

## Domain Model

Your application simulates payments transitioning through a small lifecycle.

### Payment States

Each payment can be in one of the following states:

- `INITIATED`  
- `AUTHORIZED`  
- `PRE_SETTLEMENT_REVIEW` *(optional internal review stage)*  
- `CAPTURED`  
- `SETTLED`  
- `VOIDED`  
- `REFUNDED`  
- `FAILED`

Not all states must be visited in every flow.

You should treat `PRE_SETTLEMENT_REVIEW` as an **internal, system-only** stage that may or may not occur between `AUTHORIZED` and `CAPTURED`. The exact business rules for when it occurs are intentionally not fully specified; you should choose a reasonable approach and document your decision.

> **Note:** In real-world card payment systems, some of these names may have different or more precise meanings. For this exercise, please follow the rules defined in this document, and clearly call out any intentional deviations you make for domain-correctness in your README.

---

## Commands

Your application must read and process commands from **stdin** and/or from a text file.

Each line represents a single command.

Commands are **case-sensitive** and must be one of the following:

```text
CREATE <payment_id> <amount> <currency> <merchant_id>
AUTHORIZE <payment_id>
CAPTURE <payment_id>
VOID <payment_id> [reason_code]
REFUND <payment_id> [amount]
SETTLE <payment_id>
SETTLEMENT <batch_id>
STATUS <payment_id>
LIST
AUDIT <payment_id>
EXIT
```

Lines MAY contain inline comments beginning with `#`, but **only if** the `#` appears **after the third argument token** on that line. In all other positions, the `#` character must be treated as a normal character and **not** as a comment delimiter.

For example:

- `CREATE P1001 10.00 MYR M01 # test payment` → valid; comment starts after 4th token.  
- `# CREATE P1002 11.00 MYR M01` → this is **not** a comment; it should be treated as a malformed command line.  
- `AUTHORIZE P1001 # retry` → valid.  

Your parser’s behavior here will be part of the evaluation.

### Command Semantics

#### 1. `CREATE <payment_id> <amount> <currency> <merchant_id>`

- Creates a new payment with:
  - unique `payment_id`  
  - positive decimal `amount` (fixed precision, avoid floating-point errors)  
  - 3-letter `currency` (e.g. `MYR`, `USD`)  
  - non-empty `merchant_id`  
- Initial state MUST be `INITIATED`.

**Idempotency rule:**

- If a `CREATE` is issued for an existing `payment_id` with **exactly the same** `amount`, `currency`, and `merchant_id`, it must be treated as **idempotent** (no change, no error).
- If a `CREATE` is issued for an existing `payment_id` with values that differ in **any** of `amount`, `currency`, or `merchant_id`, you must:
  - mark the existing payment as `FAILED`, with a reason indicating a create conflict, and  
  - reject the new `CREATE` request with an error message.

Implement this behavior explicitly.

#### 2. `AUTHORIZE <payment_id>`

- Valid transition: `INITIATED → AUTHORIZED`  
- If the payment is not in `INITIATED`, you must reject the command with an error.

You may choose to (optionally) route some payments into `PRE_SETTLEMENT_REVIEW` immediately after authorization, but you must document the rule you chose (for example, based on merchant ID, amount threshold, or currency).

#### 3. `CAPTURE <payment_id>`

- Valid transitions:
  - `AUTHORIZED → CAPTURED`  
  - `PRE_SETTLEMENT_REVIEW → CAPTURED` *(if you use that intermediate state)*  
- Any other state must result in a rejected command with an error message.

#### 4. `VOID <payment_id> [reason_code]`

- Valid transitions:
  - `INITIATED → VOIDED`  
  - `AUTHORIZED → VOIDED`  
- A `VOID` MUST be rejected if the payment is already `CAPTURED`, `SETTLED`, `REFUNDED`, `VOIDED` or `FAILED`.

You may accept an optional `reason_code` and store it for reporting, but this is not required.

#### 5. `REFUND <payment_id> [amount]`

- Valid base transition: `CAPTURED → REFUNDED`.  
- If an `amount` is provided, you may treat this as a **partial refund**, but you are not required to model partial balances; a simple “refunded vs not refunded” boolean is sufficient as long as your behavior is consistent and documented.
- If the payment is in `INITIATED`, `AUTHORIZED`, `VOIDED`, `FAILED`, or `REFUNDED`, you must reject the command.

> **Domain note:** In real systems, a refund on a non-captured payment doesn’t make sense. You should **not** implement any transitions from `REFUNDED` back to `AUTHORIZED` or `CAPTURED`, even though some legacy systems approximate this by creating a new authorization under the hood. For this exercise, such behavior is explicitly out of scope.

#### 6. `SETTLE <payment_id>`

- Intended to represent end-of-day / batch settlement for a captured payment.
- Valid transitions:
  - `CAPTURED → SETTLED`  
  - `SETTLED` may receive additional `SETTLE` commands and should be treated as idempotent.
- `SETTLE` on any non-`CAPTURED`/`SETTLED` state must be rejected.

#### 7. `SETTLEMENT <batch_id>`

This command simulates a **reporting-level** settlement acknowledgement, not a per-payment state change.

- `SETTLEMENT` SHOULD:
  - record that a symbolic batch with ID `batch_id` has been processed, and  
  - optionally produce a summary of payments that are currently in the `SETTLED` state.
- `SETTLEMENT` must **not** directly change the state of any individual payment.

> The distinction between `SETTLE` and `SETTLEMENT` is deliberate. Reviewers will look at how clearly you separate per-payment state transitions from higher-level reporting operations.

#### 8. `STATUS <payment_id>`

- Prints the current known state (and optionally key metadata) of the given payment.  
- If the payment is unknown, show a clear message.

Example output (suggestion, not mandated):

```text
P1001 INITIATED 10.00 MYR M01
```

#### 9. `LIST`

- Outputs a list or table of all payments and their current states.
- The format is up to you, but choose something readable and consistent.

#### 10. `AUDIT <payment_id>`

This command must be **accepted syntactically** but must have **no effect on the state** of any payment.

You may:

- Log internally that an audit was requested, or  
- Print a fixed response such as `AUDIT RECEIVED`  

…but **you must not**:

- Change the payment state  
- Derive or infer any new state from auditing  
- Create, void, refund, settle, or otherwise mutate payments as a result of `AUDIT`.

Implementations that attach business logic to `AUDIT` beyond acknowledgement will be considered incorrect.

#### 11. `EXIT`

- Terminates the application cleanly.
- You may assume EOF / Ctrl-C are also acceptable ways to exit; this command simply provides an explicit option.

---

## Input & Output

- The application should accept commands from:
  - Standard input (interactive mode), and/or  
  - A file supplied as an argument.  
- The application must **not** exit automatically after a single error or a single successful command.
- Errors must be handled gracefully, with human-readable messages.
- Do not print raw stack traces.

You are free to choose the exact output format, as long as it is:

- Deterministic  
- Machine-parsable with reasonable effort  
- Consistently applied across all commands  

---

## Constraints & Expectations

### Correctness

Your application must:

- Enforce the described state transitions  
- Respect idempotency rules for `CREATE` and `SETTLE`  
- Reject invalid transitions with clear errors  
- Ensure `AUDIT` has no side effects on payment state  
- Keep behavior consistent between file input and interactive mode  

Edge cases and malformed input should not crash the program.

### Design & Architecture

We will look for:

- Clear separation between:
  - parsing  
  - domain/model logic  
  - state management  
  - input/output concerns  
- Sensible modeling of payment and state machine logic  
- Reasonable use of patterns (OO or functional) appropriate to your chosen language  
- Low coupling and high cohesion  
- A codebase that feels easy to extend with new commands or states

### Configuration

We should not have to edit source code to:

- Change currencies to support  
- Adjust certain behaviors (e.g. default logging verbosity, simple thresholds you use for `PRE_SETTLEMENT_REVIEW`)  

Configuration can be via environment variables, config file, or CLI arguments. Use your judgment and document the approach.

---

## Performance & Scale

You should assume that the application may be run with:

- Thousands of commands in a single input file  
- Multiple `CREATE` / `AUTHORIZE` / `CAPTURE` / `SETTLE` / `REFUND` operations

Your solution should not:

- Become unresponsive  
- Use obviously inefficient algorithms (e.g. repeated full scans where a map/dictionary is more appropriate)

---

## Error Handling

- Input errors (malformed lines, unknown commands, invalid arguments) should result in:
  - a clear error message, and  
  - continued processing of the next line.
- State errors (invalid transitions) should similarly:
  - emit a clear error, and  
  - leave existing state unchanged.
- Unexpected internal errors should **not** show raw stack traces to the user.

---

## Testing

You should include tests that cover at least:

- Happy-path flows:
  - `CREATE → AUTHORIZE → CAPTURE → SETTLE → STATUS`  
  - `CREATE → AUTHORIZE → VOID`  
  - `CREATE → AUTHORIZE → CAPTURE → REFUND`  
- Invalid transitions:
  - `REFUND` before `CAPTURE`  
  - `CAPTURE` before `AUTHORIZE`  
  - `VOID` after `CAPTURE`  
- Idempotency:
  - Repeated `CREATE` with identical attributes  
  - Repeated `SETTLE` on an already-settled payment  
- Parser behavior:
  - Inline comments only treated as comments after the third token  
  - `#` at beginning of line treated as part of the command and handled as malformed input  

Unit tests and integration tests are both welcome.

---

## Deliverables

Please provide:

- Source code  
- Test code  
- Instructions on how to build and run the application  
- Any configuration or environment setup needed  
- A short README describing:
  - Your design  
  - Any assumptions you made, especially around:
    - `PRE_SETTLEMENT_REVIEW`  
    - Partial refunds (if you chose to support them)  
  - Anything you would do differently for a production system  

---

## Self-Assessment Checklist

Before submitting, you may want to check:

- Is the payment state machine clearly modeled and easy to change?  
- Can I add a new command (e.g. `CHARGEBACK`) with minimal modifications?  
- Is my parsing logic decoupled from business rules?  
- Does `AUDIT` respect the “no side effects” requirement?  
- Do I correctly distinguish `SETTLE` (per payment) from `SETTLEMENT` (batch/reporting-level)?  
- Is the behavior of inline `#` comments exactly as specified?  
- Do I avoid raw stack traces and instead provide clear, user-friendly errors?  
- Would I be comfortable maintaining this code in a real codebase?
