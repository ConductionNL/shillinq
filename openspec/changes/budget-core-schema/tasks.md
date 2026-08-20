# Tasks: budget-core-schema

## 1. Resolve the `Budget` collision — schema rename (REQ-BCS-001)
- [ ] Rename `bookkeeping-provincies-bbv-variant.json:67`'s schema key
      `Budget` → `BbvProgrammeBudget` (slug + title updated to match;
      required/properties/icon/x-spec unchanged otherwise, `design.md` §1b).
- [ ] Rename `bookkeeping-verplichtingenadministratie.json:922`'s schema key
      `Budget` → `CommitmentBudget` (same treatment).
- [ ] Update both fragments' seed `objects[]` blocks: `@self.schema` values
      (lines `291/308/325…` and `1132/1146`) to the new slugs, and — while
      already editing these exact lines — prefix the BBV fragment's example
      seed slugs with `example-` per ADR-001 (`design.md` §2d; the
      verplichtingen fragment's seed slugs are checked too and prefixed if
      also non-compliant).
- [ ] Update `bookkeeping-provincies-bbv-variant.json:35`'s
      `BbvComplianceDashboard` widget `config.schema` to `BbvProgrammeBudget`.
- [ ] Update `bookkeeping-verplichtingenadministratie.json:536`'s
      `committedVsRealisedPerBudgetLine.join.through` to `CommitmentBudget`.

## 2. Update PHP/JS consumers (REQ-BCS-002)
- [ ] Delete `lib/Service/BbvBudgetVocabulary.php` (`design.md` §2c).
- [ ] `lib/Service/BbvProgrammeBudgetReader.php`: `SCHEMA_BUDGET` constant →
      `'BbvProgrammeBudget'`; remove the `BbvBudgetVocabulary $vocabulary`
      constructor dependency; replace `vocabulary->year()`/`->programme()`/
      `->amount()` call sites (lines 176/196/201) with direct field reads
      (`$budget['fiscalYear']`, `$budget['programmeStructure']`,
      `$budget['totalAmount']`).
- [ ] `lib/Service/BbvProgrammeBudgetService.php`: update the docblock
      comment referencing `Budget.totalAmount` to `BbvProgrammeBudget.totalAmount`
      (no functional literal to change).
- [ ] `lib/Lifecycle/BudgetBlocker.php:195`: `schema: 'Budget'` →
      `schema: 'CommitmentBudget'`.
- [ ] `lib/Service/Commitment/CommitmentMaterialisationService.php:515`:
      `schema: 'Budget'` → `schema: 'CommitmentBudget'`.
- [ ] `src/views/budgetLineCommitmentsHelpers.js`: update the aggregation
      bucket-key reads (`Budget.authorised_amount`/`Budget.realised_amount`)
      to `CommitmentBudget.authorised_amount`/`CommitmentBudget.realised_amount`
      (`design.md` §2a — the response bucket key follows `join.through`).
