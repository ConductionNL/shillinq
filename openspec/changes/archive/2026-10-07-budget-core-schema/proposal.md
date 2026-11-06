# Change: budget-core-schema

## Why

This change opens the begroting (budgeting) wave — the flagship feature of
the shillinq compliance/begroting programme. Before any budgeting UI can be
built, shillinq needs a schema foundation that does not already contradict
itself.

**It currently does.** `lib/Settings/register.d/` declares a **full
`components.schemas.Budget` twice**, once in
`bookkeeping-provincies-bbv-variant.json:67` (a BBV programme budget:
`budgetName`/`totalAmount`/`programmeStructure`/`status`/`fiscalYear`) and
once in `bookkeeping-verplichtingenadministratie.json:922` (a commitment
budget-capacity bucket: `administrationId`/`financialYear`/
`authorised_amount`/`free_capacity`). `SettingsService::deepMergeConfig()`
(`lib/Service/SettingsService.php:1563-1582`) unions same-key
`components.schemas` objects — `properties` as a union, `required` as an
**undeduplicated `array_merge`**, scalars last-write-wins by alphabetical
file order — so the two fragments do not coexist, they collide into one
merged schema neither author intended. This is not theoretical: shillinq
already ships `lib/Service/BbvBudgetVocabulary.php`, a shim whose own
docblock states *"THIS CLASS EXISTS BECAUSE `Budget` IS DECLARED TWICE, AND
THE TWO DECLARATIONS SHARE NO FIELD NAMES"*, and records a live production
failure: `POST .../objects/shillinq/Budget -> 400 "The required properties
(financialYear, authorised_amount) are missing."` Live re-verification
(2026-08-20, `localhost:8080`): the imported merged schema (id 1114) carries
icon `"Wallet"` (the verplichtingen fragment's, since it sorts after
`bookkeeping-provincies-bbv-variant.json` alphabetically), a 9-entry
`required` array with `administrationId` duplicated, and a 17-key property
union — and **zero live `Budget` objects exist** (`_limit=1` → `total: 0`),
consistent with the merged schema silently rejecting every BBV-shaped
create.

A begroting feature cannot be built on top of a schema that already refuses
half its own inputs. This change:

1. **Resolves the collision** by renaming both domain-specific schemas —
   `BbvProgrammeBudget` (provincial/BBV) and `CommitmentBudget`
   (verplichtingen/commitment capacity) — and updates every consumer in the
   blast radius, with a count-abort migrator for any live legacy data.
2. **Adds `LedgerGroup`** (verzamelpost): the reusable per-administration,
   ordered, nestable grouping of GL accounts that both the RJ270 statement
   manifests and the budget grid need, seeded from existing statement +
   rubriek-mapping precedents so day-one data exists.
3. **Adds `AnnualBudget`**: the fiscal-year budget container, with a
   lifecycle and the one-default invariant scenarios will need later,
   aligned now rather than retrofitted.
4. **Adds `BudgetLine`**: `AnnualBudget` × `LedgerGroup` with 12 monthly
   phased amounts and a `source` marker, matching the user's mental model
   of a spreadsheet with monthly columns.
5. **Specs the budget-vs-actuals roll-up as a PHP service first**, because
   `x-openregister-aggregations`/`-calculations` are currently validated
   wrong and silently discarded platform-wide for any cross-schema filter
   (`AggregationAnnotationValidator` checks the filter field names against
   the *declaring* schema, not the *target* schema) — the exact failure
   class this change's own consumer sweep finds live evidence of in the
   existing `CommitmentBudget.outstanding_commitments` aggregation and the
   existing `committedVsRealisedPerBudgetLine` aggregation this rename
   touches (see `design.md` §6).
6. **Ships minimal index/detail pages** for the three new schemas so gate
   coverage exists on day one; the real spreadsheet-grid UI is the
   follow-up change `budget-grid-view`.

## What Changes

- **RENAME** (schema): `Budget` (provincies-bbv fragment) →
  `BbvProgrammeBudget`; `Budget` (verplichtingenadministratie fragment) →
  `CommitmentBudget`. `design.md` §1.
- **UPDATE** (consumers, blast radius): `lib/Service/BbvBudgetVocabulary.php`
  (retired — its entire purpose was reading either colliding vocabulary;
  once the schemas are distinct there is nothing left to adapt),
  `lib/Service/BbvProgrammeBudgetReader.php` (`SCHEMA_BUDGET` constant +
  vocabulary dependency removed), `lib/Lifecycle/BudgetBlocker.php:195`,
  `lib/Service/Commitment/CommitmentMaterialisationService.php:515`,
  `lib/Service/BbvProgrammeBudgetService.php` (docblock only, no literal),
  `src/views/BudgetLineCommitments.vue` +
  `src/views/budgetLineCommitmentsHelpers.js` (aggregation response bucket
  keys change from `Budget.*` to `CommitmentBudget.*`), and the two
  register.d fragments' own seed `objects[]` blocks + the
  `committedVsRealisedPerBudgetLine` aggregation's `join.through`. Five test
  files: `VerplichtingWorkflowTest`, `CommitmentMaterialisationServiceTest`,
  `RequisitionServiceTest`, `VerplichtingenCommitmentAccountingFragmentTest`
  (all four → `CommitmentBudget`), `ProvinciesBbvFragmentTest` (→
  `BbvProgrammeBudget`). `design.md` §2.
