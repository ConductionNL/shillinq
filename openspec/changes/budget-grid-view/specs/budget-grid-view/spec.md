# Spec: budget-grid-view

## ADDED Requirements

### Requirement: REQ-BGV-001 — The grid MUST render any operator-selected period range at a selectable granularity

The begroting grid MUST accept a `startPeriod`/`endPeriod` calendar-month
range (not constrained to a single fiscal year or calendar year) and a
`granularity` (`month` default, `quarter`, `year`), and generate its
columns from that range at that granularity (`design.md` §2a). When the
range spans more than one fiscal year, each column MUST independently
resolve the default `AnnualBudget` for its own calendar month's fiscal
year (`design.md` §2b); a column in a fiscal year with no default
`AnnualBudget` MUST render its budget value as an explicit empty/dash
state, distinct from a `0` value.

#### Scenario: A range crossing two fiscal years resolves each column against its own year's default budget

- **GIVEN** a displayed range of November 2026 - February 2027, with a
  default `AnnualBudget` for fiscal year 2026 but none yet for 2027
- **WHEN** the grid renders its columns
- **THEN** the November/December 2026 columns show budget values from the
  2026 `AnnualBudget`'s `BudgetLine`s, and the January/February 2027
  columns show an explicit empty/dash budget state, not `0`

@e2e exclude backend column/fiscal-year resolution — verified by PHPUnit
against `BudgetGridReader`, mirroring `budget-core-schema`'s own treatment
of cross-object resolution logic

#### Scenario: Quarter granularity aggregates three monthly `BudgetLine` amounts per column

- **GIVEN** a `BudgetLine` with `month01Amount`/`month02Amount`/
  `month03Amount` set
- **WHEN** the grid is viewed at `quarter` granularity for Q1
- **THEN** the Q1 column's budget value is the sum of the three monthly
  amounts

@e2e budget-grid-view::grid-renders-rows-and-columns

### Requirement: REQ-BGV-002 — Rows MUST be the current administration's `LedgerGroup` tree, expandable to child groups or resolved member accounts

Root rows MUST be every `LedgerGroup` with `parentLedgerGroupId === null`
for the operator's currently selected administration, ordered by `order`.
Clicking a row's expand control MUST reveal either its child `LedgerGroup`s
(when any exist) or its resolved member `Account`s (`accountRanges` ∪
`includedAccountNumbers` minus `excludedAccountNumbers`, per
`budget-core-schema` §3a's resolution — reused, not re-derived) when it has
no children (`design.md` §1a-§1b). The full `LedgerGroup` tree and resolved
member-account lists for the administration MUST be fetched once, upfront;
expanding or collapsing a row MUST NOT issue an additional network request
(`design.md` §1c).

#### Scenario: Expanding a verzamelpost with child groups reveals them, not accounts

- **GIVEN** a root `LedgerGroup` "Personeel" with two child `LedgerGroup`s
  "Lonen" and "Sociale lasten"
- **WHEN** the operator expands "Personeel"
- **THEN** "Lonen" and "Sociale lasten" appear as child rows, and no
  `Account` row appears directly under "Personeel"

@e2e budget-grid-view::verzamelpost-expand-reveals-children

#### Scenario: Expanding a leaf verzamelpost reveals its resolved grootboek accounts

- **GIVEN** a leaf `LedgerGroup` (no children) with `accountRanges:
  [{from: "4000", to: "4099"}]` and `excludedAccountNumbers: ["4050"]`
- **WHEN** the operator expands it
- **THEN** every `Account` numbered 4000-4099 except 4050 appears as a
  child row

@e2e budget-grid-view::verzamelpost-expand-reveals-children

#### Scenario: Expanding and re-collapsing ten rows issues no additional queries

- **GIVEN** a grid already rendered with its initial payload loaded
- **WHEN** the operator expands and collapses ten different rows in
  sequence
- **THEN** no additional network request is issued beyond the initial
  payload fetch

@e2e exclude network-call-count assertion, verified by PHPUnit query-count
test against `BudgetGridReader` (`design.md` §1c) and by inspecting network
activity in the Playwright run for group 2's spec, not a dedicated assertion

### Requirement: REQ-BGV-003 — A column MUST show actuals + deviation only when its period is closed, never a `today`-relative guess

A column is past — and MUST render its actual amount and deviation from
budget — when either (a) a `FiscalPeriod` exists for the administration
whose `startDate`/`endDate` exactly match the column's own calendar span
and whose `state` is `closed` or `audit-locked`, or (b) the column's
calendar span is fully contained within a coarser `FiscalPeriod` (e.g. a
month within a closed quarterly `FiscalPeriod`) whose `state` is `closed`
or `audit-locked` (`design.md` §2c, amended). A column whose matching or
containing `FiscalPeriod` is `open`/`closing`, or for which none exists at
all, MUST render its budget value only. The actual value for a past column
MUST be computed from `GLTransaction`/`GLLine` activity for exactly that
column's own calendar span (never from a coarser period's own figure) —
apportionment is never needed, so no "actuals unavailable at this
granularity" fallback exists.

