---
kind: code
depends_on: [order-revenue-recognition]
chain:
  - order-revenue-recognition         # head (kind:config) — SalesOrder + SalesOrderLine schemas + seed
  - order-revenue-recognition-engine  # THIS change (kind:code) — RevenueRecognitionService (ADR-031 exception) + read endpoint
  - pipelinq-recognized-recurring-revenue-widget  # downstream (pipelinq repo) — dashboard widget, depends_on this
---

# Change: order-revenue-recognition-engine

## Why

The head change `order-revenue-recognition` (kind: config) declared the `SalesOrder` +
`SalesOrderLine` booking-term schemas and **defined** the recognized-recurring-revenue metric,
but its feasibility investigation proved (high confidence) that OpenRegister's
`x-openregister-aggregations` / `-calculations` grammar **cannot** compute it: the metric is an
interval-overlap proration parameterized by a **runtime** reporting period `[from, to]` chosen on
the dashboard, and the engine has neither (a) a runtime date-window usable inside date-diff
arithmetic (`@params.*` is equality-only) nor (b) an `overlap_days × rate` per-row reducer. The
head therefore split the work into a chain (ADR-032) and deferred the arithmetic to this `code`
change. This change ships that arithmetic as the documented **ADR-031 exception** — a thin
`OCA\Shillinq\Recognition\RevenueRecognitionService` plus the read endpoint the dashboard polls —
so the recognized figure becomes available. Precedent for the exception already lives in this
register: the `EmuCalculator` guard on `emuSaldoByQuarter`.

## What Changes

- **NEW service `OCA\Shillinq\Recognition\RevenueRecognitionService`** — the ADR-031 exception. It
  reads `SalesOrder` / `SalesOrderLine` **objects** via OpenRegister's ObjectService (ADR-022 — no
  app tables, no SQL) and computes, for a runtime period `[from, to]`:
  - **recognized RECURRING revenue** = `Σ over RECURRING lines of monthlyRate(line) × overlapMonths(termOf(line), [from, to])`, with `monthlyRate` normalizing `amount` by `frequentie` (MAANDELIJKS=1, KWARTAALS=1/3, JAARLIJKS=1/12, WEKELIJKS=52/12, TWEEWEKELIJKS=26/12) and **whole-month** overlap (D5 in the head design);
  - **one-off recognition** (computed but reported separately, NOT part of the recurring figure): `POINT_IN_TIME` recognized in full when `recognitionDate ∈ [from, to]`; `OVER_TIME` prorated across the line's term (the term is reused — no separate delivery-window fields);
  - a secondary **ARR** (annualized current monthly rate of in-term recurring lines).
- **NEW thin controller `OCA\Shillinq\Controller\RecognitionController`** (ADR-003) with one
  read method, **`#[NoAdminRequired]`** but **RBAC-guarded per `administrationId`** (ADR-005, no
  IDOR — reads go through OR's ObjectService which enforces multitenancy; the controller validates
  the scope before touching the data layer).
- **NEW route** in `appinfo/routes.php` (ADR-016): `GET /api/recognition/recurring-revenue`
  → `{ "recognized": <number>, "arr": <number>, "currency": "EUR", "lineCount": <n> }`. Design
  notes that a `{ "value": <number> }`-compatible shape would let the abstract OR stat widget
  source it with minimal glue — flagged as a downstream concern for the pipelinq adoption change.
- **PHPUnit tests** for the service (≥4 cases): full-month recurring, mid-month partial overlap
  (whole-month rounding), one-off point-in-time in/out of period, and empty/no-lines → 0.
- **No new schema, no seed, no register edits** — the head ships the data model. This change adds
  only PHP (service + controller + route + tests) and an i18n key for any user-facing string.

## Capabilities

### New Capabilities
<!-- None. The engine realizes the metric already declared by the head's capability. -->

### Modified Capabilities
- `recurring-revenue-recognition`: realizes the previously-deferred recognition requirement — adds
  the normative behaviour of the `RevenueRecognitionService` (overlap proration + frequency
  normalization + one-off split + ARR) and the read endpoint contract. No schema requirement
  changes; the data-model requirements from the head are untouched.

## Impact

- **`lib/Recognition/RevenueRecognitionService.php`** (NEW) — ADR-031 exception service.
- **`lib/Controller/RecognitionController.php`** (NEW) — thin read controller (`#[NoAdminRequired]`,
  per-`administrationId` RBAC).
- **`appinfo/routes.php`** — one new `recognition#recurringRevenue` route entry.
- **`tests/unit/Recognition/RevenueRecognitionServiceTest.php`** (NEW) — ≥4 PHPUnit cases.
- **`l10n/`** — any user-facing string (e.g. an error message) keyed in English (ADR-007).
- **Depends on** `order-revenue-recognition` (head) for the `SalesOrder` / `SalesOrderLine`
  schemas + seed — this change is inert until the head's schemas are merged.
- **Downstream** `pipelinq-recognized-recurring-revenue-widget` (pipelinq repo) consumes the
  endpoint for the dashboard's date range. Chained, NOT in this change.
- **No new external dependencies.** No DB tables, no direct SQL (ADR-022). `CashflowRecurring`
  untouched. Precedent: `EmuCalculator` guard, `RevenueController` read endpoint.
