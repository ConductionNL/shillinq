# Spec: budget-projection-engine

## ADDED Requirements

### Requirement: REQ-BPE-001 — The projection metric MUST be selected by account type: closing balance for stock accounts, net movement for flow accounts

`Account.accountType` MUST determine which quantity is projected:
`assets`, `liabilities`, and `equity` (stock accounts) MUST project
`closingBalance`; `revenue` and `expenses` (flow accounts) MUST project
`netMovement` (`debitMovement - creditMovement`, the same signed
convention `TrialBalanceLine` already exposes, applied without a
per-type sign flip). All five account types MUST be projectable by type;
an individual account MAY still be unprojectable for lack of data
(REQ-BPE-004).

#### Scenario: A stock account projects its closing balance, not a re-derived movement

- **GIVEN** an `assets` account with 12 months of `closingBalance` history
- **WHEN** `BudgetProjectionCalculator` computes its projection
- **THEN** the growth rate (REQ-BPE-002) is computed over the
  `closingBalance` series, and the projected value for a future month is a
  projected `closingBalance`, not a value derived from projecting
  `netMovement` and re-summing

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `BudgetProjectionCalculatorTest::testStockAccountProjectsClosingBalance`

#### Scenario: A flow account projects its net movement, not a closing balance

- **GIVEN** a `revenue` account with 12 months of `netMovement` history
- **WHEN** `BudgetProjectionCalculator` computes its projection
- **THEN** the growth rate is computed over the `netMovement` series, and
  the projected value for a future month is a projected `netMovement`

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `BudgetProjectionCalculatorTest::testFlowAccountProjectsNetMovement`

### Requirement: REQ-BPE-002 — The average-growth rate MUST be computed over trailing pairwise month-over-month steps, with zero-base and sign-flip steps excluded and a fixed outlier trim above five valid steps

Given a trailing window of up to 12 calendar-month metric values (REQ-BPE-003
for how the window itself is bounded), the growth rate `ḡ` MUST be the
arithmetic mean of the pairwise step rates `g_i = (v_i/v_{i-1}) - 1` for
`i` where both `v_{i-1}` and `v_i` are present, EXCEPT: a step where
`v_{i-1} = 0` and `v_i ≠ 0` MUST be excluded (undefined ratio); a step
where `v_{i-1}` and `v_i` have opposite non-zero signs MUST be excluded
(not a meaningful percentage); a step where both are `0` MUST be included
as `g_i = 0`. When the count of included steps is 5 or more, the single
highest and single lowest included step MUST be dropped before averaging;
below 5, no trimming is applied. `design.md` §2 is the normative
derivation.

#### Scenario: A zero-to-zero step is included as a flat rate

- **GIVEN** a metric series `[1000, 1000, 0, 0, 1000]` (cents) — steps
  `1000→1000` (0%, included), `1000→0` (-100%, included per the next
  scenario), `0→0` (index 3→4), `0→1000` (excluded per the next scenario)
- **WHEN** the `0 → 0` step (index 3→4) is evaluated
- **THEN** it is included in the growth-rate average as `g = 0`

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testZeroToZeroStepIncluded`

#### Scenario: A step starting from zero is excluded; a step ending at zero is not

- **GIVEN** a metric series `[0, 500]` for one step and `[500, 0]` for
  another
- **WHEN** each step is evaluated
- **THEN** `0 → 500` is excluded (division by zero from a zero base) and
  `500 → 0` is included as `g = -1.0` (a fully computable -100% rate)

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testZeroBaseStepExcluded` and
`testNonZeroToZeroStepIncluded`

#### Scenario: A sign-flip step is excluded

- **GIVEN** a `netMovement` series with a reversal month producing the
  step pair `500 → -200` (expenses account, one-off credit reversal)
- **WHEN** that step is evaluated
- **THEN** it is excluded from the growth-rate average — the surrounding
  same-signed steps still contribute normally

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testSignFlipStepExcluded`

#### Scenario: A single outlier month is trimmed, not allowed to dominate

- **GIVEN** 11 included steps of which 10 cluster around +2% and one is
  +80% (a one-off month)
- **WHEN** the growth rate is computed
- **THEN** the trim (5+ included steps) drops the +80% high and the
  single lowest remaining value, and `ḡ` is the mean of the remaining 9
  steps — not skewed toward the +80% outlier

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testOutlierIsTrimmedAboveFiveSteps`

