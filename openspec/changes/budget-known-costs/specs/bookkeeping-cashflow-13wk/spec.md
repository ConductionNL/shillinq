# Spec: bookkeeping-cashflow-13wk (delta — budget-known-costs)

This delta MODIFIES REQ-CF-005 to add two additive, optional
`CashflowRecurring` fields (`contractReference`, `cpiRatePercent`) so the
begroting programme's known-cost derivation can reuse this schema instead
of declaring a second one. No existing field, enum value, weekly-horizon
behaviour, or the `CashflowRecurringGuard` save precondition is removed or
narrowed.

## Why this delta exists

`budget-known-costs design.md` §1 reads `CashflowRecurring` field-by-field
and finds it already models everything a "known cost" needs except a
`Contract` link and a usable CPI rate. REQ-CF-005's own base scenario
("Annual insurance with CPI indexing") assumes a `GIVEN "CBS published CPI
for 2024 = +3.2%"` — no such feed exists anywhere in this codebase
(verified by grep, `budget-known-costs design.md` §3a); `indexationRule =
CPI_PAST_YEAR` has never had a rate field to read that percentage from.
`budget-known-costs` closes this pre-existing gap with an operator-supplied
rate (`cpiRatePercent`), following the same "operator states the rate"
shape `bookkeeping-ifrs-16-lease.json`'s own `indexationRateOrSource` field
already uses elsewhere in this app, rather than inventing a live CBS
integration. The `contractReference` addition lets a recurring cost be
tagged to the `Contract` it originates from without inventing a parallel
Contract-to-GL-account join schema (`design.md` §1c).

Both additions are consumed by `budget-known-costs`'s own new services
(`KnownCostReader`/`KnownCostScheduleExpander`/`KnownCostBudgetWriter`, that
change's own capability) to derive `BudgetLine` rows for the begroting
programme — this delta only changes what `CashflowRecurring` itself
declares and what `CashflowRecurringGuard` checks on save; the 13-week
weekly-horizon expansion this capability's own REQ-CF-002/REQ-CF-005
scenarios describe is untouched.

## MODIFIED Requirements

### Requirement: REQ-CF-005 — Recurring costs automatic scheduling with lifecycle + CPI indexing, plus an optional Contract link and an operator-supplied CPI rate

The system SHALL satisfy this requirement: Recurring costs automatic
scheduling with lifecycle + CPI indexing, retaining every field this
capability already declares (`recurId`, `label`, `category`, `direction`,
`frequency`, `dagFromMonth`/`monthOfYear`, `standardAmount`,
`indexationRule`, `validFrom`/`validTo`, `administrationId`,
`accountNumberExpense`, the `CashflowRecurringGuard` save precondition —
field names as live in `lib/Settings/register.d/zzp-cashflow-13wk.json`,
which uses English property names; this spec's own original table above
predates that translation and is not corrected by this delta, out of
scope). `CashflowRecurring` additionally declares:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `contractReference` | string (FK) | No | FK to `Contract` (`contract-lifecycle-management`); null means this recurring cost has no originating contract — a dated planned cost (`budget-known-costs` REQ-BKC-001) |
| `cpiRatePercent` | number | No | Operator-supplied annual indexation rate (e.g. `2.5` for 2.5%), consumed when `indexationRule = "CPI_PAST_YEAR"`; null means the rate is not yet known and `CPI_PAST_YEAR` indexation cannot be computed until it is supplied (`budget-known-costs` REQ-BKC-003) |

`CashflowRecurringGuard::validateOnSave()` gains one additional
precondition: when `contractReference` is set and the referenced
`Contract`'s `startDate`/`endDate` are known, `validFrom`/`validTo` must
fall within them (`budget-known-costs` REQ-BKC-002). Every existing
precondition (non-negative amount, frequency-anchor consistency, validity
window ordering, CPI-applicable-to-annual-only) is unchanged.

#### Scenario: An existing `CashflowRecurring` row with no `contractReference`/`cpiRatePercent` remains valid unchanged

- **GIVEN** a `CashflowRecurring` row seeded before this delta, carrying no
  `contractReference` or `cpiRatePercent` value
- **WHEN** the schema is re-validated after this delta lands
- **THEN** the row remains valid — both new fields are absent/null and
  neither is required, and every existing `CashflowRecurringGuard` check
  behaves exactly as before

@e2e exclude backend schema-compatibility check, no browser-visible
behaviour — verified by re-running `node tests/validate-registers.js`
against the amended fragment and confirming existing seed objects still
validate

#### Scenario: A recurring cost tagged to a Contract must stay within that Contract's dates

- **GIVEN** a `Contract` with `startDate: "2027-01-01"`, `endDate` null
- **WHEN** a `CashflowRecurring` row with `contractReference` set to that
  contract and `validFrom: "2026-06-01"` is saved
- **THEN** `CashflowRecurringGuard` rejects the save (`budget-known-costs`
  REQ-BKC-002)

@e2e exclude backend guard precondition, no browser-visible surface —
verified by PHPUnit against the extended `CashflowRecurringGuard`

#### Scenario: CPI indexation with no operator-supplied rate cannot be computed

- **GIVEN** a `CashflowRecurring` row with `indexationRule:
  "CPI_PAST_YEAR"` and `cpiRatePercent` absent
- **WHEN** a consumer (`budget-known-costs`'s `KnownCostScheduleExpander`)
  attempts to compute an indexed amount for it
- **THEN** it receives a typed "needs operator input" result, never a
  fabricated percentage and never the pre-existing base spec's own assumed
  live CBS figure, which this codebase has never implemented
  (`budget-known-costs` REQ-BKC-003)

@e2e exclude pure-calculator arithmetic, no browser-visible surface —
verified by `KnownCostScheduleExpanderTest::testCpiWithoutRateNeedsOperatorInput`
(`budget-known-costs`'s own test suite)
