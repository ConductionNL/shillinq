# Tasks: budget-known-costs

## 1. `CashflowRecurring` — additive fields (REQ-BKC-001, REQ-BKC-003, REQ-CF-005 delta)
- [ ] Add `contractReference` (nullable string, FK to `Contract`) and
      `cpiRatePercent` (nullable number) to `CashflowRecurring` in
      `lib/Settings/register.d/zzp-cashflow-13wk.json` — additive only, no
      `required` change, no existing property touched (`design.md` §1, §3b).
- [ ] `node tests/validate-registers.js` — PASS, confirm existing
      `CashflowRecurring` seed objects still validate unchanged.

## 2. `CashflowRecurringGuard` extension (REQ-BKC-002)
- [ ] Read `tests/Unit/Guard/CashflowRecurringGuardTest.php` (or its
      equivalent path — confirm the exact test file before editing) to
      understand its current fixture shape.
- [ ] Add `hasConsistentContractWindow()` to
      `lib/Guard/CashflowRecurringGuard.php`, called from
      `validateOnSave()` alongside the four existing checks: when
      `contractReference` is set and resolvable, `validFrom` MUST be `>=`
      the `Contract`'s `startDate` (when set) and `validTo` (when set) MUST
      be `<=` the `Contract`'s `endDate` (when set); fail-closed, logged
      with `recurId` context per the existing checks' own convention
      (`design.md` §3c).
- [ ] Unit tests: within-bounds accepted, before-start rejected,
      after-end rejected, both-open (indefinite Contract) accepted,
      `contractReference` absent skips the check entirely (no regression
      to the four pre-existing checks).

## 3. `BudgetLineDerivation` schema (REQ-BKC-004)
- [ ] Add `lib/Settings/register.d/budget-known-costs.json`:
      `BudgetLineDerivation` — `administrationId`, `annualBudgetId` (FK),
      `ledgerGroupId` (FK), `sourceType` (enum `contract|recurring`),
      `budgetLineId` (FK), `contributingRecurIds` (array<string>),
      `lastGeneratedMonthlyAmounts` (array<integer>, length 12),
      `lastGeneratedAt` (date-time), `overridden` (boolean, default false)
      — `design.md` §4b. `x-openregister-audit-trail.enabled: true`
      (REQ-AT-001).
- [ ] `node tests/validate-registers.js` — PASS.

## 4. `KnownCostReader` — batched store access (REQ-BKC-008)
- [ ] Add `lib/Service/KnownCostReader.php`: `ObjectServiceInterface` DI,
      6-call budget (`design.md` §5): `CashflowRecurring.findAll([administrationId])`,
      `Account.findAll([administrationId])`, `LedgerGroup.findAll([administrationId])`,
      `AnnualBudget.findAll([administrationId])`,
      `BudgetLine.findAll([annualBudgetId: in [...]])`,
      `BudgetLineDerivation.findAll([annualBudgetId: in [...]])`.
- [ ] Implement `accountNumberExpense` → resolved `LedgerGroup` membership,
      reimplementing `budget-core-schema` §3a's range + explicit
      include/exclude algorithm (deliberately not shared — `design.md` §5,
      same decision `budget-projection-engine` §5d already made).
- [ ] Resolve, per fiscal year touched by any in-scope `CashflowRecurring`
      row, whether a default (`isDefault: true`) `AnnualBudget` exists
      (`design.md` §7).
- [ ] Unit tests (mocked `ObjectServiceInterface`):
      `testQueryCountIsFixed` (exactly 6 calls, regardless of row/group/year
      count — the query-budget regression guard), a `LedgerGroup`
      membership resolution test, a default-`AnnualBudget`-per-fiscal-year
      resolution test.

