# Spec: budget-core-schema

## ADDED Requirements

### Requirement: REQ-BCS-001 — The colliding `Budget` schema MUST be split into two distinct, non-colliding schemas

`lib/Settings/register.d/bookkeeping-provincies-bbv-variant.json`'s `Budget`
MUST be renamed `BbvProgrammeBudget`, and
`lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`'s
`Budget` MUST be renamed `CommitmentBudget`, per `design.md` §1. After this
change, no two `register.d` fragments MAY declare a full (`type`+`required`)
`components.schemas` definition under the same key.

#### Scenario: The merged effective register no longer carries a colliding `Budget` schema

- **GIVEN** `lib/Settings/register.d/` after this change
- **WHEN** the merged effective register (`SettingsService::deepMergeConfig()`
  output) is inspected for a schema slug `Budget`
- **THEN** no such slug exists; `BbvProgrammeBudget` carries exactly the
  BBV-vocabulary `required` list (`budgetName, totalAmount,
  programmeStructure, status, fiscalYear, administrationId`) and
  `CommitmentBudget` carries exactly the commitment-vocabulary `required`
  list (`administrationId, financialYear, authorised_amount`), with no
  cross-contamination from `array_merge`-concatenated `required` arrays

@e2e exclude backend register-declaration diff, no browser-visible
behaviour — verified by inspecting the merged effective register and by
`node tests/validate-registers.js`'s same-slug-full-definition check
(pending that gate landing via `contracts-single-home`; a manual grep for a
duplicate `"Budget"` key across `register.d/*.json` is the fallback
verification if that gate has not yet merged)

#### Scenario: A BBV-shaped create is no longer refused by cross-vocabulary required fields

- **GIVEN** an operator submitting a new `BbvProgrammeBudget` with only the
  BBV fields (`budgetName`, `totalAmount`, `programmeStructure`, `status`,
  `fiscalYear`, `administrationId`)
- **WHEN** `POST /apps/openregister/api/objects/shillinq/BbvProgrammeBudget`
  is called
- **THEN** the create succeeds — it is no longer rejected with `"The
  required properties (financialYear, authorised_amount) are missing"`,
  the failure `lib/Service/BbvBudgetVocabulary.php`'s docblock recorded as
  measured on the rig before this change

@e2e budget-core-schema::budgets-nav-group-reachable

### Requirement: REQ-BCS-002 — Every consumer of the retired `Budget` slug MUST be updated to its renamed schema

The blast-radius inventory in `design.md` §2a (2 register.d fragments'
schema keys, seed `@self.schema` values and dashboard/aggregation
references, `lib/Service/BbvProgrammeBudgetReader.php`,
`lib/Lifecycle/BudgetBlocker.php`,
`lib/Service/Commitment/CommitmentMaterialisationService.php`,
`src/views/budgetLineCommitmentsHelpers.js`, and 5 PHPUnit test files) MUST
reference `BbvProgrammeBudget` or `CommitmentBudget` as appropriate, and
`lib/Service/BbvBudgetVocabulary.php` MUST be deleted (`design.md` §2c —
its entire purpose, tolerant reading of two colliding vocabularies, no
longer exists once the schemas are distinct).

#### Scenario: No literal `Budget` schema reference survives outside the two renamed schemas' own substrings

- **GIVEN** this change's implementation diff
- **WHEN** `lib/`, `src/`, and `tests/` are grepped for a literal `'Budget'`/
  `"Budget"` schema reference
- **THEN** every remaining match is a substring of `BbvProgrammeBudget` or
  `CommitmentBudget`, and `lib/Service/BbvBudgetVocabulary.php` no longer
  exists

@e2e exclude diff/grep verification, no browser-visible behaviour

### Requirement: REQ-BCS-003 — Any live legacy `Budget` object MUST migrate via a count-abort migrator, never a silent drop

A migrator (`BudgetSchemaSplitMigrator`, `design.md` §2b) MUST classify each
live object currently under the `Budget` slug by which vocabulary its
fields match, re-point it to `BbvProgrammeBudget` or `CommitmentBudget`
accordingly, and MUST abort the entire batch (leaving source data intact)
if any object is unclassifiable or if the migrated count does not equal the
source count.

#### Scenario: An unclassifiable object aborts the whole migration batch

- **GIVEN** a set of live `Budget` objects where one carries neither the
  BBV vocabulary (`totalAmount`/`programmeStructure`) nor the commitment
  vocabulary (`authorised_amount`/`financialYear`)
- **WHEN** `BudgetSchemaSplitMigrator::migrateBatch()` runs
- **THEN** `assertCountsMatch()` throws `RuntimeException`, and no object in
  the batch — including the classifiable ones — is re-pointed

