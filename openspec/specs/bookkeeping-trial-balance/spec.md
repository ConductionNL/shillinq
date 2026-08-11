---
status: done
---

# Spec: bookkeeping-trial-balance

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL)

## Purpose

This specification defines the requirements for bookkeeping trial balance in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

### REQ-TB-001: The system SHALL produce a trial balance as a declarative aggregation over `GLLine`, not a PHP report builder

The trial balance MUST be expressed as one (or composed three)
`x-openregister-aggregations` query against T1's `GLLine` register,
grouping by `(period_id, account_number, side)` and reducing with
SUM(amount). The implementation MUST NOT introduce a
`TrialBalanceService.php`, `TrialBalanceReportBuilder.php`, or any
PHP class whose responsibility is "assemble the trial balance".
This is the ADR-031 anti-pattern explicitly enumerated under
"Aggregation service".

Whether the three buckets (opening / period movement / closing)
land as one aggregation with bucket discriminators or as three
composed aggregations is resolved during `opsx-ff` design
discovery; both shapes satisfy this requirement.

#### Scenario: Reviewer confirms no report-builder service

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/*ReportBuilder*.php`,
  `lib/Service/*Report*Service.php`, or
  `lib/Service/TrialBalance*.php` files
- **THEN** no such files SHALL exist; the trial-balance output
  comes from OR's aggregation engine.

#### Scenario: Aggregation query is declarative in the register file

- **GIVEN** `lib/Settings/shillinq_register.json`
- **WHEN** scanned for the trial-balance aggregation declaration
- **THEN** an `x-openregister-aggregations` block MUST exist on
  the `GLLine` schema (or as a stand-alone aggregations register
  per OR convention) naming the trial balance grouping and
  reduction.

### REQ-TB-002: The trial balance SHALL report opening balance, period movement, and closing balance per account per period

For each `(period_id, account_number)` pair, the aggregation MUST
produce three buckets:

| Bucket | Definition |
|---|---|
| `opening` | Sum of all posted `GLLine` rows with `periodId` strictly before the requested period, computed as `SUM(debit) - SUM(credit)` (positive = debit balance, negative = credit balance) |
| `movement` | Same computation restricted to the requested `periodId` |
| `closing` | `opening + movement` |

The aggregation MUST exclude lines whose parent `GLTransaction.state`
is `reversed` (per T1 REQ-GL-004 — reversed transactions are
excluded from balance aggregations). The result MUST also
distinguish `debit_total` and `credit_total` per period per account
so consumers can show split columns (the trial balance's
canonical four-column shape: opening Dr / opening Cr / movement Dr
/ movement Cr / closing Dr / closing Cr).

#### Scenario: First period of an administration shows zero opening

- **GIVEN** a fresh administration with its first period `2026-Q1`
  and three posted transactions in that period
- **WHEN** the trial balance is requested for `2026-Q1`
- **THEN** every account row's `opening` MUST be 0; `movement`
  MUST reflect the three transactions; `closing` MUST equal
  `movement`.

#### Scenario: Subsequent period inherits the prior closing as its opening

- **GIVEN** account `1000 Cash` ended `2026-Q1` with closing
  `€10 000` debit
- **WHEN** the trial balance is requested for `2026-Q2`
- **THEN** account `1000`'s `opening` MUST be `€10 000` debit;
  movement MUST reflect only Q2 postings.

#### Scenario: Reversed transactions are excluded

- **GIVEN** a posted transaction `T1` debiting `4100 Sales` €100
  in `2026-Q1`, and a posted compensating transaction `T2`
  crediting `4100 Sales` €100 in `2026-Q1`, after which `T1` is
  transitioned to `reversed`
- **WHEN** the trial balance is requested for `2026-Q1`
- **THEN** account `4100`'s movement MUST reflect only `T2`
  (`T1` is excluded because it is `reversed`); the net effect on
  account `4100` MUST be `-€100` credit.

### REQ-TB-003: The trial balance output SHALL satisfy the debit-credit balance invariant as a schema-declared assertion

The system SHALL satisfy this requirement: The trial balance output SHALL satisfy the debit-credit balance invariant as a schema-declared assertion.

The sum of all `closing.debit` across all accounts in the requested
period MUST equal the sum of all `closing.credit`. This invariant
MUST be declared as a schema invariant on the aggregation output
(per ADR-031 — the assertion is metadata on the aggregation, not a
PHP service check). A failed invariant MUST surface as an error
to the consumer with a delta value reporting the imbalance to the
cent.

If the OR aggregation engine cannot express output-side invariants
declaratively, the shape-neutral fallback per ADR-031 exception is
a single-method `OCA\Shillinq\Lifecycle\TrialBalanceInvariantGuard`
called *by* the aggregation engine; the guard MUST NOT contain any
report-building logic.

#### Scenario: Balanced trial balance returns no invariant error

- **GIVEN** a period with all posted transactions balanced per
  T1 REQ-GL-005
- **WHEN** the trial balance is requested
- **THEN** the aggregation MUST succeed; the invariant MUST
  evaluate true; no error MUST be surfaced.

#### Scenario: Imbalance is reported with delta

- **GIVEN** a hypothetical corrupted state where a posted
  transaction's lines no longer balance (only reachable via
  direct DB tampering; T1 REQ-GL-005 prevents this at write time)
- **WHEN** the trial balance is requested
- **THEN** the aggregation MUST surface an invariant error naming
  the delta to the cent and listing the offending
  `(transaction_id, account_number)` pairs.

### REQ-TB-004: Each trial-balance row SHALL be drill-through-able to the underlying GL transactions

Every row in the trial-balance output MUST carry sufficient
identifiers (`period_id`, `account_number`) to construct a
filtered URL into the General Ledger index page declared in T1
REQ-GL-007. The drill-through is a manifest-side affordance — a
row click navigates to
`/index.php/apps/shillinq/general-ledger?period=<period_id>&account=<account_number>`,
where T1's `CnIndexPage` filters the GL transactions to that
slice.

No bespoke drill-through code in shillinq; the manifest declares
the link template and the OR generic index page handles the
filtered query.

#### Scenario: Drill-through link is constructible

- **GIVEN** a trial-balance row for `(2026-Q1, 4100)`
- **WHEN** the row's drill-through URL is constructed
- **THEN** the URL MUST be
  `/index.php/apps/shillinq/general-ledger?period=2026-Q1&account=4100`.

#### Scenario: Drill-through reaches the filtered GL index

- **GIVEN** the trial-balance row for `(2026-Q1, 4100)` and a
  manifest declaring the drill-through link template
- **WHEN** an operator clicks the row
- **THEN** the GL index page MUST render filtered to period
  `2026-Q1` and account `4100`, showing all posted lines in
  that slice with their parent transaction reference.

### REQ-TB-005: Trial balance SHALL be reachable through the shillinq manifest navigation as a `type: report` (or `type: index`) page

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
Trial Balance`) with a page binding the trial-balance aggregation
to a renderer. The renderer SHOULD be `CnReportPage` from
`@conduction/nextcloud-vue` (the same renderer that the
`bookkeeping-financial-statements` capability uses); if that
component does not yet exist in the library, the `type: index`
renderer with custom columns is the fallback. The choice is
documented in `bookkeeping-trial-balance` design discovery; the
spec is shape-neutral.

The page MUST accept a `period` query parameter; absent that, it
MUST default to the currently-open `FiscalPeriod` from the
`bookkeeping-period-close` capability.

#### Scenario: Index page lists trial-balance rows

- **GIVEN** the manifest declares the Trial Balance page and the
  aggregation has been populated
- **WHEN** an operator opens
  `/index.php/apps/shillinq/trial-balance?period=2026-Q1`
- **THEN** the renderer MUST display one row per non-zero
  account showing the four canonical columns (opening Dr / Cr,
  movement Dr / Cr, closing Dr / Cr) **AND** a totals row at
  the bottom proving the invariant per REQ-TB-003.

#### Scenario: Default period falls back to open period

- **GIVEN** the manifest declares the Trial Balance page and the
  active `FiscalPeriod` is `2026-Q2`
- **WHEN** an operator opens
  `/index.php/apps/shillinq/trial-balance` with no `period` query
  parameter
- **THEN** the page MUST default to `period=2026-Q2`.

### REQ-TB-006: The trial balance SHALL support multi-period comparison via repeated aggregation calls, not a separate report-builder

The system SHALL satisfy this requirement: The trial balance SHALL support multi-period comparison via repeated aggregation calls, not a separate report-builder.

When a consumer requests N periods of comparative trial balance
data, the renderer MUST issue N independent aggregation calls (or
one aggregation with N period filters if the OR engine supports
it). No bespoke "multi-period report" assembly code in shillinq —
the composition is a manifest concern. The `bookkeeping-financial-statements`
capability uses this same pattern for year-over-year comparatives.

#### Scenario: Three-period comparison composes from three aggregation calls

- **GIVEN** an operator requests trial-balance comparison across
  `2025-Q4`, `2026-Q1`, `2026-Q2`
- **WHEN** the renderer issues the requests
- **THEN** three independent aggregation calls MUST land at OR;
  composition into a 6-column-per-period grid MUST happen in the
  renderer manifest, not in shillinq PHP.
