---
kind: config
depends_on: []
chain:
  - order-revenue-recognition         # THIS change (head, kind:config) — SalesOrder + SalesOrderLine schemas
  - order-revenue-recognition-engine  # next (kind:code) — RevenueRecognitionService (ADR-031 exception), depends_on this
  - pipelinq-recognized-recurring-revenue-widget  # downstream (pipelinq repo) — dashboard widget, depends_on -engine
---

# Change: order-revenue-recognition

## Why

Shillinq currently has no first-class booking-term model for recurring revenue, so
the only way a dashboard could surface "recurring revenue" was a naive **run-rate**
derived from `CashflowRecurring` (a cashflow-projection entry, not a revenue booking).
A prior `recurring-revenue-mrr-model` change was retired as superseded precisely because
run-rate MRR is the wrong metric: it ignores term boundaries, mixes one-off implementation
fees into "recurring", and cannot be tied back to a signed booking. This change replaces
that approach with **recognized recurring revenue per period** (IFRS 15 / ASC 606 over-time
recognition) sourced from a sales **Order + line** model — the order is the actual booking
term, recognition is prorated to the overlap of each line's term with the reporting period.

## What Changes

- **NEW schema `SalesOrder`** — the booking term (orderDate, termStart, termEnd nullable for
  indefinite, status, currency, klantId, ondernemingId, administrationId, optional `contractId`
  string reference to the legal agreement). The order is the booking; the contract is the legal
  signer. **We do NOT model a full Contract entity** — `contractId` is a plain string reference.
- **NEW schema `SalesOrderLine`** — `orderId` FK, `nature` (enum `RECURRING` | `ONE_OFF`),
  `label`, `amount` (per-interval for recurring, total for one-off), `frequentie`
  (enum `MAANDELIJKS` | `KWARTAALS` | `JAARLIJKS` | `WEKELIJKS` | `TWEEWEKELIJKS`; recurring only),
  `recognitionMethod` (enum `OVER_TIME` | `POINT_IN_TIME`), `termStart` / `termEnd`
  (nullable — inherit the order's term when null), `recognitionDate` (for `POINT_IN_TIME`),
  `accountNumber`. Dutch field names + English schema name per the in-register convention
  (cf. `CashflowRecurring`, `InventoryReorderRule`).
- **Recognition metric (specified here, IMPLEMENTED in the chained `-engine` change):**
  recognized recurring revenue for `[periodFrom, periodTo]` = Σ over `RECURRING` lines of
  `monthlyRate × overlapMonths([line term], [period])`, where `monthlyRate` normalizes
  `amount` by `frequentie` (MAANDELIJKS=1, KWARTAALS=1/3, JAARLIJKS=1/12, WEKELIJKS=52/12,
  TWEEWEKELIJKS=26/12). One-off lines are recognized **separately** (point-in-time at
  `recognitionDate`, or over-time across the line's delivery window) and are NOT counted as
  recurring revenue. ARR / run-rate is a secondary, cheap-to-derive view.
- **BREAKING for the dashboard contract (downstream):** the pipelinq recurring-revenue
  widget stops reading run-rate MRR and starts reading recognized-recurring-revenue for the
  dashboard's date range. `requiresApp: shillinq` is already wired.

This change (`kind: config`) ships **only** the two declarative schemas and their seed data.
The recognition computation is a **MIXED** concern — declarative schema storage PLUS imperative
recognition arithmetic — so per **ADR-032** it is split into a chain (see below). This head
change carries no PHP.

## The chain (ADR-032)

The recognition metric requires interval-overlap proration parameterized by a **runtime**
reporting period (`[@period.from, @period.to]` chosen on the dashboard). The feasibility
investigation in `design.md` concludes the `x-openregister-aggregations` /
`-calculations` grammar **cannot** express this (it prorates only against a *persisted*
period schema such as `CashflowWeek`, and `@params.*` is used for equality filtering, never
for date-diff overlap arithmetic). Recognition is therefore an **ADR-031 exception service**.
A `config`-only schema change plus a `code` recognition service in one envelope is exactly the
`mixed` anti-pattern ADR-032 prohibits, so the work is a chain:

1. **`order-revenue-recognition`** (this change, **kind: config**) — declare `SalesOrder` +
   `SalesOrderLine` in `lib/Settings/shillinq_register.json`, seed a realistic mixed order.
   The new line fields are read-only-available on every object once merged.
2. **`order-revenue-recognition-engine`** (**kind: code**, `depends_on: [order-revenue-recognition]`)
   — a thin `OCA\Shillinq\Recognition\RevenueRecognitionService` (ADR-031 exception, missing
   primitive documented) that computes recognized recurring revenue for a runtime period, plus
   the read endpoint the dashboard polls. NOT authored here.
3. **`pipelinq-recognized-recurring-revenue-widget`** (pipelinq repo, **kind: config/code**,
   `depends_on: [order-revenue-recognition-engine]`) — the dashboard widget reads recognized
   recurring revenue for the selected date range. NOT authored here.

Splitting lets the schema land first (expand-then-contract): existing consumers ignore the
new fields, the dashboard opts in only after the engine ships.

## Capabilities

### New Capabilities
- `recurring-revenue-recognition`: the `SalesOrder` + `SalesOrderLine` booking-term data model,
  the recognized-recurring-revenue metric definition (period-overlap proration + frequency
  normalization), the recurring/one-off split, the optional `contractId` legal reference, and
  the ADR-031 declarative-vs-exception boundary for the recognition computation.

### Modified Capabilities
<!-- None. The retired recurring-revenue-mrr-model change left no live spec to delta;
     CashflowRecurring is untouched (it remains a cashflow-projection entry, not a revenue booking). -->

## Impact

- **`lib/Settings/shillinq_register.json`** — two new schemas (`SalesOrder`, `SalesOrderLine`)
  with seed data. (Edited only by the implementing cycle — NOT by this authoring task.)
- **`openspec/architecture/adr-000-data-model.md`** — two new entity entries (implementing cycle).
- **Downstream `order-revenue-recognition-engine`** (shillinq) — new
  `OCA\Shillinq\Recognition\RevenueRecognitionService` + read route. Chained, not in this change.
- **Downstream pipelinq widget** — dashboard recurring-revenue tile re-sources from the
  recognition endpoint. Chained, in the pipelinq repo.
- **No new external dependencies.** No Contract entity. `CashflowRecurring` unchanged.