#### Scenario: A closed period shows actuals and deviation

- **GIVEN** a `FiscalPeriod` for January 2026 with `state: "closed"`, and
  posted `GLTransaction`/`GLLine` activity for the relevant account in
  January 2026 producing an actual amount different from the `BudgetLine`'s
  `month01Amount`
- **WHEN** the January 2026 column renders
- **THEN** it shows the actual amount (not the budget amount) and a
  text-labelled deviation from budget

@e2e budget-grid-view::past-column-shows-actuals-and-deviation

#### Scenario: An open or closing period still shows budget only

- **GIVEN** a `FiscalPeriod` for February 2026 with `state: "open"`
- **WHEN** the February 2026 column renders
- **THEN** it shows the budget amount, not an actual amount, even though
  the period's calendar span may already be in the past relative to today

@e2e exclude past/present boundary rule verified against seed state, not
today's date — covered by `budget-grid-view::past-column-shows-actuals-and-deviation`'s
own negative case in the same Playwright spec

#### Scenario: A month contained within a closed quarterly `FiscalPeriod` shows actuals for exactly that month, not the quarter

- **GIVEN** an administration whose only `FiscalPeriod` for Q1 2026 is
  quarterly (`periodId: "2026-Q1"`, `state: "closed"`), the grid viewed at
  month granularity, and posted `GLTransaction`/`GLLine` activity dated in
  January 2026 only
- **WHEN** the January 2026 column renders
- **THEN** it shows the actual amount for exactly January's own GL activity
  (not one-third of the quarter's total, and not the budget figure) —
  January counts as past because it is fully contained within the closed
  Q1 `FiscalPeriod`

@e2e exclude granularity-containment logic, verified by PHPUnit against
`BudgetGridReader::pastColumns()`

### Requirement: REQ-BGV-004 — The deviation sign convention MUST be derived per account type, never a single fixed sign

Deviation for a `revenue`-type resolved member account MUST be computed as
`actual − budget` (positive = favorable, exceeded budget); for an
`expenses`-type account MUST be computed as `budget − actual` (positive =
favorable, under budget). A `LedgerGroup` row's deviation MUST be the sum
of its correctly-signed per-member deviations, never a single row-wide sign
applied to a mixed-type sum (`design.md` §2d).

#### Scenario: An expense account over budget shows an unfavorable deviation, a revenue account over budget shows favorable

- **GIVEN** an expense account with actual EUR 60,000 against a budgeted
  EUR 50,000 (actual exceeds budget by 10,000), and a revenue account with
  actual EUR 60,000 against a budgeted EUR 50,000 (identical raw
  difference)
- **WHEN** each account's deviation is computed
- **THEN** the expense account's deviation is EUR −10,000 (unfavorable,
  overspent) and the revenue account's deviation is EUR +10,000 (favorable,
  exceeded target) — the identical raw `actual − budget` difference
  produces opposite favorable/unfavorable results

@e2e exclude sign-convention arithmetic — verified by PHPUnit against
`BudgetGridCalculator`, one case per `accountType` (`design.md` §2d, the
task brief's own explicit "getting this wrong inverts the whole screen"
warning)

#### Scenario: Deviation is displayed as text, not colour alone

- **GIVEN** a rendered deviation cell, favorable or unfavorable
- **WHEN** the cell is inspected
- **THEN** a text label (e.g. "onder begroting"/"boven begroting" or
  equivalent) accompanies the signed number — colour alone MUST NOT be the
  only indicator (WCAG 2.1 AA, matching `BudgetLineCommitments.vue`'s own
  existing text-labelled-column precedent)