- [ ] Confirm `src/views/BudgetLineCommitments.vue` needs no direct edit
      (it consumes the helper's normalised output only) — verify by re-
      reading the file after the helper change, not by assumption.

## 3. Update PHPUnit tests for the rename (REQ-BCS-002)
- [ ] `tests/Unit/Lifecycle/VerplichtingWorkflowTest.php` (lines
      231/238/278/307-316): `'Budget'` mock-array key and
      `authorised_amount`/`financialYear` fixtures → `'CommitmentBudget'`.
- [ ] `tests/Unit/Service/Commitment/CommitmentMaterialisationServiceTest.php`
      (lines 242-243/309/359/382): same rename.
- [ ] `tests/Unit/Service/RequisitionServiceTest.php` (lines
      260/297/325/376/381): same rename.
- [ ] `tests/Unit/Service/VerplichtingenCommitmentAccountingFragmentTest.php`
      (lines 106/111/155/175/194, incl.
      `assertSame('Budget', $agg['join']['through'])`): same rename to
      `'CommitmentBudget'`.
- [ ] `tests/Unit/Service/ProvinciesBbvFragmentTest.php` (lines 114-118):
      `'Budget'` → `'BbvProgrammeBudget'`.

## 4. Migrator (REQ-BCS-003)
- [ ] Add `lib/Service/Migration/BudgetSchemaSplitMigrator.php`, modelled on
      `SubsidieOrderConsolidationMigrator.php`: `classify(array $object): ?string`
      (BBV-shaped / Commitment-shaped / null-unclassifiable, `design.md`
      §2b), `migrateBatch()`, `assertCountsMatch()` (RuntimeException on any
      mismatch, source left intact).
- [ ] Unit tests: both vocabularies classify correctly, a deliberately
      ambiguous/malformed row classifies `null` and aborts the batch, count
      mismatch aborts without touching source data.
- [ ] Re-run the live OR-API count check
      (`GET /apps/openregister/api/objects?register=shillinq&schema=Budget&_limit=1`,
      reading `total`) against the shared dev instance immediately before
      implementing the rename — confirm it is still `0` as measured
      2026-08-20, or if non-zero, run the migrator against every real row
      before the schema rename ships (per this repo's own
      `payroll-leaves-to-hrmq` precedent: re-verify on every real
      deployment, not just the one shared dev instance).

## 5. `LedgerGroup` schema + seeds (REQ-BCS-004, REQ-BCS-005)
- [ ] Add `LedgerGroup` to a new `lib/Settings/register.d/budget-core-schema.json`
      fragment: `administrationId`, `code`, `name`, `order`,
      `parentLedgerGroupId` (nullable self-FK), `accountRanges`
      (array of `{from, to}`), `includedAccountNumbers`,
      `excludedAccountNumbers`, `effectiveFrom`/`effectiveTo` (nullable) —
      per `design.md` §3b. Declare `x-openregister-audit-trail.enabled: true`
      (REQ-AT-001 — every bookkeeping schema in this repo carries it,
      confirmed via `tests/validate-registers.js`, which will fail the
      build otherwise).
- [ ] Seed one `LedgerGroup` per `level: 2` section of
      `lib/Settings/statements/rj270-balance-sheet.json`, using the
      small-manufacturing variant's `glAccountRange` values from
      `balans-rubriek-mapping.json` where they align, RJ270's own
      `accountRange` otherwise. Each seed row: `@self.seedExemption: "anchor"`,
      plain (non-`example-`) slug `ledger-group-<rj270-code-lowercased>`
      (`design.md` §3c).
- [ ] `node tests/validate-registers.js` — PASS, confirm `LedgerGroup`
      carries the audit-trail flag and no slug-case collision is introduced.

## 6. `AnnualBudget` schema + lifecycle guard (REQ-BCS-006)
- [ ] Add `AnnualBudget` to the same new fragment: `administrationId`,
      `fiscalYear`, `name`, `isDefault` (boolean, default false),
      `x-openregister-lifecycle` (`draft -> active -> closed`, `activate`
      transition `requires: OCA\Shillinq\Lifecycle\AnnualBudgetDefaultGuard::isUniqueDefault`)
      per `design.md` §4a.
- [ ] Add `lib/Lifecycle/AnnualBudgetDefaultGuard.php` (ADR-031
      exception-path guard, same shape as `BudgetBlocker`): rejects
      `activate` when another `AnnualBudget` with `isDefault=true` already
      exists for the same `administrationId`+`fiscalYear`.
- [ ] PHPUnit: `AnnualBudgetDefaultGuardTest` — a second default is
      rejected; a default for a different fiscal year or administration is
      accepted; the guard fires on `activate`, not on save/draft.

## 7. `BudgetLine` schema (REQ-BCS-007)
- [ ] Add `BudgetLine` to the same fragment: `administrationId`,
      `annualBudgetId` (FK, uuid), `ledgerGroupId` (FK, uuid), `source`
      (enum `manual|contract|recurring|projected|scenario`, default
      `manual`), `month01Amount`..`month12Amount` (integer, default 0,
      minor units), `notes` (nullable) — per `design.md` §5a. No stored
      total/cumulative field (`design.md` §5b) — confirm this decision is
      still current before implementing; if a product sign-off (`design.md`
      §11.4) has since decided otherwise, follow that instead.
- [ ] `x-openregister-audit-trail.enabled: true` + confirm
      `node tests/validate-registers.js` still passes with all three new
      schemas added.

## 8. Budget-vs-actuals roll-up — PHP primary + hazard positive control (REQ-BCS-008)
- [ ] Add `lib/Service/BudgetVsActualsReader.php` +
      `lib/Service/BudgetVsActualsCalculator.php` (reader/calculator split
      mirroring `BbvProgrammeBudgetReader`/`Calculator`, `design.md` §6b):
      reader resolves `BudgetLine` → `LedgerGroup` member accounts (ranges +
      explicit include/exclude, resolved in PHP) → matching
      `TrialBalanceLine` rows by `accountNumber`+`periodId`; calculator sums
      budgeted vs. actual per line.
- [ ] PHPUnit coverage for both classes (no e2e — `design.md` §9).
- [ ] Positive-control task, run and record results in this change's PR
      description (not silently skipped): grep `nextcloud.log` for
      `"annotation on schema"` after a fresh register import, and directly
      query whether `CommitmentBudget.outstanding_commitments` and
      `committedVsRealisedPerBudgetLine` return non-empty rows against
      seeded data. Record the outcome in
      `specs/bookkeeping-verplichtingenadministratie/spec.md`'s delta
      (already drafted assuming "broken" — confirm or correct against the
      actual result) and in `design.md` §11.2's open question.
- [ ] Add a *documentation-only* `x-openregister-aggregations` entry on
      `BudgetLine` for the budget-vs-actuals shape (per `design.md` §6b),
      with an explicit comment that it is unverified/expected-discarded
      pending the positive control above — do not wire any page to depend
      on it.

## 9. Minimal pages + nav placement — sequenced on byte headroom (REQ-BCS-009, REQ-BCS-010)
- [ ] **Before starting this group**: run
      `node tests/check-manifest-budget.js` and confirm headroom ≥ ~8,000
      bytes (comfortable margin over the 5,676–7,656B estimate,
      `design.md` §8). If `nav-six-clusters`/PR #923 has not yet merged and
      headroom is still ~2,927B, STOP — do not add pages; land groups 1–8
      only and record this group as deferred (`design.md` §8's fallback).
- [ ] Add `src/manifest.d/budget-core-schema.json`: new top-level menu
      group `id: "Budgets"` (icon/order per `design.md` §11.3, provisional —
      flag for product review) with three children (`AnnualBudgets`,
      `LedgerGroups`, `BudgetLines`), and six pages (3 index + 3 detail)
      per `design.md` §7a, modelled on `DBAOpdrachten`/`DBAOpdrachtDetail`.
- [ ] `AnnualBudgetDetail` includes a child collection of its `BudgetLine`s
      (FK `annualBudgetId`); `LedgerGroupDetail` includes its child
      `LedgerGroup`s (FK `parentLedgerGroupId`) and its resolved
      account-range/explicit-include/exclude display.
- [ ] `node tests/check-manifest-budget.js` — PASS, report the exact byte
      delta against the ~5,676–7,656B estimate.
- [ ] `npm run check:nav-reachability` — PASS.

## 10. e2e coverage (REQ-BCS-009)
- [ ] Add `tests/e2e/budget-core-schema.spec.ts` covering
      `budget-core-schema::budgets-nav-group-reachable`,
      `budget-core-schema::ledger-group-seeded-on-import`,
      `budget-core-schema::budget-line-monthly-columns-editable`
      (`design.md` §9), modelled on `tests/e2e/budget-line-commitments.spec.ts`
      (SPDX header, `becomesVisible` helper, data-defensive `test.skip()`).
- [ ] Tag each Playwright test with `@e2e budget-core-schema::<slug>`
      matching `specs/budget-core-schema/spec.md`'s scenario ids exactly
      (gate-19 / `hydra-gate-e2e-coverage`).

## 11. Spec sync (REQ-BCS-011 and delta specs)
- [ ] Apply `specs/bookkeeping-provincies-bbv-variant/spec.md`'s delta
      (REQ-BBC-001/002 renamed, dead `../budget-planning-control/spec.md`
      dependency reference corrected) to
      `openspec/specs/bookkeeping-provincies-bbv-variant/spec.md`.
- [ ] Apply `specs/bookkeeping-verplichtingenadministratie/spec.md`'s delta
      (REQ-VPL-011 join-target rename + the §6a positive-control finding,
      recorded per its actual measured outcome per task group 8) to
      `openspec/specs/bookkeeping-verplichtingenadministratie/spec.md`.
- [ ] Confirm no other `openspec/specs/*/spec.md` cites the bare `Budget`
      schema slug by name (`grep -rln '\bBudget\b' openspec/specs/`),
      beyond the two deltas already handled.

## 12. Validation
- [ ] `node tests/check-manifest-budget.js` — PASS (report exact delta,
      task group 9).
- [ ] `node tests/validate-registers.js` — PASS (task groups 5, 7).
- [ ] `npm run check:nav-reachability` — PASS (task group 9, if pages
      shipped in this change; N/A if deferred per the byte-budget fallback).
- [ ] `grep -rln "'Budget'\|\"Budget\"" lib/ src/ tests/ --include=*.php --include=*.js --include=*.vue --include=*.json | grep -v node_modules` —
      zero matches outside `BbvProgrammeBudget`/`CommitmentBudget` (which
      must still match on their own literal substrings; verify by eye, not
      by count alone).
- [ ] Run the full PHPUnit suite for touched files:
      `BudgetSchemaSplitMigratorTest`, `AnnualBudgetDefaultGuardTest`,
      `BudgetVsActualsReaderTest`/`CalculatorTest`, plus the 5 updated
      existing test files (task group 3) — all green.
- [ ] `npx playwright test tests/e2e/budget-core-schema.spec.ts` — PASS (or
      explicitly skipped with a recorded reason if group 9's pages were
      deferred per the byte-budget fallback).
- [ ] `openspec validate budget-core-schema --strict` — PASS.