@e2e exclude backend migration logic, no browser-visible surface —
verified by PHPUnit against `BudgetSchemaSplitMigrator`

#### Scenario: The measured-zero live count is re-verified before the rename ships

- **GIVEN** the shared dev instance measured `Budget` `total: 0` on
  2026-08-20
- **WHEN** this change is implemented
- **THEN** the same live-count query is re-run immediately before the
  rename lands, and — per this repo's own `payroll-leaves-to-hrmq`
  precedent — against every other real shillinq deployment before the
  rename ships there, not assumed to still hold from the measurement date

@e2e exclude live-data verification task, not a repeatable browser
assertion — recorded in the PR description per `tasks.md` group 4

### Requirement: REQ-BCS-004 — `LedgerGroup` MUST model per-administration, ordered, nestable GL account groupings

`LedgerGroup` MUST scope to `administrationId`, support sibling `order` and
`parentLedgerGroupId` nesting, and resolve membership at evaluation time
from an optional `accountRanges` array (inclusive `{from, to}` pairs) plus
explicit `includedAccountNumbers`/`excludedAccountNumbers` arrays —
per `design.md` §3a's synthesis of the RJ270 statement, rubriek-mapping,
and `ChartOfAccountsMapping` precedents. `effectiveFrom`/`effectiveTo`
(nullable, open-ended) MAY scope a group to a date range, per the
`ChartOfAccountsMapping` precedent.

#### Scenario: A range-based group resolves its members correctly, with an explicit exclude honoured

- **GIVEN** a `LedgerGroup` with `accountRanges: [{from: "1000", to: "1099"}]`
  and `excludedAccountNumbers: ["1050"]`
- **WHEN** its resolved member accounts are computed
- **THEN** every `Account` with `accountNumber` in `1000`–`1099` is a
  member except `1050`

@e2e budget-core-schema::ledger-group-seeded-on-import

#### Scenario: A nested `LedgerGroup` is reachable from its parent

- **GIVEN** a `LedgerGroup` with `parentLedgerGroupId` set to another
  `LedgerGroup`'s id
- **WHEN** the parent's detail page is opened
- **THEN** the child `LedgerGroup` is listed among its children

@e2e budget-core-schema::ledger-group-seeded-on-import

### Requirement: REQ-BCS-005 — `LedgerGroup` MUST ship seeded from `rj270-pl.json`, P&L-shaped

**Amended (2026-08-20)**: on import, `LedgerGroup` MUST carry one seeded
row per `level: 1` section of `lib/Settings/statements/rj270-pl.json`
(16 leaves: `NETO`/`WVPV`/`GEAC`/`OVOP`/`KPVO`/`INKW`/`LONE`/`SOCL`/`AFSC`/
`HUIS`/`EXPL`/`VKKO`/`ALGK`/`RBAT`/`RLST`/`VPB`), each with that section's
own `accountRange`, plus 3 seeded parent `LedgerGroup`s (`Omzet`,
`Personeel`, `Kostprijs van de omzet`) nesting the relevant leaves via
`parentLedgerGroupId`, per `design.md` §3c. `LedgerGroup` MUST NOT ship
seeded from `lib/Settings/statements/rj270-balance-sheet.json` by default —
a begroting is a monthly-phased flow plan and a balance-sheet stock account
is not a begroting use case this programme has identified (`design.md` §3c
states this explicitly). Each seeded row MUST carry
`@self.seedExemption: "anchor"` per ADR-001 — this is canonical BW 2:373 /
RJ270 statutory reference data, not deletable editorial example data.

#### Scenario: A fresh import ships day-one, P&L-shaped `LedgerGroup` data

- **GIVEN** a fresh OpenRegister import of this change's register fragment
- **WHEN** `LedgerGroups` is opened
- **THEN** the seeded RJ270-PL-derived rows (e.g. "Omzet", "Personeel",
  "Huisvestingskosten") are present, each carrying
  `@self.seedExemption: "anchor"`, and no `rj270-balance-sheet.json`-derived
  row (e.g. "Voorraden", "Liquide middelen") is present

@e2e budget-core-schema::ledger-group-seeded-on-import

#### Scenario: A parent `LedgerGroup`'s value rolls up its children when it has no own `BudgetLine`

- **GIVEN** the seeded `Personeel` `LedgerGroup` (parent of `Lonen en
  salarissen` and `Sociale lasten en pensioenlasten`, no `BudgetLine` of its
  own) with a `BudgetLine` of `month01Amount` EUR 30,000 against `Lonen en
  salarissen` and EUR 8,000 against `Sociale lasten en pensioenlasten`
- **WHEN** `Personeel`'s own budgeted value for that month is resolved
- **THEN** it resolves to EUR 38,000 (the recursive sum of its children),
  per `design.md` §3d's rollup rule