@e2e budget-grid-view::past-column-shows-actuals-and-deviation

### Requirement: REQ-BGV-005 — The final `TOTAAL` column MUST show a running cumulative pair, not a single blended total

The grid's final column MUST show, per row: a begroot cumulative value (the
unconditional sum of budget across every displayed column, including
future ones) and a werkelijk cumulative value (the running sum of actuals
across only the columns that are past per REQ-BGV-003), each with its own
deviation computed from the cumulative pair using the REQ-BGV-004 sign
convention (`design.md` §3).

#### Scenario: The cumulative column sums only realised actuals for the running total, while the budget total includes future months

- **GIVEN** a displayed range of January-June 2026 where only January and
  February are closed `FiscalPeriod`s
- **WHEN** the `TOTAAL` column renders
- **THEN** the begroot cumulative value sums all six months' budget
  amounts, and the werkelijk cumulative value sums only January and
  February's actuals — March through June contribute nothing to the
  werkelijk cumulative

@e2e exclude cumulative-sum arithmetic — verified by PHPUnit against
`BudgetGridCalculator` (`design.md` §3); the column's presence and
non-emptiness is covered by `budget-grid-view::grid-renders-rows-and-columns`

### Requirement: REQ-BGV-006 — Row expand/collapse MUST be keyboard-operable (ADR-059)

Every row's expand/collapse control MUST be operable by keyboard: `role=
"button"`, `tabindex="0"`, `:aria-expanded` reflecting current state, and a
`keydown`/`keyup` handler for both Enter and Space, in addition to pointer
`@click` (ADR-059 Decision 1, `design.md` §6).

#### Scenario: A row expands via Enter or Space without a pointer click

- **GIVEN** a collapsed root `LedgerGroup` row with keyboard focus
- **WHEN** the operator presses Enter (or, separately, Space)
- **THEN** the row expands identically to a pointer click, and
  `aria-expanded` updates to `true`

@e2e budget-grid-view::expand-keyboard-operable

### Requirement: REQ-BGV-007 — A grootboek row MUST navigate to that account's detail page

Clicking (or activating via keyboard) an `Account` leaf row MUST navigate
to `ChartOfAccountsDetail` (`/chart-of-accounts/:id`) for that account's
id — implemented as a real navigation control (link/routed button), not a
`@click` handler on a non-interactive element (`design.md` §6, ADR-059
Decision 3).

#### Scenario: Clicking a grootboek row opens its Chart of Accounts detail page

- **GIVEN** an expanded verzamelpost showing its resolved `Account` leaf
  rows
- **WHEN** the operator clicks one of them
- **THEN** the browser navigates to `/chart-of-accounts/:id` for that
  account, rendering the existing `ChartOfAccountsDetail` page

@e2e budget-grid-view::grootboek-drill-through-navigates

### Requirement: REQ-BGV-008 — Subtotal/derived rows (Bruto Marge, Kosten, Bedrijfsresultaat, %) MUST be a page-config concern, not a schema field

Computed rows MUST be declared in the `BudgetGrid` page's own manifest
config as `<code> [+|-] <code> …` arithmetic formula references over a
single flat codespace (root `LedgerGroup` `code`s and other `computedRows`
`code`s resolve identically), each carrying its own explicit
`favorableDirection` (`"higher"`|`"lower"`) since a computed row has no
resolved member accounts to derive one from — modelled on `rj270-pl.json`'s
existing `sum-group:<group>`/`section:<code> ± section:<code>` convention
and per-section `sign` tag, adapted to this schema's flat codespace
(`design.md` §4). This requirement MUST NOT add any field to the
`LedgerGroup`, `AnnualBudget`, or `BudgetLine` schemas `budget-core-schema`
defines; three of `rj270-pl.json`'s groupings (`Omzet`, `Personeel`,
`Kostprijs van de omzet`) are real parent `LedgerGroup`s instead, resolved
by simple rollup-sum (`budget-core-schema design.md` §3d), not by a
computed row.

#### Scenario: Bedrijfsresultaat computes as Bruto Marge minus Kosten across every column, including TOTAAL

- **GIVEN** computed rows `bruto-marge`
  (`formula: "omzet - kostprijs-van-de-omzet"`), `kosten`
  (`formula: "personeel + huisvesting + afschrijvingen-op-vaste-activa +
  exploitatie-en-machinekosten + verkoopkosten + algemene-kosten"`), and
  `bedrijfsresultaat` (`formula: "bruto-marge - kosten"`)
