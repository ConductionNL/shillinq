# Spec: bookkeeping-trial-balance

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** T1 `bookkeeping-general-ledger`

## ADDED Requirements

### Requirement: REQ-TB-001 — The system SHALL declare the trial balance as an `x-openregister-aggregations` query on `GLLine`

Per ADR-031, the trial balance MUST NOT be implemented as a PHP
report-builder service. It MUST be declared as one or more
`x-openregister-aggregations` queries in
`lib/Settings/shillinq_register.json` grouping `GLLine` records by
`(period_id, account_number, side)` with opening / movement / closing
buckets. No custom PHP service class (e.g. `TrialBalanceReportService`)
SHALL exist (per ADR-022 anti-pattern list). Per ADR-031, if the OR
aggregation engine cannot express all three buckets in one query,
each bucket becomes its own named aggregation and the presentation
layer composes them — still declarative, no PHP required.

#### Scenario: Trial balance aggregation is declared in the register

- **GIVEN** `lib/Settings/shillinq_register.json` is loaded
- **WHEN** scanned for `x-openregister-aggregations` entries
- **THEN** at least one aggregation entry named `trial-balance` (or
  equivalent) MUST exist, grouping `GLLine` by `(periodId,
  accountNumber, side)` with `SUM(amount)` per bucket.

#### Scenario: Reviewer confirms no parallel PHP report builder

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/` classes whose names contain
  `TrialBalance`, `ReportBuilder`, or `LedgerSummary`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-TB-002 — The trial-balance aggregation SHALL produce opening, movement, and closing buckets per period and account

The aggregation output MUST include, for each
`(periodId, accountNumber)` pair:

| Bucket | Definition |
|---|---|
| `openingDebit` | Sum of debit `GLLine` amounts dated before the period's `startDate`, excluding reversed lines |
| `openingCredit` | Sum of credit `GLLine` amounts dated before the period's `startDate`, excluding reversed lines |
| `movementDebit` | Sum of debit `GLLine` amounts within the period, excluding reversed lines |
| `movementCredit` | Sum of credit `GLLine` amounts within the period, excluding reversed lines |
| `closingDebit` | `openingDebit` + `movementDebit` |
| `closingCredit` | `openingCredit` + `movementCredit` |

Reversed `GLLine` records (parent `GLTransaction.state = "reversed"`)
MUST be excluded from all buckets.

#### Scenario: Trial balance shows correct opening and movement buckets

- **GIVEN** account `1000 Kas` has a debit opening balance of
  EUR 5.000 from prior periods
- **AND** in period `2026-01` account `1000` receives a debit of
  EUR 1.000 and a credit of EUR 500
- **WHEN** the trial-balance aggregation is queried for period `2026-01`
- **THEN** the result for account `1000` MUST show
  `openingDebit: 5000`, `movementDebit: 1000`, `movementCredit: 500`,
  `closingDebit: 6000`, `closingCredit: 500`.

#### Scenario: Reversed transactions are excluded

- **GIVEN** a `GLTransaction` with state `reversed` containing a
  debit `GLLine` of EUR 2.000 on account `4100 Omzet` in period
  `2026-01`
- **WHEN** the trial-balance aggregation is queried for period `2026-01`
- **THEN** the EUR 2.000 debit line MUST NOT appear in any bucket
  for account `4100`.

### Requirement: REQ-TB-003 — The trial balance SHALL declare the debit-equals-credit invariant as a schema invariant

The trial balance MUST declare the debit-equals-credit invariant at the schema level — not as a PHP assertion inside a service method. The debit-credit-balance-verifies invariant (sum of all
`closingDebit` across all accounts for a period MUST equal sum of
all `closingCredit`) MUST be declared as a schema-level invariant
on the trial-balance aggregation output per ADR-031 — not as a
PHP assertion inside a service method. If the aggregation engine
supports declarative invariant annotations, the annotation MUST be
used. If not, the invariant MUST be documented as a precondition
on period-close (REQ-PC-003).

#### Scenario: Balanced books produce no invariant violation

- **GIVEN** a set of balanced `GLTransaction` records (all debits =
  all credits) for period `2026-01`
- **WHEN** the trial-balance aggregation is computed and the
  invariant is evaluated
- **THEN** the invariant MUST NOT fire a violation.

#### Scenario: Unbalanced books produce an invariant violation

- **GIVEN** a manually corrupted `GLLine` where one debit line is
  tampered to a different amount (debits ≠ credits)
- **WHEN** the invariant is evaluated
- **THEN** the invariant MUST fire and surface an error or warning
  visible to the `auditor` role.

### Requirement: REQ-TB-004 — The trial balance SHALL exclude accounts with zero opening and zero movement

The trial balance SHALL exclude accounts whose opening and movement buckets are all zero, unless an `includeEmpty=true` query parameter is supplied. To produce a readable report, the aggregation SHOULD filter out
accounts where `openingDebit`, `openingCredit`, `movementDebit`,
and `movementCredit` are all zero. This filter MAY be toggled off
by a query parameter (`includeEmpty=true`) to support full-chart
inspection.

#### Scenario: Empty accounts are suppressed by default

- **GIVEN** account `9999 Reserverekening` has no GL postings in
  any period
- **WHEN** the trial balance is queried for any period without
  `includeEmpty=true`
- **THEN** account `9999` MUST NOT appear in the aggregation output.

### Requirement: REQ-TB-005 — The trial balance SHALL be reachable through the shillinq manifest navigation with drill-through to the GL

`src/manifest.json` MUST declare a `Bookkeeping > Trial Balance`
navigation entry. The page MUST bind to the trial-balance
aggregation, default to the active `FiscalPeriod` (or the most
recently closed period if no active period exists), and declare a
drill-through affordance linking each account row to the filtered
`GLLine` index page (`/general-ledger?periodId=…&accountNumber=…`).
The page MAY use `type: report` (if `CnReportPage` is available in
`@conduction/nextcloud-vue`) or `type: index` as a fallback. No
bespoke Vue component is authored (per ADR-024).

#### Scenario: Trial balance navigation entry exists in the manifest

- **GIVEN** `src/manifest.json` is loaded
- **WHEN** scanned for navigation entries
- **THEN** a `Trial Balance` entry under the `Bookkeeping` section
  MUST exist with a valid page reference and a `periodId` query
  parameter declared.

#### Scenario: Drill-through link is generated per account row

- **GIVEN** the trial balance is displayed for period `2026-01`
- **WHEN** the operator clicks on account `4100 Omzet` in the
  trial-balance table
- **THEN** the UI MUST navigate to the GL index page filtered to
  `periodId=2026-01&accountNumber=4100`.
