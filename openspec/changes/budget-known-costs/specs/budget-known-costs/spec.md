# Spec: budget-known-costs

## ADDED Requirements

### Requirement: REQ-BKC-001 — `CashflowRecurring` MUST be extendable to a `Contract` and MUST NOT require one

`CashflowRecurring` MUST gain a nullable `contractReference` field (FK to
`Contract`) so a recurring cost MAY be tagged to the contract it originates
from. A `CashflowRecurring` row MUST remain valid and fully usable with
`contractReference` left null — this is the "cost that we project" case:
a dated planned cost with no contract yet. Both cases MUST resolve through
the identical schedule primitive; no second dated-cost schema is declared
by this change.

#### Scenario: A recurring cost with no contract is a valid, budgetable row

- **GIVEN** a `CashflowRecurring` row with `contractReference` absent,
  `validFrom` set to a future date, `accountNumberExpense` set to a real
  `Account.accountNumber`, and no `validTo`
- **WHEN** the row is saved
- **THEN** it saves successfully (the existing `CashflowRecurringGuard`
  checks pass unchanged), and it is eligible for expansion by
  `KnownCostScheduleExpander` from `validFrom` onward, indefinitely

@e2e budget-known-costs::recurring-cost-derives-budget-line

#### Scenario: A recurring cost tagged to a contract is distinguishable from one that is not

- **GIVEN** two `CashflowRecurring` rows, one with `contractReference` set
  to a real `Contract` id and one without