@e2e exclude backend rollup-resolution logic, no browser-visible surface —
verified by PHPUnit against `BudgetVsActualsReader`

### Requirement: REQ-BCS-006 — `AnnualBudget` MUST carry a lifecycle and enforce exactly one default per administration + fiscal year

`AnnualBudget` MUST declare `administrationId`, `fiscalYear`, `name`,
`isDefault` (boolean), and an `x-openregister-lifecycle`
`draft -> active -> closed` (per `design.md` §4a). The `activate` transition
MUST be guarded by `AnnualBudgetDefaultGuard`, which MUST reject activation
if another `AnnualBudget` with `isDefault=true` already exists for the same
`administrationId` + `fiscalYear`.

#### Scenario: A second default for the same administration and fiscal year is rejected

- **GIVEN** an `AnnualBudget` with `isDefault=true`, already `active`, for
  administration `adm-1` fiscal year 2027
- **WHEN** a second `draft` `AnnualBudget` with `isDefault=true` for the
  same administration and fiscal year attempts the `activate` transition
- **THEN** the transition is rejected by `AnnualBudgetDefaultGuard`

@e2e exclude lifecycle-transition guard, no browser-visible surface —
verified by PHPUnit against `AnnualBudgetDefaultGuard`, mirroring
`BudgetBlocker`'s own test treatment

#### Scenario: A default for a different fiscal year is accepted

- **GIVEN** an `AnnualBudget` with `isDefault=true`, `active`, for
  administration `adm-1` fiscal year 2027
- **WHEN** a `draft` `AnnualBudget` with `isDefault=true` for administration
  `adm-1` fiscal year 2028 attempts `activate`
- **THEN** the transition succeeds

@e2e exclude lifecycle-transition guard, no browser-visible surface —
verified by PHPUnit against `AnnualBudgetDefaultGuard`

### Requirement: REQ-BCS-007 — `BudgetLine` MUST cross `AnnualBudget` and `LedgerGroup` with 12 monthly phased amounts and a source marker

`BudgetLine` MUST declare `annualBudgetId` (FK), `ledgerGroupId` (FK),
`source` (enum `manual|contract|recurring|projected|scenario`, default
`manual`), and `month01Amount` through `month12Amount` (integer, minor
units, default 0 each) — per `design.md` §5a. This change writes only
`source = "manual"` lines; the other enum values are declared for
`budget-known-costs`, `budget-projection-engine`, and `budget-scenarios`
to populate.

#### Scenario: A manually entered budget line persists its 12 monthly amounts

- **GIVEN** an operator creating a `BudgetLine` under a given `AnnualBudget`
  and `LedgerGroup`
- **WHEN** they enter values for each of the 12 monthly amount fields and
  save
- **THEN** the `BudgetLine` detail page renders all 12 values unchanged on
  reload, with `source: "manual"`

@e2e budget-core-schema::budget-line-monthly-columns-editable

### Requirement: REQ-BCS-008 — The budget-vs-actuals roll-up MUST be computed by a PHP service as the primary, tested path

