# Spec: budget-scenarios

## ADDED Requirements

### Requirement: REQ-BSC-001 — `BudgetScenario` MUST be a distinct schema from `CashflowScenario`, not an extension of it

A new schema `BudgetScenario` MUST declare `administrationId`, `name`,
`description`, `isDefault`, and a `draft→active→archived` status
lifecycle. `CashflowScenario` (`bookkeeping-cashflow-13wk`) MUST remain
unmodified by this change — no field renamed, added, or removed on it, and
no live object migrated between the two slugs.

#### Scenario: `BudgetScenario` and `CashflowScenario` never share an object

- **GIVEN** this change's implementation diff
- **WHEN** `lib/Settings/register.d/zzp-cashflow-13wk.json` is inspected
- **THEN** no line of that fragment is modified, and no seed object or
  migrator re-points a `CashflowScenario` object to `BudgetScenario` or
  vice versa

@e2e exclude diff inspection, no browser-visible behaviour

### Requirement: REQ-BSC-002 — Exactly one `BudgetScenario` per administration MAY be default, enforced by atomic demotion

Promoting a `BudgetScenario` to default MUST atomically demote any
previously-default scenario for the same `administrationId` in the same
service call, rather than rejecting the promotion. After a successful
promotion, exactly one `BudgetScenario` for that administration MUST have
`isDefault: true`. When zero scenarios have `isDefault: true`, no scenario
overlay MUST be applied by any consumer — the real `AnnualBudget`/
`BudgetLine` data is shown unmodified.

#### Scenario: Promoting a new default demotes the previous one in the same action

- **GIVEN** `BudgetScenario` A with `isDefault: true` for administration
  `adm-1`, and `BudgetScenario` B (`isDefault: false`) for the same
  administration
- **WHEN** `BudgetScenarioDefaultPromoter::promote(B.id)` is called
- **THEN** B's `isDefault` becomes `true` and A's `isDefault` becomes
  `false`, and exactly one `BudgetScenario` for `adm-1` has `isDefault:
  true` afterward

@e2e budget-scenarios::promote-to-default-demotes-previous-default

#### Scenario: Zero default scenarios means no overlay is applied

- **GIVEN** an administration with no `BudgetScenario` carrying
  `isDefault: true` (no scenario has ever been promoted)
- **WHEN** a consumer resolves "the default scenario view"
- **THEN** it resolves to the real `AnnualBudget`/`BudgetLine` data with no
  scenario delta applied — not an error, not an arbitrarily chosen scenario

@e2e exclude backend default-resolution logic, no browser-visible surface —
verified by PHPUnit against `BudgetScenarioDefaultPromoter`

### Requirement: REQ-BSC-003 — A `BudgetScenarioModifier` MUST express one of three dated modifier kinds, targeting `budget-known-costs`'s own dated planned-cost primitive

`BudgetScenarioModifier` MUST declare `modifierType` as one of
`RECURRING_END`, `RECURRING_AMOUNT_CHANGE`, or `LEDGER_AMOUNT_DELTA`, each
with a required `effectiveDate`. `RECURRING_END`/`RECURRING_AMOUNT_CHANGE`
MUST target a `CashflowRecurring` row by `recurId` — the dated
planned-cost primitive `budget-known-costs` owns (REQ-BKC-001 there); this
change MUST NOT declare a second dated-cost primitive.
`LEDGER_AMOUNT_DELTA` MUST target a `LedgerGroup` and apply its signed
`amountDeltaCents` to `effectiveDate`'s own calendar month only.

#### Scenario: A `RECURRING_END` modifier targets an existing `CashflowRecurring` row by id

- **GIVEN** a real `CashflowRecurring` row with `recurId: "rec-hosting"`
  and `validTo` null
- **WHEN** a `BudgetScenarioModifier` with `modifierType: "RECURRING_END"`,
  `targetRecurId: "rec-hosting"`, `effectiveDate: "2027-09-01"` is created
- **THEN** it saves successfully and references that exact row — no new
  `CashflowRecurring` row is created by this modifier

@e2e budget-scenarios::modifier-crud-reachable

#### Scenario: A `LEDGER_AMOUNT_DELTA` modifier applies to exactly one month

- **GIVEN** a `BudgetScenarioModifier` with `modifierType:
  "LEDGER_AMOUNT_DELTA"`, `targetLedgerGroupId` set, `effectiveDate:
  "2027-03-15"`, `amountDeltaCents: -500000`
- **WHEN** `BudgetScenarioEvaluator` evaluates the owning scenario
- **THEN** the delta is applied to March 2027 only for that `LedgerGroup`;
  every other month is unaffected by this modifier

@e2e exclude pure-calculator arithmetic — verified by
`BudgetScenarioEvaluatorTest::testLedgerAmountDeltaAppliesToSingleMonth`

### Requirement: REQ-BSC-004 — Two modifiers in the same scenario MUST NOT target the same `CashflowRecurring` row with overlapping effective windows