- **WHEN** the grid renders any column, including `TOTAAL`
- **THEN** the `bedrijfsresultaat` row's value in that column equals the
  `bruto-marge` row's value minus the `kosten` row's value in the same
  column

@e2e exclude computed-row formula evaluation — verified by PHPUnit/unit
test against the formula evaluator (`design.md` §4), not a browser
assertion of arithmetic correctness; the rows' presence is covered by
`budget-grid-view::grid-renders-rows-and-columns`

#### Scenario: The full waterfall from Bruto Marge to Nettoresultaat is representable

- **GIVEN** the six computed rows in `design.md` §4
  (`bruto-marge`/`kosten`/`bedrijfsresultaat`/`financieel-resultaat`/
  `resultaat-voor-belastingen`/`nettoresultaat`), matching
  `rj270-pl.json`'s own `SOM-OPB → SOM-KOS → BEDR-RES → FIN-RES → RES-VBB →
  NET-RES` waterfall
- **WHEN** the grid renders
- **THEN** `nettoresultaat`'s value in any column equals
  `bedrijfsresultaat + financieel-resultaat - vennootschapsbelasting` for
  that same column

@e2e exclude computed-row formula evaluation — verified by PHPUnit/unit
test against the formula evaluator

### Requirement: REQ-BGV-009 — Query budget: a grid render MUST cost a bounded, flat number of reads, never one per row or one per column

Per `design.md` §1c, one grid render MUST issue at most 7 OpenRegister
`findAll` calls total — 3 for `BudgetGridReader`'s own reads
(`LedgerGroup`, `FiscalPeriod`, `BudgetLine`) plus at most 4 delegated to
`budget-core-schema`'s `BudgetVsActualsReader` (`Account`, `GLTransaction`,
`GLLine`, `LedgerGroup`) — a flat constant, never a count that scales with
the number of `LedgerGroup` rows, the number of rows the operator expands,
or the number of displayed columns (actuals are fetched once, unfiltered by
period, and bucketed by calendar month in memory — not once per past
column).

#### Scenario: A render with 50 `LedgerGroup` rows and 12 displayed columns costs the same query count as one with 5 rows and 3 columns

- **GIVEN** two administrations, one with 5 `LedgerGroup`s viewed over 3
  columns and one with 50 `LedgerGroup`s viewed over 12 columns, with
  differing numbers of closed periods in each range
- **WHEN** each grid renders
- **THEN** both issue the identical, flat number of `findAll` calls —
  neither the row count nor the column count nor the number of past/closed
  columns affects the query count

@e2e exclude query-count assertion — verified by PHPUnit against
`BudgetGridReader` with a call-counting mock/spy (`design.md` §1c), per the
fleet's own recorded facet-composition query-multiplication incident this
requirement exists to avoid repeating

### Requirement: REQ-BGV-010 — Non-goals

This change MUST NOT implement projection math (`budget-projection-engine`),
contract/recurring cost derivation writing non-`manual` `BudgetLine.source`
values (`budget-known-costs`), scenario/modifier support
(`budget-scenarios`), charts (`budget-charts`), or a multi-administration
consolidated view. It MUST NOT add, rename, or redesign any field on
`LedgerGroup`, `AnnualBudget`, or `BudgetLine`. (Apportioning a
coarser-cadence `FiscalPeriod`'s actuals into a finer column granularity
was an earlier draft's non-goal; `design.md` §2c's amendment resolved the
underlying problem directly — actuals are always computed from GL activity
at the column's own exact calendar span — so there is no apportionment
logic left to avoid building.)

#### Scenario: No projection, contract-derivation, scenario, chart, multi-administration, or schema-redesign code appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no projection-math service, contract/recurring `BudgetLine`
  writer, scenario-switching logic, chart component, multi-administration
  aggregation, or edit to `budget-core-schema`'s own `register.d` schema
  fragment is present — only the grid page, its backend reader/calculator,
  and the manifest/nav additions named in REQ-BGV-006/REQ-BGV-007

@e2e exclude negative/scope-boundary requirement — verified by diff
inspection