Given the platform hazard that `x-openregister-aggregations`/
`-calculations` are validated against the *declaring* schema's own
properties for cross-schema filters rather than the *target* schema's,
silently discarding any cross-schema annotation (`design.md` §6a),
`BudgetVsActualsReader`/`BudgetVsActualsCalculator` MUST be the primary,
PHPUnit-tested path for joining `BudgetLine` to actuals via each line's
`LedgerGroup`-resolved member accounts. **Amended (2026-08-20)**: actuals
MUST be computed directly from `GLTransaction` + `GLLine` + `Account` (the
same batched, dual-keyed-`transactionId` shape
`BbvProgrammeBudgetReader::spendByProgramme()` and
`budget-projection-engine`'s own reader use), NOT from `TrialBalanceLine` —
`TrialBalanceService.php`'s own docblock confirms no `TrialBalanceLine` row
is ever persisted, so a reader expecting queryable historical rows there
would silently report near-zero actuals everywhere. Any declarative
`x-openregister-aggregations` entry expressing the same shape MUST be
documentation-only, explicitly commented as unverified pending a positive
control, and MUST NOT be the path any page depends on.

#### Scenario: The PHP roll-up returns budgeted vs. actual per budget line, computed from GL activity

- **GIVEN** a `BudgetLine` with `month01Amount` EUR 10,000 under a
  `LedgerGroup` resolving to account `1000`, and one or more posted
  `GLTransaction`/`GLLine` pairs booking EUR 8,000 of debit activity to
  account `1000` with a `postingDate` in January 2027
- **WHEN** `BudgetVsActualsReader`/`Calculator` compute the roll-up for that
  line and month
- **THEN** the result reports budgeted EUR 10,000, actual EUR 8,000 —
  resolved from the `GLTransaction`/`GLLine` activity, not from any
  `TrialBalanceLine` row

@e2e exclude backend roll-up computation, no browser-visible surface —
verified by PHPUnit against `BudgetVsActualsReader`/`Calculator`, mirroring
`BbvProgrammeBudgetReader`/`Calculator`'s own treatment

#### Scenario: The reader's query count stays within its bound regardless of window size

- **GIVEN** a request spanning 12 months across every seeded `LedgerGroup`
- **WHEN** `BudgetVsActualsReader` resolves actuals for the whole request
- **THEN** it issues at most 4 `findAll()` calls for the GL-derived side
  (`Account`, `GLTransaction`, `GLLine`, `LedgerGroup`) plus 1 for the
  `BudgetLine` batch — 5 total, not scaling with the number of months,
  accounts, or `LedgerGroup`s in scope

@e2e exclude query-count regression — verified by PHPUnit against a
call-counting mock of `ObjectServiceInterface`, mirroring
`budget-projection-engine`'s own §8 query-budget regression test

#### Scenario: The declarative aggregation hazard is checked, not assumed

- **GIVEN** a fresh register import of this change's fragments
- **WHEN** `nextcloud.log` is grepped for `"annotation on schema"` and the
  `CommitmentBudget.outstanding_commitments` and
  `committedVsRealisedPerBudgetLine` aggregation endpoints are queried
  directly for non-empty rows against seeded data
- **THEN** the actual outcome (discarded or materialised) is recorded in
  `openspec/specs/bookkeeping-verplichtingenadministratie/spec.md`'s delta
  and `design.md` §11.2, not silently assumed either way

@e2e exclude platform-diagnostic task, not a repeatable browser assertion

### Requirement: REQ-BCS-009 — Minimal index/detail pages MUST exist for each new schema, reachable via navigation

`AnnualBudget`, `LedgerGroup`, and `BudgetLine` MUST each have an `index`
and `detail` page (6 pages total, `design.md` §7a), reachable from a new
`Budgets` top-level navigation group, and MUST pass
`npm run check:nav-reachability` with no orphaned route or dangling menu
entry.

#### Scenario: The Budgets navigation group and its pages are reachable

- **GIVEN** an authenticated user viewing the main navigation after this
  change (and after this change's byte-budget sequencing gate, `design.md`
  §8, has cleared)
- **WHEN** they open the `Budgets` top-level group
- **THEN** `AnnualBudgets`, `LedgerGroups`, and `BudgetLines` are listed as
  children, and each resolves to its index page; each index page's rows
  navigate to a working detail page

@e2e budget-core-schema::budgets-nav-group-reachable

### Requirement: REQ-BCS-010 — This change's own pages MUST NOT ship until the manifest byte-budget gate has headroom for them

Per `design.md` §8, this change's 6 new pages (estimated 5,676–7,656 bytes)
exceed the manifest byte-budget gate's headroom as measured on 2026-08-20
(2,927 bytes). The page-adding tasks (`tasks.md` group 9) MUST NOT run
until `node tests/check-manifest-budget.js` shows sufficient headroom
(expected after `nav-six-clusters`/PR #923 merges, which frees ~29,253
bytes) — or, if forced to land before that, the schema/service tasks
(groups 1–8) MAY ship without pages, deferring group 9 as a follow-up.

#### Scenario: The manifest byte-budget gate passes after pages are added

- **GIVEN** this change's page-adding tasks have run
- **WHEN** `node tests/check-manifest-budget.js` executes
- **THEN** it exits 0, and the reported total does not exceed the budget

@e2e exclude CI gate assertion, not a browser scenario — verified by
`node tests/check-manifest-budget.js` in CI

### Requirement: REQ-BCS-011 — Non-goals

This change MUST NOT implement the spreadsheet-grid UI (`budget-grid-view`),
projection math (`budget-projection-engine`), contract/recurring-cost
derivation writing `BudgetLine.source` values other than `manual`
(`budget-known-costs`), scenario/modifier support beyond the one-default
guard (`budget-scenarios`), or budget-vs-actuals charts (`budget-charts`).
It MUST NOT patch `AggregationAnnotationValidator` (openregister/foundation-
repo scope, out of bounds for this app-repo change).

#### Scenario: No grid, projection, contract-derivation, scenario, or chart code appears in this change's diff

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected
- **THEN** no spreadsheet-grid component, projection-math service,
  contract/recurring `BudgetLine` writer, scenario-switching logic, or
  chart component is present — only the schema, migrator, PHP roll-up
  service, and the 6 minimal pages named in REQ-BCS-009

@e2e exclude negative/scope-boundary requirement — verified by diff
inspection