`BudgetScenarioModifierGuard` MUST reject saving a second
`RECURRING_END`/`RECURRING_AMOUNT_CHANGE` modifier in the same scenario
whose `targetRecurId` matches an existing modifier's `targetRecurId` in
that scenario. Modifiers targeting different `recurId`s, or a
`RECURRING_*` modifier alongside a `LEDGER_AMOUNT_DELTA`, MUST be allowed
to coexist and MUST sum additively at evaluation time with no ordering
dependency.

#### Scenario: A second modifier on the same recurring row in the same scenario is rejected

- **GIVEN** a scenario already carrying a `RECURRING_END` modifier
  targeting `recurId: "rec-hosting"`
- **WHEN** a second modifier (any `RECURRING_*` type) targeting the same
  `recurId` in the same scenario is saved
- **THEN** the save is rejected by `BudgetScenarioModifierGuard`

@e2e exclude backend guard precondition, no browser-visible surface —
verified by PHPUnit against `BudgetScenarioModifierGuard`

#### Scenario: Modifiers on different targets sum without an ordering rule

- **GIVEN** a scenario with a `RECURRING_END` on `recurId: "rec-a"` and a
  `LEDGER_AMOUNT_DELTA` on a different `LedgerGroup`
- **WHEN** `BudgetScenarioEvaluator` evaluates the scenario
- **THEN** both modifiers' effects are present in the result, independent
  of the order they are evaluated in

@e2e exclude pure-calculator arithmetic — verified by
`BudgetScenarioEvaluatorTest::testIndependentModifiersSumOrderIndependently`

### Requirement: REQ-BSC-005 — Scenario evaluation MUST be non-destructive: no `BudgetLine` is ever written by this change

`BudgetScenarioEvaluator` MUST compute a side-by-side
`(ledgerGroupId, month) => {base, scenario, delta}` comparison without
writing to any `BudgetLine` object. `RECURRING_*` modifiers MUST be
evaluated by constructing an in-memory, hypothetical copy of the targeted
`CashflowRecurring` row and passing it to `budget-known-costs`'s own pure
`KnownCostScheduleExpander`, never by mutating the real
`CashflowRecurring` row.

#### Scenario: Evaluating a scenario leaves the real budget and the real recurring row unchanged

- **GIVEN** a real `BudgetLine` and a real `CashflowRecurring` row
  targeted by a `RECURRING_AMOUNT_CHANGE` modifier
- **WHEN** `BudgetScenarioEvaluator::evaluate()` runs
- **THEN** the real `BudgetLine`'s stored amounts and the real
  `CashflowRecurring` row's `standardAmount` are unchanged afterward, and
  the evaluator's return value carries the hypothetical `scenario` figure
  separately from the unchanged `base` figure

@e2e budget-scenarios::scenario-comparison-renders-base-and-scenario

#### Scenario: A scenario with zero modifiers evaluates to exactly the base

- **GIVEN** a `BudgetScenario` with no `BudgetScenarioModifier` rows
- **WHEN** it is evaluated
- **THEN** every `(ledgerGroupId, month)` cell's `scenario` value equals
  its `base` value, and `delta` is `0` everywhere

@e2e exclude pure-calculator arithmetic — verified by
`BudgetScenarioEvaluatorTest::testZeroModifiersEqualsBase`

### Requirement: REQ-BSC-006 — A `RECURRING_END`/`RECURRING_AMOUNT_CHANGE` modifier's evaluated effect MUST use the identical arithmetic `budget-known-costs` uses for real generation

`BudgetScenarioEvaluator` MUST call `budget-known-costs`'s own
`KnownCostScheduleExpander::expand()` for both the hypothetical (modified)
and the real (unmodified) view of a targeted `CashflowRecurring` row, and
derive the modifier's per-month delta as the difference between the two —
it MUST NOT implement a second, independent schedule-expansion arithmetic.

#### Scenario: A scenario's projected recurring-cost figure matches what regeneration would actually produce if the modifier became real

- **GIVEN** a `CashflowRecurring` row and a `RECURRING_AMOUNT_CHANGE`
  modifier changing its `standardAmount` from a given `effectiveDate`
- **WHEN** the modifier's hypothetical monthly amounts are computed by the
  evaluator, and separately the same amount change is applied for real to
  the `CashflowRecurring` row and `KnownCostBudgetWriter` regenerates
- **THEN** the two sets of monthly amounts are identical — the scenario
  path and the real-generation path share the same
  `KnownCostScheduleExpander` call

@e2e exclude cross-change arithmetic-consistency check, no browser-visible
surface — verified by a PHPUnit test in this change's own suite that
constructs the hypothetical input identically to how
`KnownCostBudgetWriter` would construct the real one, asserting equal
output from the shared pure expander

### Requirement: REQ-BSC-007 — Reads MUST be batched into a fixed, small query count