- **WHEN** `KnownCostBudgetWriter` runs
- **THEN** the first row's contribution is written to a `BudgetLine` with
  `source: "contract"` and the second's to a `BudgetLine` with `source:
  "recurring"`

@e2e budget-known-costs::contract-linked-cost-tags-source-contract

### Requirement: REQ-BKC-002 — A `CashflowRecurring` row linked to a `Contract` MUST stay within that contract's own dates

When `contractReference` is set and the referenced `Contract` has a
non-null `startDate` and/or `endDate`, `CashflowRecurringGuard` MUST reject
a save where `validFrom` precedes the contract's `startDate` or `validTo`
(when set) follows the contract's `endDate`. A `Contract` field left null
imposes no bound on that side.

#### Scenario: A recurring cost starting before its contract's start date is rejected

- **GIVEN** a `Contract` with `startDate: "2027-01-01"`
- **WHEN** a `CashflowRecurring` row with `contractReference` set to that
  contract and `validFrom: "2026-06-01"` is saved
- **THEN** the save is rejected by `CashflowRecurringGuard`

@e2e exclude backend guard precondition, no browser-visible surface —
verified by PHPUnit against the extended `CashflowRecurringGuard`

#### Scenario: An indefinite contract imposes no end bound

- **GIVEN** a `Contract` with `endDate` null
- **WHEN** a `CashflowRecurring` row with `contractReference` set to that
  contract and `validTo` null is saved
- **THEN** the save succeeds — no upper bound is enforced

@e2e exclude backend guard precondition, no browser-visible surface —
verified by PHPUnit against the extended `CashflowRecurringGuard`

### Requirement: REQ-BKC-003 — CPI indexation MUST use an operator-supplied rate, never a fabricated or zero rate

`CashflowRecurring` MUST gain a nullable `cpiRatePercent` field. When
`indexationRule = "CPI_PAST_YEAR"` and `cpiRatePercent` is set,
`KnownCostScheduleExpander` MUST compound the amount once per calendar year
relative to `validFrom`'s own year, applied uniformly to every in-scope
month of that fiscal year. When `indexationRule = "CPI_PAST_YEAR"` and
`cpiRatePercent` is null, the expander MUST return a typed
`needsOperatorInput` result for that row, never a `FIXED`-equivalent or
zero-indexed amount.

#### Scenario: A CPI-indexed recurring cost compounds once per year, applied to every month of that year

- **GIVEN** a `CashflowRecurring` row with `standardAmount: 1000`,
  `indexationRule: "CPI_PAST_YEAR"`, `cpiRatePercent: 2.0`, `validFrom` in
  fiscal year 2026, `frequency: "MONTHLY"`
- **WHEN** the expander computes fiscal year 2028's monthly amounts
- **THEN** every in-scope month of 2028 is `round(1000 × 1.02²) = 1040.4`
  → `1040` (integer cents convention), identical across all 12 months of
  that fiscal year

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `KnownCostScheduleExpanderTest::testCpiIndexationCompoundsAnnually`

#### Scenario: CPI indexation with no rate is never silently treated as fixed or zero

- **GIVEN** a `CashflowRecurring` row with `indexationRule: "CPI_PAST_YEAR"`
  and `cpiRatePercent` absent
- **WHEN** the expander computes its monthly amounts
- **THEN** the result is `{ kind: "needsOperatorInput" }` for every month,
  never a computed number

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `KnownCostScheduleExpanderTest::testCpiWithoutRateNeedsOperatorInput`

### Requirement: REQ-BKC-004 — `BudgetLineDerivation` MUST make regeneration idempotent

A system-managed `BudgetLineDerivation` row MUST record, per
`(annualBudgetId, ledgerGroupId, sourceType)`, which `BudgetLine` was
written, which `CashflowRecurring` rows contributed, and the exact monthly
amounts last written. Running `KnownCostBudgetWriter` twice in succession
with no change to any `CashflowRecurring`, `Contract`, `LedgerGroup`, or
`AnnualBudget` data MUST produce exactly one `BudgetLine` row per
`(annualBudgetId, ledgerGroupId, sourceType)` combination, with identical
monthly amounts after both runs.

#### Scenario: Running the writer twice does not double-count

- **GIVEN** one `CashflowRecurring` row targeting a `LedgerGroup`, and a
  default `AnnualBudget` for its fiscal year
- **WHEN** `KnownCostBudgetWriter` runs twice in succession with no
  intervening data change
- **THEN** exactly one `BudgetLine(source: "recurring")` row exists for
  that `(annualBudgetId, ledgerGroupId)` after both runs, with the same 12
  monthly amounts both times

@e2e exclude idempotency regression, no browser-visible surface — verified
by `KnownCostBudgetWriterTest::testRegenerationIsIdempotent`

#### Scenario: Multiple recurring costs targeting the same LedgerGroup sum into one derived line

- **GIVEN** two `CashflowRecurring` rows, neither `contractReference`-tagged,
  whose `accountNumberExpense` both resolve to the same `LedgerGroup`
- **WHEN** `KnownCostBudgetWriter` runs
- **THEN** exactly one `BudgetLine(source: "recurring")` row exists for that
  `LedgerGroup`, whose monthly amounts are the sum of both rows'
  contributions, and its `BudgetLineDerivation.contributingRecurIds` lists
  both `recurId`s

@e2e budget-known-costs::derivation-audit-trail-visible

### Requirement: REQ-BKC-005 — An operator's direct edit to a derived `BudgetLine` MUST be detected and respected, not silently overwritten

When a regeneration run finds a derived `BudgetLine`'s current monthly
amounts differ from its `BudgetLineDerivation.lastGeneratedMonthlyAmounts`,
it MUST mark that derivation `overridden: true` and MUST NOT overwrite the
`BudgetLine`'s amounts. Once `overridden: true`, subsequent runs MUST skip
that `(annualBudgetId, ledgerGroupId, sourceType)` combination entirely
until the `BudgetLine` is deleted, at which point the next run MUST
recreate it fresh (a fresh `BudgetLineDerivation` with `overridden: false`).

#### Scenario: A hand-edited derived line is flagged, not clobbered

- **GIVEN** a `BudgetLine(source: "recurring")` previously written by
  `KnownCostBudgetWriter`, whose `month03Amount` an operator has since
  edited directly to a different value than the derivation's
  `lastGeneratedMonthlyAmounts` records
- **WHEN** `KnownCostBudgetWriter` runs again with the same
  `CashflowRecurring` inputs as before
- **THEN** the `BudgetLine`'s amounts are left unchanged (the operator's
  edit persists), and `BudgetLineDerivation.overridden` becomes `true`

@e2e exclude override-detection regression, no browser-visible surface —
verified by `KnownCostBudgetWriterTest::testOperatorOverrideIsDetectedAndRespected`

#### Scenario: Deleting a derived line resets it to fully machine-generated

- **GIVEN** an `overridden: true` `BudgetLineDerivation` whose `BudgetLine`
  has since been deleted
- **WHEN** `KnownCostBudgetWriter` runs again
- **THEN** a fresh `BudgetLine` and a fresh `BudgetLineDerivation`
  (`overridden: false`) are created from the current `CashflowRecurring`
  inputs

@e2e exclude reset-path regression, no browser-visible surface — verified
by `KnownCostBudgetWriterTest::testDeletedDerivedLineIsRecreatedFresh`

### Requirement: REQ-BKC-006 — A `CashflowRecurring` row's schedule MUST honour `validFrom`/`validTo`, including the indefinite case

`KnownCostScheduleExpander` MUST contribute `0` for any calendar month
strictly before `validFrom`'s month, and `0` for any calendar month
strictly after `validTo`'s month when `validTo` is set. When `validTo` is
null, every month from `validFrom` onward, across every fiscal year, MUST
be in scope.

#### Scenario: An indefinite recurring cost is budgeted every year with no end

- **GIVEN** a `CashflowRecurring` row with `validFrom: "2024-01-01"` and
  `validTo` null
- **WHEN** the expander computes fiscal year 2030's monthly amounts
- **THEN** every month of fiscal year 2030 receives a nonzero contribution
  (subject to indexation, REQ-BKC-003)

@e2e exclude pure-calculator arithmetic — verified by
`KnownCostScheduleExpanderTest::testIndefiniteValidToNeverTerminates`

#### Scenario: A cost starting mid-year is budgeted only from its start month

- **GIVEN** a `CashflowRecurring` row with `validFrom: "2027-04-01"`,
  `frequency: "MONTHLY"`, no `validTo`
- **WHEN** the expander computes fiscal year 2027's monthly amounts
- **THEN** months January–March are `0` and April–December each carry the
  standard amount

@e2e exclude pure-calculator arithmetic — verified by
`KnownCostScheduleExpanderTest::testMidYearStartBudgetsOnlyFromStartMonth`

### Requirement: REQ-BKC-007 — A fiscal year with no default `AnnualBudget` MUST be skipped, never fabricated

When a `CashflowRecurring` row's schedule spans a fiscal year for which no
`AnnualBudget` with `isDefault: true` exists for that `administrationId`,
`KnownCostBudgetWriter` MUST NOT write any `BudgetLine` for that fiscal
year and MUST NOT create an `AnnualBudget`.

#### Scenario: A future fiscal year with no default budget yet is silently skipped

- **GIVEN** an indefinite `CashflowRecurring` row and no `AnnualBudget` for
  fiscal year 2032 in its administration
- **WHEN** `KnownCostBudgetWriter` runs
- **THEN** no `BudgetLine` is written for fiscal year 2032, and no
  `AnnualBudget` object is created

@e2e exclude backend orchestration logic, no browser-visible surface —
verified by `KnownCostBudgetWriterTest::testFiscalYearWithNoDefaultBudgetIsSkipped`

### Requirement: REQ-BKC-008 — Reads MUST be batched into a fixed, small query count

`KnownCostReader` MUST resolve all data for a regeneration run using
exactly 6 `findAll()` calls total (`CashflowRecurring`, `Account`,
`LedgerGroup`, `AnnualBudget`, `BudgetLine`, `BudgetLineDerivation`),
independent of the number of `CashflowRecurring` rows, `LedgerGroup`s, or
fiscal years touched.

#### Scenario: A regeneration run over 40 recurring rows across 5 fiscal years issues exactly 6 store queries

- **GIVEN** 40 `CashflowRecurring` rows spanning 5 fiscal years
- **WHEN** `KnownCostBudgetWriter` runs
- **THEN** exactly 6 `findAll()` calls are made against
  `ObjectServiceInterface`

@e2e exclude reader query-count regression, no browser-visible surface —
verified by `KnownCostReaderTest::testQueryCountIsFixed` with a mocked
`ObjectServiceInterface`

### Requirement: REQ-BKC-009 — Minimal pages MUST expose the derivation audit trail, reachable via navigation

`BudgetLineDerivation` MUST have an index and detail page, nested under
the `Budgets` top-level navigation group, showing `sourceType`,
`contributingRecurIds` (linked to their `CashflowRecurring` rows),
`lastGeneratedAt`, and `overridden`.

#### Scenario: An operator can trace a derived budget line back to its source recurring costs

- **GIVEN** a `BudgetLine(source: "recurring")` written by
  `KnownCostBudgetWriter` from two `CashflowRecurring` rows
- **WHEN** its `BudgetLineDerivation` detail page is opened
- **THEN** both contributing `CashflowRecurring` rows are listed and
  linked, and `lastGeneratedAt` is shown

@e2e budget-known-costs::derivation-audit-trail-visible

### Requirement: REQ-BKC-010 — Weekly and fortnightly recurring costs MUST expand via exact occurrence-date enumeration, never an averaged monthly factor

For `frequency = "WEEKLY"` or `"FORTNIGHTLY"`, `KnownCostScheduleExpander`
MUST compute each in-scope month's amount by enumerating the row's actual
occurrence dates — starting at `validFrom` and stepping by 7 days
(`WEEKLY`) or 14 days (`FORTNIGHTLY`), bounded by `validTo` when set — and
booking `standardAmount` (indexed per REQ-BKC-003 where applicable) once
per occurrence date that falls inside that month. It MUST NOT apply an
averaged factor (e.g. 52/12 or 26/12) to approximate a monthly total.

#### Scenario: A month with five weekly occurrences books five times the per-occurrence amount

- **GIVEN** a `CashflowRecurring` row with `frequency: "WEEKLY"`,
  `standardAmount: 100`, `indexationRule: "FIXED"`, `validFrom` anchored on
  a Monday, and no `validTo`
- **WHEN** the expander computes the amount for a calendar month that
  contains 5 Mondays on or after `validFrom` (rather than the more common
  4)
- **THEN** that month's amount is `500` (5 × 100), not `433` (an averaged
  52/12 × 100 approximation) and not `400` (a flat ×4 approximation)

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `KnownCostScheduleExpanderTest::testMonthWithFiveWeeklyOccurrencesSumsAllFive`

#### Scenario: A fortnightly cost books only the occurrences that actually land inside a given month

- **GIVEN** a `CashflowRecurring` row with `frequency: "FORTNIGHTLY"`,
  `standardAmount: 250`, `validFrom: "2027-01-04"`, no `validTo`
- **WHEN** the expander computes January 2027's amount (occurrences on
  `2027-01-04` and `2027-01-18`, both inside the month) and February 2027's
  amount (the next occurrence, `2027-02-01`, plus `2027-02-15`)
- **THEN** January's amount is `500` (2 occurrences) and February's is also
  `500` (2 occurrences) — each derived from counting exact dates, not from
  a fixed per-month occurrence assumption

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `KnownCostScheduleExpanderTest::testFortnightlyExpansionEnumeratesExactOccurrenceDates`

### Requirement: REQ-BKC-011 — Non-goals

This change MUST NOT implement the spreadsheet-grid UI (`budget-grid-view`),
projection/growth-rate math (`budget-projection-engine`), scenario or
modifier support (`budget-scenarios`), charts (`budget-charts`), a live
CBS-CPI feed, or any change to `CashflowWeek`/`CashflowForecastHorizon`/
`CashflowARProjection`/`CashflowBufferPolicy`.

#### Scenario: No grid, projection, scenario, chart, or 13-week-engine code appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no spreadsheet-grid component, projection-math service,
  scenario-switching logic, chart component, live CPI-feed integration, or
  edit to `CashflowWeek`/`CashflowForecastHorizon`/`CashflowARProjection`/
  `CashflowBufferPolicy` is present — only the two additive
  `CashflowRecurring` fields, the extended `CashflowRecurringGuard`, the
  new `BudgetLineDerivation` schema, the three new PHP services, and the 2
  minimal pages named in REQ-BKC-009

@e2e exclude negative/scope-boundary requirement — verified by diff
inspection