## 5. `KnownCostScheduleExpander` — pure arithmetic (REQ-BKC-003, REQ-BKC-006)
- [ ] Add `lib/Service/KnownCostScheduleExpander.php`: no constructor
      dependencies (mirrors `BbvProgrammeBudgetCalculator`'s "reads
      NOTHING" contract). Public surface: `expand(recurring, fiscalYear,
      contract): array<string,int>` (`"01".."12" => cents`) or a typed
      `needsOperatorInput` result (`design.md` §6).
- [ ] Implement frequency → months-in-scope (`design.md` §6b): monthly
      books every in-scope month unchanged; quarterly spreads evenly across
      the quarter's 3 months; annually books whole in `monthOfYear`;
      weekly/fortnightly return `needsOperatorInput` (§6d, flagged, not
      guessed — `design.md` §13.1's open question).
- [ ] Implement `validFrom`/`validTo` bounding, including the indefinite
      (`validTo: null`) case (`design.md` §6c, REQ-BKC-006).
- [ ] Implement CPI compounding: `FIXED` unchanged every month;
      `CPI_PAST_YEAR` with `cpiRatePercent` set compounds once per calendar
      year relative to `validFrom`'s year, applied uniformly across that
      year's in-scope months, integer cents, rounded once per computed
      value (`design.md` §6e); `CPI_PAST_YEAR` with `cpiRatePercent` null
      returns `needsOperatorInput`, never a fabricated or zero rate
      (REQ-BKC-003).
- [ ] Unit tests — one per named scenario in `spec.md`:
      `testCpiIndexationCompoundsAnnually`,
      `testCpiWithoutRateNeedsOperatorInput`,
      `testIndefiniteValidToNeverTerminates`,
      `testMidYearStartBudgetsOnlyFromStartMonth`,
      `testQuarterlySpreadsEvenlyAcrossQuarterMonths`,
      `testAnnuallyBooksWholeInAnchorMonth`,
      `testWeeklyAndFortnightlyReturnNeedsOperatorInput`.

## 6. `KnownCostBudgetWriter` — idempotent orchestration (REQ-BKC-004, REQ-BKC-005, REQ-BKC-007)
- [ ] Add `lib/Service/KnownCostBudgetWriter.php`: per-run algorithm
      (`design.md` §8a) — group `CashflowRecurring` rows by
      `(ledgerGroupId, sourceType)`, sum expander output per group per
      fiscal year with a default `AnnualBudget` (skip fiscal years without
      one, REQ-BKC-007 — no `AnnualBudget` created), upsert per §8b/§8c.
- [ ] Implement the no-existing-derivation create path (§8b) and the
      existing-derivation path (§8c): missing `BudgetLine` → recreate
      fresh; live amounts match fingerprint → overwrite + refresh
      fingerprint; live amounts diverge from fingerprint → mark
      `overridden: true`, do not overwrite; already `overridden` → skip
      entirely, no read-back.
- [ ] Unit tests: `testRegenerationIsIdempotent` (run twice, byte-identical
      output, same query count both runs — REQ-BKC-004),
      `testMultipleRecurringRowsTargetingSameLedgerGroupSum` (REQ-BKC-004),
      `testOperatorOverrideIsDetectedAndRespected` (REQ-BKC-005),
      `testDeletedDerivedLineIsRecreatedFresh` (REQ-BKC-005),
      `testFiscalYearWithNoDefaultBudgetIsSkipped` (REQ-BKC-007).

## 7. Minimal pages + nav placement (REQ-BKC-009)
- [ ] Run `node tests/check-manifest-budget.js` before starting; confirm
      headroom covers the estimated 950–1,900B (measured 32,182B headroom
      2026-08-20, `proposal.md` Impact — re-verify, do not assume it still
      holds by the time this task runs).
- [ ] Add `src/manifest.d/budget-known-costs.json`: `BudgetLineDerivations`
      (index) / `BudgetLineDerivationDetail` (detail), read-only (no
      create/edit form), nested under the `Budgets` top-level group
      (created by whichever of `budget-core-schema`/`budget-grid-view`
      lands it first — check current manifest state before writing this
      fragment, per those changes' own either-order convention,
      `design.md` §10).
- [ ] Detail page surfaces `sourceType`, `contributingRecurIds` (linked to
      their `CashflowRecurring` rows), `lastGeneratedAt`, `overridden`.
- [ ] `node tests/check-manifest-budget.js` — PASS, report exact byte
      delta.
- [ ] `npm run check:nav-reachability` — PASS.

## 8. e2e coverage (REQ-BKC-001, REQ-BKC-004, REQ-BKC-009)
- [ ] Add `tests/e2e/budget-known-costs.spec.ts` covering
      `budget-known-costs::recurring-cost-derives-budget-line`,
      `budget-known-costs::contract-linked-cost-tags-source-contract`,
      `budget-known-costs::derivation-audit-trail-visible`
      (`design.md` §11), modelled on
      `tests/e2e/budget-line-commitments.spec.ts` (SPDX header,
      `becomesVisible` helper, data-defensive `test.skip()`).
- [ ] Tag each Playwright test with `@e2e budget-known-costs::<slug>`
      matching `specs/budget-known-costs/spec.md`'s scenario ids exactly
      (gate-19 / `hydra-gate-e2e-coverage`).

## 9. Spec sync
- [ ] Apply `specs/bookkeeping-cashflow-13wk/spec.md`'s delta (REQ-CF-005
      MODIFIED: `contractReference`/`cpiRatePercent` additive fields, the
      extended guard precondition) to
      `openspec/specs/bookkeeping-cashflow-13wk/spec.md`.
- [ ] Confirm no other `openspec/specs/*/spec.md` cites `CashflowRecurring`
      in a way this delta invalidates
      (`grep -rln 'CashflowRecurring' openspec/specs/`).

## 10. Validation
- [ ] `node tests/check-manifest-budget.js` — PASS (task group 7).
- [ ] `node tests/validate-registers.js` — PASS (task groups 1, 3).
- [ ] `npm run check:nav-reachability` — PASS (task group 7).
- [ ] Full PHPUnit run for touched/new files:
      `CashflowRecurringGuardTest` (extended), `KnownCostReaderTest`,
      `KnownCostScheduleExpanderTest`, `KnownCostBudgetWriterTest` — all
      green.
- [ ] `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) — PASS.
- [ ] `npx playwright test tests/e2e/budget-known-costs.spec.ts` — PASS.
- [ ] `openspec validate budget-known-costs --strict` — PASS.