#### Scenario: Fewer than five included steps are never trimmed

- **GIVEN** exactly 4 included steps
- **WHEN** the growth rate is computed
- **THEN** all 4 contribute to the mean; none are dropped

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testNoTrimBelowFiveSteps`

### Requirement: REQ-BPE-003 — A month with no GL data at all MUST shorten the trailing window, never be treated as a zero value

The trailing window for a given account MUST include only calendar months
on or after that account's earliest posted `GLTransaction`. A nominal
trailing-12-month month that falls before the account's earliest data
MUST be dropped from the window (shortening it), and MUST NOT be
substituted with a `0` metric value. Calendar-month bucketing MUST be
derived from `GLTransaction.postingDate`, never from `GLLine.periodId`
(which is optional and not guaranteed monthly granularity — this
codebase's own seed data carries `periodId` values at monthly, quarterly,
and half-year granularity simultaneously).

#### Scenario: A newly opened account has a shorter-than-12 window, not a padded one

- **GIVEN** an account whose earliest posted `GLTransaction` is 3 months
  before its `lastActualMonth`
- **WHEN** the trailing window is resolved
- **THEN** the window contains exactly 3 (or 4, inclusive of the boundary
  month) real metric values, producing at most 2–3 pairwise steps — never
  a 12-value window with the missing 8–9 months represented as `0`

@e2e exclude pure-calculator / reader boundary logic — verified by
`BudgetProjectionReaderTest::testWindowShortensToEarliestPostedTransaction`

#### Scenario: Monthly bucketing uses `postingDate`, not `periodId`

- **GIVEN** a `GLTransaction` with `postingDate: "2026-03-15"` and a
  `periodId` of `"2026-Q1"` on its `GLLine` rows
- **WHEN** the reader buckets the line into a calendar month
- **THEN** it is bucketed under `2026-03`, not spread or mis-keyed against
  the quarterly `periodId` string

@e2e exclude reader bucketing logic — verified by
`BudgetProjectionReaderTest::testBucketsByPostingDateNotPeriodId`

### Requirement: REQ-BPE-004 — Below a minimum of 3 valid growth steps, an account MUST be reported unprojectable, never a fabricated rate

If the count of included pairwise steps (REQ-BPE-002, after exclusions
and before any trim) is below `MIN_VALID_STEPS = 3`, the calculator MUST
return a typed `unprojectable` result (`reason: "insufficient-data"`)
for that account/month, carrying the actual valid-step count. Callers
MUST NOT treat an absent or zero `amount` on an `unprojectable` result as
a projected value of zero.

#### Scenario: Two valid steps is reported as insufficient, not projected

- **GIVEN** a metric series producing exactly 2 included pairwise steps
- **WHEN** `BudgetProjectionCalculator` computes a projection
- **THEN** the result is `{ kind: "unprojectable", reason:
  "insufficient-data", validSteps: 2 }`, and no `amount` field is present

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testBelowMinimumStepsIsUnprojectable`

#### Scenario: Exactly three valid steps is projectable

- **GIVEN** a metric series producing exactly 3 included pairwise steps
- **WHEN** `BudgetProjectionCalculator` computes a projection
- **THEN** the result is `{ kind: "projected", amount: <int>, rate: <float>,
  validSteps: 3 }`

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testExactlyMinimumStepsProjects`

### Requirement: REQ-BPE-005 — A projected month MUST be computed by compounding the single mean growth rate forward from the last actual value

For projected month offset `k = 1, 2, …` beyond `lastActualMonth`'s value
`V₀`, the projected value MUST be `round_cents(V₀ × (1 + ḡ)^k)` — the same
`ḡ` applied uniformly at every offset, not re-estimated per step.
Projection MUST NOT extend beyond `PROJECTION_HORIZON_MONTHS = 12` (one
fiscal year, matching `BudgetLine`'s 12 monthly slots).

#### Scenario: Month 3 of a projection equals the last actual compounded three times

- **GIVEN** `V₀ = 10000` cents and `ḡ = 0.02`
- **WHEN** the projected value for offset `k=3` is computed
- **THEN** it equals `round(10000 × 1.02³) = round(10612.08) = 10612` cents

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testExtrapolationCompoundsSingleRate`