`BudgetScenarioReader` MUST resolve all data for evaluating one scenario
using exactly 5 `findAll()` calls total (`BudgetScenario`,
`BudgetScenarioModifier`, `CashflowRecurring`, `BudgetLine`,
`LedgerGroup`), independent of the number of modifiers or `LedgerGroup`s in
scope.

#### Scenario: Evaluating a scenario with 20 modifiers issues exactly 5 store queries

- **GIVEN** a `BudgetScenario` with 20 `BudgetScenarioModifier` rows
- **WHEN** `BudgetScenarioReader` resolves the data for evaluation
- **THEN** exactly 5 `findAll()` calls are made against
  `ObjectServiceInterface`

@e2e exclude reader query-count regression, no browser-visible surface —
verified by `BudgetScenarioReaderTest::testQueryCountIsFixed` with a mocked
`ObjectServiceInterface`

### Requirement: REQ-BSC-008 — Minimal pages MUST exist for `BudgetScenario`, `BudgetScenarioModifier`, and a standalone comparison view, reachable via navigation

`BudgetScenarios`/`BudgetScenarioDetail`, `BudgetScenarioModifiers`/
`BudgetScenarioModifierDetail`, and `BudgetScenarioComparison` MUST all be
reachable from the `Budgets` top-level navigation group, and MUST pass
`npm run check:nav-reachability`.

#### Scenario: An operator can create a scenario, add a modifier, and view the comparison

- **GIVEN** an authenticated user on the `BudgetScenarios` index page
- **WHEN** they create a scenario, add a `LEDGER_AMOUNT_DELTA` modifier,
  and open that scenario's comparison page
- **THEN** the comparison page renders base, scenario, and delta values for
  the affected `LedgerGroup`/month

@e2e budget-scenarios::modifier-crud-reachable

### Requirement: REQ-BSC-009 — A minimal balance-sheet `LedgerGroup` MUST be seeded so `LEDGER_AMOUNT_DELTA` has a valid target, without reversing `budget-core-schema`'s P&L-only default seed

This change MUST seed exactly one balance-sheet-scoped `LedgerGroup`
(`code: "VLA-LIQ"`, `name: "Liquide middelen"`, `accountRanges: [{"from":
"1000", "to": "1099"}]`), sourced from `rj270-balance-sheet.json`'s own
`VLA-LIQ` section, in this change's own register.d fragment — using the
`LedgerGroup` schema exactly as `budget-core-schema` already defines it,
with no field added or reinterpreted. This change MUST NOT seed any other
`rj270-balance-sheet.json` section, and MUST NOT modify
`budget-core-schema`'s own fragment or its P&L-only default-seed decision.

#### Scenario: A fresh import gives `LEDGER_AMOUNT_DELTA` a real target out of the box

- **GIVEN** a fresh OpenRegister import of this change's register fragment,
  on top of `budget-core-schema`'s own P&L-only `LedgerGroup` seed
- **WHEN** an operator creates a `LEDGER_AMOUNT_DELTA` modifier for
  *"amount X transferred to the bank at date X"*
- **THEN** a `LedgerGroup` named "Liquide middelen" exists and is a valid
  `targetLedgerGroupId` — the task brief's own worked example is
  expressible without the operator first creating a `LedgerGroup` by hand

@e2e budget-scenarios::modifier-crud-reachable

#### Scenario: No other balance-sheet section is seeded, and `budget-core-schema`'s own P&L-only default seed is unchanged

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** exactly one `LedgerGroup` seed object is added (`VLA-LIQ`), no
  other `rj270-balance-sheet.json` section (e.g. `VA`, `VLA-VRD`, `EV`) is
  seeded, and `lib/Settings/register.d/budget-core-schema.json` is not
  modified

@e2e exclude diff inspection, no browser-visible behaviour

### Requirement: REQ-BSC-010 — Non-goals

This change MUST NOT implement the spreadsheet-grid UI or its
scenario-selector embedding (`budget-grid-view`), projection/growth-rate
math (`budget-projection-engine`), charts (`budget-charts`), any HR/payroll
concept, a fix to `CashflowScenario`'s missing `result` producer or
`ScenarioCreator.vue`'s dead-code status, or a restoration of
`budget-core-schema`'s excluded balance-sheet `LedgerGroup` hierarchy
beyond the one leaf named in REQ-BSC-009. It MUST NOT write
`BudgetLine.source: "scenario"` rows.

#### Scenario: No grid, projection, chart, HR, CashflowScenario-fix, or balance-sheet-restoration code appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no spreadsheet-grid component or grid-embedded selector, no
  projection-math service, no chart component, no employee/payroll schema
  or field, no edit to `CashflowScenario`/`ScenarioCreator.vue`, no
  `BudgetLine` writer, and no balance-sheet `LedgerGroup` seed beyond
  `VLA-LIQ` (REQ-BSC-009) is present — only `BudgetScenario`,
  `BudgetScenarioModifier`, the guard, the promoter, the evaluator, the
  reader, and the pages named in REQ-BSC-008

@e2e exclude negative/scope-boundary requirement — verified by diff
inspection