- **ADD** (migration): a count-abort migrator
  (`BudgetSchemaSplitMigrator`, modelled on
  `lib/Service/Migration/SubsidieOrderConsolidationMigrator.php`) that
  classifies any live `Budget` object by which vocabulary its fields match
  and re-points it to the correct renamed schema, aborting without touching
  source data on any unclassifiable row or count mismatch. `design.md` §2.
- **ADD** (schema): `LedgerGroup`, `AnnualBudget`, `BudgetLine`. `design.md`
  §3–§5.
- **ADD** (spec-only): the budget-vs-actuals roll-up requirement, PHP-service
  primary per the platform aggregation hazard. `design.md` §6.
- **ADD** (pages): minimal index/detail pages for `AnnualBudget`,
  `LedgerGroup`, `BudgetLine` under a new `Budgets` top-level nav group —
  positioned to fold into `nav-six-clusters`' reserved Cluster 4 (Banking &
  Cashflow) "Budgets" leaf once that change lands. `design.md` §7.
- **Non-goals, each naming its follow-up change** (`design.md` §10): the
  real spreadsheet-grid UI (`budget-grid-view`), projection math
  (`budget-projection-engine`), contract/recurring cost derivation
  (`budget-known-costs`), scenarios + modifiers (`budget-scenarios`), charts
  (`budget-charts`).

## Impact

- **Affected specs**: new capability `budget-core-schema`
  (`specs/budget-core-schema/spec.md`); `bookkeeping-provincies-bbv-variant`
  MODIFIED (`Budget` → `BbvProgrammeBudget` in REQ-BBC-001/002, dead
  `../budget-planning-control/spec.md` dependency reference corrected to
  point at this change); `bookkeeping-verplichtingenadministratie` MODIFIED
  (REQ-VPL-011's join target renamed, plus a positive-control finding on
  whether that declarative aggregation currently resolves at all — surfaced,
  not fixed here, since fixing the platform validator is
  openregister/foundation-repo scope).
- **Affected code**: 2 register.d fragments edited (schema rename + seed
  updates), 1 new migrator class + PHPUnit test, 1 shim class deleted
  (`BbvBudgetVocabulary`), 4 PHP consumers updated, 1 Vue + 1 JS helper
  updated, 5 PHPUnit test files updated, 3 new register.d schemas
  (`LedgerGroup`, `AnnualBudget`, `BudgetLine`) + 1 lifecycle guard
  (`AnnualBudgetDefaultGuard`), 1 new PHP roll-up service
  (`BudgetVsActualsReader`/`BudgetVsActualsCalculator`, mirroring the
  `BbvProgrammeBudgetReader`/`Calculator` split), 1 new manifest fragment
  (6 pages: 3 index + 3 detail) + 1 `menu-layout.json`/`manifest.json` edit
  for the `Budgets` top-level group, new Playwright + PHPUnit coverage
  (`design.md` §9).
- **Byte budget — hard sequencing dependency**: current headroom against the
  1,126,300-byte gate is **2,927 bytes** (measured
  2026-08-20: `manifest.json=460786B manifest.d/=662587B total=1123373B`).
  This change's own 6 new pages are estimated at **~6,600–7,900 bytes**
  (6 pages × 946–1,276B, this repo's own measured median/mean per page) —
  **this alone exceeds current headroom**, before counting the 2 renamed
  fragments' edits. `nav-six-clusters` (sibling change, PR #923, OPEN as of
  2026-08-20) frees a measured 29,253 bytes, raising headroom to ~32,180
  bytes — comfortably covering this change's pages. **Stance: this change's
  page-adding tasks (group 6 below) are sequenced after `nav-six-clusters`
  lands** (or after the current gate is otherwise re-baselined); the
  schema-only tasks (groups 1–5) do not depend on it and may land first.
  `design.md` §8 details the fallback if pages must ship before #923 merges.
- **No cross-repo impact**: unlike the payroll/hrmq wave, this change is
  entirely within shillinq — no hrmq, no openregister-repo edit (the
  aggregation-validator bug is documented and worked around, not patched;
  patching it is out of this app-repo's scope).
