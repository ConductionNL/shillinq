# Spec: bookkeeping-reconciliation-reports

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-general-ledger (T1), bookkeeping-accounts-payable-core (T2), bookkeeping-accounts-receivable-core (T2)

## ADDED Requirements

### Requirement: REQ-RR-001 — Reconciliation reports SHALL be expressed as OpenRegister saved-query objects, NOT as a PHP report engine

Every reconciliation report (sub-ledger ↔ GL control account match, intercompany matching, variance analysis, controller exception report) MUST be declared as a saved-query record in an OpenRegister-managed `SavedQuery` register (or whatever OR's canonical name for parameterised aggregation queries is). The query MUST be an `x-openregister-aggregations` definition consumed both by launchpad for dashboard rendering (per ADR-022) and by the shillinq manifest detail page that surfaces the report. shillinq MUST NOT author a `ReportingService` / `ReconciliationService` that loops ledger objects and produces rows.

#### Scenario: Reviewer confirms no report engine

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes with names matching
  `*Report*` / `*Reconciliation*` / `*Variance*` whose methods
  iterate OR objects to produce rows
- **THEN** no such classes SHALL exist; reports MUST be aggregation-
  declared.

#### Scenario: launchpad consumes a reconciliation report by query slug

- **GIVEN** a saved query `subledger-ap-vs-gl-1700` declared as
  `x-openregister-aggregations`
- **WHEN** launchpad renders a widget bound to that slug via runtime
  GraphQL
- **THEN** the widget MUST resolve without any shillinq-side PHP
  controller in the call path.

### Requirement: REQ-RR-002 — The system SHALL provide a sub-ledger ↔ GL control-account reconciliation for AP and AR

A saved query MUST be declared per sub-ledger control account
(AP control, AR control) producing a row per
`(controlAccountNumber, period, subLedgerOpenBalance,
glControlAccountBalance, difference, isReconciled)`. The query
MUST flag rows where `|difference| > administrationTolerance`
(default `EUR 0.01` per administration setting) as exceptions.

#### Scenario: A balanced AP ↔ GL reconciliation reports zero variance

- **GIVEN** the AP sub-ledger sums to €123,456.78 open at period
  end, and the GL control account `1700` carries €123,456.78
- **WHEN** the reconciliation query runs for that period
- **THEN** the row MUST show `difference: 0`, `isReconciled: true`.

#### Scenario: A mismatched AR ↔ GL reconciliation surfaces as an exception

- **GIVEN** the AR sub-ledger sums to €50,000 open and the GL
  control account `1300` carries €49,950
- **WHEN** the query runs
- **THEN** the row MUST show `difference: -50`, `isReconciled:
  false`; **AND** the controller exception report (per REQ-RR-005)
  MUST surface the row.

### Requirement: REQ-RR-003 — The system SHALL provide intercompany matching for group administrations

A saved query MUST match each intercompany posting in
administration A against the corresponding mirror posting in
administration B (group members linked via a `Group` register
declared in T5 intercompany; the FK shape is pre-positioned here:
each administration MAY carry a `groupId` field referencing a
`Group` record). The query MUST produce a row per
`(groupId, period, leg-A-administration, leg-B-administration,
amount-A, amount-B, difference, matchedAt)`.

#### Scenario: A matched intercompany pair reports zero variance

- **GIVEN** administration A posts €10,000 to `1600 IC-receivable
  from B` and administration B posts €10,000 to `1601 IC-payable
  to A`
- **WHEN** the intercompany match query runs for the period
- **THEN** the row MUST show `difference: 0` and `matchedAt`
  populated with the most recent posting timestamp.

#### Scenario: An unmatched intercompany leg surfaces as an exception

- **GIVEN** administration A posts €10,000 to its IC-receivable
  account and administration B has no corresponding entry
- **WHEN** the query runs
- **THEN** the row MUST show `amount-B: 0`, `difference: 10,000`,
  `matchedAt: null`; **AND** the controller exception report MUST
  surface the row.

### Requirement: REQ-RR-004 — The system SHALL provide variance analysis comparing actual to budget per account per period

A saved query MUST produce a row per (`accountNumber`, `period`,
`actualAmount`, `budgetAmount`, `varianceAmount`, `variancePct`)
joining `GLLine` aggregations to the operator-maintained
`Budget` register (declared as a register here in this capability,
similar shape to T1 `Account`, fields: `accountNumber`,
`periodId`, `budgetAmount`, `currency`, `administrationId`,
`lifecycleState`). Variance rows where `|variancePct| >
administrationVarianceThreshold` (default `10%` per administration
setting) MUST surface in the controller exception report.

#### Scenario: A within-threshold variance does not flag

- **GIVEN** budget `EUR 10,000` and actual `EUR 10,500` for
  account `4100 Sales` in period `2026-07`
- **WHEN** the variance query runs
- **THEN** the row MUST show `variancePct: 5%`; **AND** the
  exception report MUST NOT include it (within default 10%).

#### Scenario: An above-threshold variance flags

- **GIVEN** budget `EUR 10,000` and actual `EUR 12,500`
- **WHEN** the query runs
- **THEN** the row MUST show `variancePct: 25%`; **AND** the
  exception report MUST include it.

### Requirement: REQ-RR-005 — The system SHALL provide a controller exception report aggregating all reconciliation exceptions

A consolidating saved query MUST aggregate exception rows from
REQ-RR-002 (sub-ledger mismatches), REQ-RR-003 (unmatched
intercompany), and REQ-RR-004 (above-threshold variances) into a
single report sorted by severity (`critical` > `warning` > `info`)
and per administration. The severity classification MUST be
declarative — encoded as a calculated field on each query — not
authored in PHP. launchpad consumes this report via runtime GraphQL
to render the controller's home dashboard widget.

#### Scenario: The exception report surfaces all three exception classes in one query

- **GIVEN** the period has: 1 AP-vs-GL mismatch, 1 unmatched IC
  leg, 2 above-threshold variances
- **WHEN** the controller exception report runs
- **THEN** the result MUST contain exactly 4 rows; **AND** the
  rows MUST be ordered by severity then administration.

### Requirement: REQ-RR-006 — Reconciliation reports SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare navigation entries under
`Bookkeeping > Reconciliation Reports` with a `type: index` page
listing the saved-query catalog (one per report) and a `type:
detail` page that renders each report's results. Detail pages
MUST be rendered by the generic `@conduction/nextcloud-vue`
`CnIndexPage` / `CnDetailPage` components driven by the
saved-query metadata — no bespoke Vue files (per ADR-024 Tier-4).

A `Budget` index/detail page pair MUST also be declared (since
this capability introduces the `Budget` register).

#### Scenario: The controller exception report renders from the saved-query record

- **GIVEN** the manifest declares the Reconciliation Reports pages
- **WHEN** the operator opens
  `/index.php/apps/shillinq/reconciliation-reports/controller-exceptions`
- **THEN** the page MUST render via `CnDetailPage` showing the
  rows of the underlying saved query with severity-coloured
  badges and per-row drill-down links into the underlying GL /
  sub-ledger objects.

### Requirement: REQ-RR-007 — Reports SHALL be consumable from launchpad via runtime GraphQL with no install-time dependency

Per ADR-022 and `feedback_launchpad-no-or-dependency.md`, launchpad MUST
consume reconciliation reports via runtime GraphQL against
OpenRegister; launchpad MUST NOT declare a `shillinq` dependency in
its app manifest, and shillinq MUST NOT push any data to launchpad.
The reports are surfaced *because* they are aggregations on OR
registers — launchpad discovers them through the GraphQL schema.

#### Scenario: Reviewer confirms launchpad does not depend on shillinq

- **GIVEN** launchpad's `src/manifest.json`
- **WHEN** scanned for `dependencies[]`
- **THEN** `shillinq` MUST NOT be listed; launchpad's only
  data-source dependency MUST be runtime GraphQL on OR.