### Requirement: REQ-BPE-006 — A period with actuals MUST always show actuals; projection MUST only fill periods with no actual, per account

Reusing `forecastByMonth`'s "drop months overlapping realized data" rule
explicitly: for a given account, a calendar month MUST resolve to
`"actual"` whenever an actual value exists for it, MUST resolve to
`"projected"` only for months strictly after that account's own
`lastActualMonth` (resolved independently per account — different
accounts MAY have different cutovers within the same request), and MUST
resolve to `"unprojectable"` for any in-window month before the account's
earliest data (REQ-BPE-003). No month is ever both.

#### Scenario: An account's own cutover, not a global one, decides the seam

- **GIVEN** two accounts in the same request, one with actuals through
  `2026-06` and another (opened later) with actuals through `2026-04`
- **WHEN** the seam is resolved for `2026-05`
- **THEN** the first account shows `"actual"` for `2026-05` and the
  second shows `"projected"` for `2026-05` — the seam is per-account

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testSeamIsPerAccountNotGlobal`

#### Scenario: An actual is never overridden by a projected value

- **GIVEN** an account with an actual value posted for a month that also
  falls within what would otherwise be its projection horizon (a
  late-posted correction after a projection was already requested for
  that month)
- **WHEN** the seam is resolved for that month
- **THEN** the result is `"actual"`, never `"projected"`

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testSeamNeverOverridesAnActual`

### Requirement: REQ-BPE-007 — A `LedgerGroup`'s projected series MUST be the sum of its resolved member accounts' own projections, never an independent fit of the group's aggregate history

`LedgerGroup` membership MUST be resolved using the identical range +
explicit include/exclude algorithm `budget-core-schema` §3a already
defines (reused, not redesigned). For each month, the group's projected
value MUST be the sum, across resolved members, of each member's own
typed result (`"projected"` amount, `"actual"` amount, or `0` for
`"unprojectable"`), and the group-level result MUST be tagged `"partial"`
whenever any contributing member for that month was `"unprojectable"`. A
group result MUST only itself be `"unprojectable"` when every resolved
member is `"unprojectable"` for that month.

#### Scenario: A group's projection is the sum of member projections, not a group-level fit

- **GIVEN** a `LedgerGroup` resolving to three member accounts, each
  independently projectable with its own growth rate
- **WHEN** the group's projected value for a future month is computed
- **THEN** it equals the sum of the three members' own projected values
  for that month — no group-level growth rate is computed from a summed
  historical series

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testGroupSumsMemberProjections`

#### Scenario: A partially unprojectable group still returns a value, tagged

- **GIVEN** a `LedgerGroup` with two members, one projectable and one with
  fewer than `MIN_VALID_STEPS` valid steps
- **WHEN** the group's projected value is computed
- **THEN** it equals the projectable member's own value alone, and the
  result carries `partial: true` rather than being withheld or silently
  treating the unprojectable member's contribution as `0` without a flag

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testPartialGroupTaggedNotWithheld`

### Requirement: REQ-BPE-008 — Both trend (per-period) and cumulative variants MUST be available, with an account-type-dependent cumulative rule

The `trend` series (actual/projected per REQ-BPE-006) MUST be available
per month. The `cumulative` series MUST also be available, computed as:
for flow accounts (`revenue`/`expenses`) and any `LedgerGroup` composed of
them, a fiscal-year-to-date running sum of the `trend` series, continuous
across the actual/projected seam; for stock accounts
(`assets`/`liabilities`/`equity`) and any `LedgerGroup` composed of them,
`cumulative` MUST equal `trend` exactly (a closing balance is already a
running position by construction — it MUST NOT be re-summed across
months, which would double-count the carried balance).

#### Scenario: A flow account's cumulative series is a continuous running sum across the seam

- **GIVEN** a `revenue` account with actual `netMovement` for months 1-6
  and projected `netMovement` for months 7-9
