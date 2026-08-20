# Tasks: budget-known-costs

## 1. `CashflowRecurring` — additive fields (REQ-BKC-001, REQ-BKC-003, REQ-CF-005 delta)
- [x] Add `contractReference` (nullable string, FK to `Contract`) and
      `cpiRatePercent` (nullable number) to `CashflowRecurring` in
      `lib/Settings/register.d/zzp-cashflow-13wk.json` — additive only, no
      `required` change, no existing property touched (`design.md` §1, §3b).
- [x] `node tests/validate-registers.js` — PASS, confirm existing
      `CashflowRecurring` seed objects still validate unchanged.

## 2. `CashflowRecurringGuard` extension (REQ-BKC-002)
- [x] Read `tests/Unit/Guard/CashflowRecurringGuardTest.php` (or its
      equivalent path — confirm the exact test file before editing) to
      understand its current fixture shape.
- [x] Add `hasConsistentContractWindow()` to
      `lib/Guard/CashflowRecurringGuard.php`, called from
      `validateOnSave()` alongside the four existing checks: when
      `contractReference` is set and resolvable, `validFrom` MUST be `>=`
      the `Contract`'s `startDate` (when set) and `validTo` (when set) MUST
      be `<=` the `Contract`'s `endDate` (when set); fail-closed, logged
      with `recurId` context per the existing checks' own convention
      (`design.md` §3c).
- [x] Unit tests: within-bounds accepted, before-start rejected,
      after-end rejected, both-open (indefinite Contract) accepted,
      `contractReference` absent skips the check entirely (no regression
      to the four pre-existing checks).

## 3. `BudgetLineDerivation` schema (REQ-BKC-004)
- [x] Add `lib/Settings/register.d/budget-known-costs.json`:
      `BudgetLineDerivation` — `administrationId`, `annualBudgetId` (FK),
      `ledgerGroupId` (FK), `sourceType` (enum `contract|recurring`),
      `budgetLineId` (FK), `contributingRecurIds` (array<string>),
      `lastGeneratedMonthlyAmounts` (array<integer>, length 12),
      `lastGeneratedAt` (date-time), `overridden` (boolean, default false)
      — `design.md` §4b. `x-openregister-audit-trail.enabled: true`
      (REQ-AT-001).
- [x] `node tests/validate-registers.js` — PASS.

## 4. `KnownCostReader` — batched store access (REQ-BKC-008)
- [x] Add `lib/Service/KnownCostReader.php`: `ObjectServiceInterface` DI,
      6-call budget (`design.md` §5): `CashflowRecurring.findAll([administrationId])`,
      `Account.findAll([administrationId])`, `LedgerGroup.findAll([administrationId])`,
      `AnnualBudget.findAll([administrationId])`,
      `BudgetLine.findAll([annualBudgetId: in [...]])`,
      `BudgetLineDerivation.findAll([annualBudgetId: in [...]])`.
- [x] Implement `accountNumberExpense` → resolved `LedgerGroup` membership,
      reimplementing `budget-core-schema` §3a's range + explicit
      include/exclude algorithm (deliberately not shared — `design.md` §5,
      same decision `budget-projection-engine` §5d already made).
- [x] Resolve, per fiscal year touched by any in-scope `CashflowRecurring`
      row, whether a default (`isDefault: true`) `AnnualBudget` exists
      (`design.md` §7).
- [x] Unit tests (mocked `ObjectServiceInterface`):
      `testQueryCountIsFixed` (exactly 6 calls, regardless of row/group/year
      count — the query-budget regression guard), a `LedgerGroup`
      membership resolution test, a default-`AnnualBudget`-per-fiscal-year
      resolution test.