- **WHEN** the cumulative series is computed
- **THEN** `cumulative(month 7) = cumulative(month 6) + projected(month
  7)`, continuing the same running total the actual months built, with no
  reset or gap at the seam

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testFlowCumulativeIsRunningSumAcrossSeam`

#### Scenario: A stock account's cumulative series equals its trend series

- **GIVEN** an `assets` account with a `closingBalance` trend series
- **WHEN** the cumulative series is computed
- **THEN** `cumulative(month N) == trend(month N)` for every month — the
  closing balances are not summed across months

@e2e exclude pure-calculator arithmetic — verified by
`BudgetProjectionCalculatorTest::testStockCumulativeEqualsTrend`

### Requirement: REQ-BPE-009 — Reads MUST be batched into a fixed, small query count independent of account, group, or month count

`BudgetProjectionReader` MUST resolve all data for a projection request
(any number of accounts, any number of `LedgerGroup`s, up to the 12-month
horizon) using at most 4 `findAll()` calls total: one unfiltered-by-period
`Account` read, one `GLTransaction` read filtered only by
`administrationId`+`state: posted`, one unfiltered-by-period-or-account
`GLLine` read, and — only when any `LedgerGroup` is requested — one
`LedgerGroup` read. `GLLine`↔`GLTransaction` joining MUST follow the
`BbvProgrammeBudgetReader::spendByProgramme()` precedent: an in-memory
index keyed by both the transaction's object id and its
`transactionNumber`. The reader MUST NOT issue a `findAll()` call scoped
to a single account or a single month.

#### Scenario: A projection request for 50 accounts across 12 months issues no more than 4 store queries

- **GIVEN** a request covering 50 accounts across a 12-month trailing
  window, including one `LedgerGroup`
- **WHEN** `BudgetProjectionReader` resolves the data
- **THEN** exactly 4 `findAll()` calls are made against
  `ObjectServiceInterface`, none of them scoped to a single account or a
  single month

@e2e exclude reader query-count regression, no browser-visible surface —
verified by `BudgetProjectionReaderTest::testQueryCountIndependentOfAccountAndMonthCount`
with a mocked `ObjectServiceInterface` asserting call count

### Requirement: REQ-BPE-010 — The growth-rate and extrapolation arithmetic MUST live in a pure calculator with no store access

`BudgetProjectionCalculator` MUST accept only plain data (metric series,
account types, resolved `LedgerGroup` membership) as method arguments and
MUST NOT depend on `ObjectServiceInterface` or any OpenRegister access —
mirroring `BbvProgrammeBudgetCalculator`'s "it reads NOTHING" contract.
All store access (§`REQ-BPE-009`) MUST live exclusively in
`BudgetProjectionReader`.

#### Scenario: The calculator has no constructor dependency on the object store

- **GIVEN** `BudgetProjectionCalculator`'s constructor
- **WHEN** its dependencies are inspected
- **THEN** none of them is `ObjectServiceInterface` or any other
  OpenRegister store-access type — every test in
  `BudgetProjectionCalculatorTest` instantiates it with no mocks

@e2e exclude structural/architectural requirement — verified by code
inspection and by `BudgetProjectionCalculatorTest` requiring no store
mocks

### Requirement: REQ-BPE-011 — Non-goals

This change MUST NOT render any of these series (no grid columns, no
chart lines, no page, no manifest edit — `budget-grid-view`/
`budget-charts`), MUST NOT derive or write `begroot`/budgeted amounts from
contracts or recurring-cost schedules (`budget-known-costs`), MUST NOT
implement scenario or modifier support (`budget-scenarios`), and MUST NOT
patch `AggregationAnnotationValidator` (foundation/openregister scope).

#### Scenario: No rendering, budgeted-derivation, or scenario code appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no Vue component, no chart, no `register.d/` schema edit, no
  contract/recurring-cost `BudgetLine` writer, and no scenario-switching
  logic is present — only `BudgetProjectionReader`,
  `BudgetProjectionCalculator`, `BudgetProjectionService`, and their
  PHPUnit coverage

@e2e exclude negative/scope-boundary requirement — verified by diff
inspection