## 5. `KnownCostScheduleExpander` — pure arithmetic (REQ-BKC-003, REQ-BKC-006)
- [x] Add `lib/Service/KnownCostScheduleExpander.php`: no constructor
      dependencies (mirrors `BbvProgrammeBudgetCalculator`'s "reads
      NOTHING" contract). Public surface: `expand(recurring, fiscalYear,
      contract): array<string,int>` (`"01".."12" => cents`) or a typed
      `needsOperatorInput` result (`design.md` §6).
- [x] Implement frequency → months-in-scope (`design.md` §6b): monthly
      books every in-scope month unchanged; quarterly spreads evenly across
      the quarter's 3 months; annually books whole in `monthOfYear`;
      weekly/fortnightly enumerate exact occurrence dates and count them per
      month (RULING 2, 2026-08-20 — `design.md` §6d, REQ-BKC-010 —
      supersedes the earlier `needsOperatorInput` deferral, `design.md`
      §13.1 now resolved).
- [x] Implement the weekly/fortnightly occurrence-date enumeration exactly
      (`design.md` §6d, REQ-BKC-010): first occurrence = `validFrom`;
      subsequent occurrences step by 7 days (`WEEKLY`) or 14 days
      (`FORTNIGHTLY`), bounded by `validTo` when set (unbounded when null,
      per REQ-BKC-006); count occurrences landing inside each requested
      month; book `standardAmount × <count>` — NOT an averaged 52/12 or
      26/12 factor. `dagFromMonth` is confirmed null for these two
      frequencies per the schema's own field description, so `validFrom`
      itself is the only usable anchor.
- [x] Implement `validFrom`/`validTo` bounding, including the indefinite
      (`validTo: null`) case (`design.md` §6c, REQ-BKC-006).
- [x] Implement CPI compounding: `FIXED` unchanged every month;
      `CPI_PAST_YEAR` with `cpiRatePercent` set compounds once per calendar
      year relative to `validFrom`'s year, applied uniformly across that
      year's in-scope months (for `WEEKLY`/`FORTNIGHTLY`, applied to the
      per-occurrence amount before multiplying by the month's occurrence
      count, `design.md` §6d point 3), integer cents, rounded once per
      computed value (`design.md` §6e); `CPI_PAST_YEAR` with
      `cpiRatePercent` null returns `needsOperatorInput`, never a
      fabricated or zero rate (REQ-BKC-003) — this is now the only case
      that returns `needsOperatorInput`.
- [x] Unit tests — one per named scenario in `spec.md`:
      `testCpiIndexationCompoundsAnnually`,
      `testCpiWithoutRateNeedsOperatorInput`,
      `testIndefiniteValidToNeverTerminates`,
      `testMidYearStartBudgetsOnlyFromStartMonth`,
      `testQuarterlySpreadsEvenlyAcrossQuarterMonths`,
      `testAnnuallyBooksWholeInAnchorMonth`,
      `testMonthWithFiveWeeklyOccurrencesSumsAllFive` (REQ-BKC-010, the
      4-vs-5-occurrence-month case the ruling explicitly requires
      coverage for),
      `testFortnightlyExpansionEnumeratesExactOccurrenceDates` (REQ-BKC-010),
      `testWeeklyIndexationAppliesPerOccurrenceBeforeMonthlySum` (§6d point
      3 — a CPI step-up mid-year applied to each occurrence, not to the
      pre-summed monthly total).

## 6. `KnownCostBudgetWriter` — idempotent orchestration (REQ-BKC-004, REQ-BKC-005, REQ-BKC-007)
- [x] Add `lib/Service/KnownCostBudgetWriter.php`: per-run algorithm
      (`design.md` §8a) — group `CashflowRecurring` rows by
      `(ledgerGroupId, sourceType)`, sum expander output per group per
      fiscal year with a default `AnnualBudget` (skip fiscal years without
      one, REQ-BKC-007 — no `AnnualBudget` created), upsert per §8b/§8c.
- [x] Implement the no-existing-derivation create path (§8b) and the
      existing-derivation path (§8c): missing `BudgetLine` → recreate
      fresh; live amounts match fingerprint → overwrite + refresh
      fingerprint; live amounts diverge from fingerprint → mark
      `overridden: true`, do not overwrite; already `overridden` → skip
      entirely, no read-back.
- [x] Unit tests: `testRegenerationIsIdempotent` (run twice, byte-identical
      output, same query count both runs — REQ-BKC-004),
      `testMultipleRecurringRowsTargetingSameLedgerGroupSum` (REQ-BKC-004),
      `testOperatorOverrideIsDetectedAndRespected` (REQ-BKC-005),
      `testDeletedDerivedLineIsRecreatedFresh` (REQ-BKC-005),
      `testFiscalYearWithNoDefaultBudgetIsSkipped` (REQ-BKC-007).

## 7. Minimal pages + nav placement (REQ-BKC-009)
- [x] Run `node tests/check-manifest-budget.js` before starting; confirm
      headroom covers the estimated 950–1,900B (measured 32,182B headroom
      2026-08-20, `proposal.md` Impact — re-verify, do not assume it still
      holds by the time this task runs).
- [x] Add `src/manifest.d/budget-known-costs.json`: `BudgetLineDerivations`
      (index) / `BudgetLineDerivationDetail` (detail), read-only (no
      create/edit form), nested under the `Budgets` top-level group
      (created by whichever of `budget-core-schema`/`budget-grid-view`
      lands it first — check current manifest state before writing this
      fragment, per those changes' own either-order convention,
      `design.md` §10).
- [x] Detail page surfaces `sourceType`, `contributingRecurIds` (linked to
      their `CashflowRecurring` rows), `lastGeneratedAt`, `overridden`.
- [x] `node tests/check-manifest-budget.js` — PASS, report exact byte
      delta.
- [x] `npm run check:nav-reachability` — PASS.

## 8. e2e coverage (REQ-BKC-001, REQ-BKC-004, REQ-BKC-009)
- [x] Add `tests/e2e/budget-known-costs.spec.ts` covering
      `budget-known-costs::recurring-cost-derives-budget-line`,
      `budget-known-costs::contract-linked-cost-tags-source-contract`,
      `budget-known-costs::derivation-audit-trail-visible`
      (`design.md` §11), modelled on
      `tests/e2e/budget-line-commitments.spec.ts` (SPDX header,
      `becomesVisible` helper, data-defensive `test.skip()`).
- [x] Tag each Playwright test with `@e2e budget-known-costs::<slug>`
      matching `specs/budget-known-costs/spec.md`'s scenario ids exactly
      (gate-19 / `hydra-gate-e2e-coverage`).

## 9. Spec sync
- [x] Apply `specs/bookkeeping-cashflow-13wk/spec.md`'s delta (REQ-CF-005
      MODIFIED: `contractReference`/`cpiRatePercent` additive fields, the
      extended guard precondition) to
      `openspec/specs/bookkeeping-cashflow-13wk/spec.md`.
- [x] Confirm no other `openspec/specs/*/spec.md` cites `CashflowRecurring`
      in a way this delta invalidates
      (`grep -rln 'CashflowRecurring' openspec/specs/`).

## 10. Validation
- [x] `node tests/check-manifest-budget.js` — PASS (task group 7).
- [x] `node tests/validate-registers.js` — PASS (task groups 1, 3).
- [x] `npm run check:nav-reachability` — PASS (task group 7).
- [x] Full PHPUnit run for touched/new files:
      `CashflowRecurringGuardTest` (extended), `KnownCostReaderTest`,
      `KnownCostScheduleExpanderTest`, `KnownCostBudgetWriterTest` — all
      green.
- [x] `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) — PASS on every
      changed/new file (phpcs/phpmd/phpstan/psalm run directly, scoped to
      the changed-file list; `composer check:strict`'s own `test:all` leg
      is the same full-suite PHPUnit run captured separately below).
- [ ] `npx playwright test tests/e2e/budget-known-costs.spec.ts` — NOT RUN,
      per the implementer's explicit brief ("write... but do NOT execute
      it"). The spec is written and lint-clean (`npx eslint`); it should be
      spot-checked against a live run before this change ships, per its own
      header note.
- [x] `openspec validate budget-known-costs --strict` — PASS.
