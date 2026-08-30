# Tasks: budget-projection-engine

## 1. `BudgetProjectionCalculator` — pure arithmetic (REQ-BPE-001, 002, 003, 004, 005, 010)
- [x] Add `lib/Service/BudgetProjectionCalculator.php`: no constructor
      dependencies (mirrors `BbvProgrammeBudgetCalculator`'s "reads
      NOTHING" contract, `design.md` §8). Public surface:
      `projectionMetric(accountType): 'closingBalance'|'netMovement'`
      (REQ-BPE-001), `growthRate(values: list<int>): {rate: float,
      validSteps: int}|{reason: 'insufficient-data', validSteps: int}`
      (REQ-BPE-002/004, `design.md` §2b–§2f), `extrapolate(v0: int, rate:
      float, k: int): int` (REQ-BPE-005, `design.md` §2g,
      round-half-away-from-zero to the nearest cent).
- [x] Implement the step-exclusion table exactly (`design.md` §2b): both
      zero → `g=0` included; zero-base non-zero-result → excluded;
      non-zero-base zero-result → included (`g=-1.0`); opposite-sign
      non-zero pair → excluded; same-sign non-zero pair → normal ratio.
- [x] Implement `MIN_VALID_STEPS = 3` floor (REQ-BPE-004) and the
      5-step outlier trim (drop single max + single min when included
      count ≥ 5, `design.md` §2e) as named constants, not magic numbers.
- [x] Implement `PROJECTION_HORIZON_MONTHS = 12` bound (REQ-BPE-005).
- [x] Unit tests (REQ-BPE-002/003/004/005 scenarios, `design.md` §2h)
      — one test per named scenario in `spec.md`:
      `testZeroToZeroStepIncluded`, `testZeroBaseStepExcluded`,
      `testNonZeroToZeroStepIncluded`, `testSignFlipStepExcluded`,
      `testOutlierIsTrimmedAboveFiveSteps`, `testNoTrimBelowFiveSteps`,
      `testBelowMinimumStepsIsUnprojectable`,
      `testExactlyMinimumStepsProjects`,
      `testExtrapolationCompoundsSingleRate`,
      `testStockAccountProjectsClosingBalance`,
      `testFlowAccountProjectsNetMovement`.

## 2. Seam + cumulative rules (REQ-BPE-006, 008)
- [x] Add `seam(account, month): 'actual'|'projected'|'unprojectable'`
      (`design.md` §4) — per-account `lastActualMonth`, never a global
      cutover; an actual value always wins.
- [x] Add `cumulative(trend: list<TypedResult>, accountType: string):
      list<int>` (`design.md` §6) — running sum for flow accounts,
      `cumulative == trend` for stock accounts (do NOT sum closing
      balances — a direct copy-through for stock types, enforced by a
      test that would fail if a future edit accidentally summed them).
- [x] Unit tests: `testSeamIsPerAccountNotGlobal`,
      `testSeamNeverOverridesAnActual`,
      `testFlowCumulativeIsRunningSumAcrossSeam`,
      `testStockCumulativeEqualsTrend`.

## 3. Group (`LedgerGroup`) sum semantics (REQ-BPE-007)
- [x] Add `groupProjected(members: list<TypedResult>): TypedResult`
      (`design.md` §5) — sums `actual`/`projected` member amounts,
      contributes `0` for `unprojectable` members, tags `partial: true`
      when any member is `unprojectable`, and only returns
      `unprojectable` itself when every member is.
- [x] Reuse `budget-core-schema` §3a's range + include/exclude membership
      resolution algorithm, reimplemented inside this change's own reader
      (`design.md` §5d — deliberately NOT extracted into a shared class
      with `budget-core-schema`'s `BudgetVsActualsReader`, to avoid
      editing that sibling change's files).
- [x] Unit tests: `testGroupSumsMemberProjections`,
      `testPartialGroupTaggedNotWithheld`.

## 4. `BudgetProjectionReader` — batched store access (REQ-BPE-003, 009)
- [x] Add `lib/Service/BudgetProjectionReader.php`: `ObjectServiceInterface`
      DI (ADR-083/084), 4-call budget (`design.md` §7b):
      `Account.findAll([administrationId])`,
      `GLTransaction.findAll([administrationId, state: 'posted'])`,
      `GLLine.findAll([])`, and `LedgerGroup.findAll([administrationId])`
      only when a group is requested.
- [x] Implement the `transactionRefs()` dual-keying join (object id AND
      `transactionNumber`) exactly per `BbvProgrammeBudgetReader`'s
      precedent — a code comment citing why (verified live hazard: one
      writer populates one, another writer populates the other).
- [x] Bucket by calendar month from `GLTransaction.postingDate`, never
      `GLLine.periodId` (REQ-BPE-003, `design.md` §2a) — a comment noting
      the verified mixed-granularity `periodId` values in this repo's own
      seed data as the reason.
- [x] Resolve each account's earliest posted transaction month, and
      shorten the trailing window rather than padding with zeros
      (REQ-BPE-003).
- [x] Derive per-account-per-month `netMovement` (flow) and
      `closingBalance` (stock, carried forward from `0` at the window's
      first available month — the `TrialBalanceService` "assumed 0 at
      first period" convention, cited). Implemented as
      `BudgetProjectionReader` bucketing raw signed `netMovement` cents
      per `(accountNumber, monthKey)` and `BudgetProjectionCalculator::
      metricSeries()` deriving the running `closingBalance` carry-forward
      from it — the running-sum arithmetic is pure, so it lives in the
      calculator (REQ-BPE-010), not the reader; the reader owns only the
      store read and the month-bucketing.
- [x] Unit tests (mocked `ObjectServiceInterface`):
      `testQueryCountIndependentOfAccountAndMonthCount` (asserts exactly
      3 calls for an account-only request, 4 when a group is included,
      regardless of how many accounts/groups/months are requested — this
      is the query-budget regression guard),
      `testWindowShortensToEarliestPostedTransaction`,
      `testBucketsByPostingDateNotPeriodId`,
      `testDualKeyedTransactionJoin` (a line whose `transactionId`
      matches only the `transactionNumber`, not the object id, is still
      joined).

## 5. `BudgetProjectionService` — orchestration (REQ-BPE-010)
- [x] Add `lib/Service/BudgetProjectionService.php`: thin orchestrator
      (reader → calculator → typed result shape), the integration point
      for `budget-grid-view`/`budget-charts`. No arithmetic of its own.
- [x] Unit tests with both collaborators mocked, asserting the service
      delegates rather than computes.

## 6. Cross-reference finding, recorded not fixed (REQ-BPE-009's design.md §7a note)
- [ ] Record, in this change's PR description, the cross-check finding
      that `budget-core-schema design.md` §6b describes its
      `BudgetVsActualsReader` as resolving actuals "from
      `TrialBalanceLine`" as though it held queryable historical rows,
      when `TrialBalanceService`'s own docblock states no such rows are
      persisted. Hand this to whoever implements `budget-core-schema`
      task group 8, not silently corrected in that change's files.
      **NOTE (apply phase): the finding text is prepared below in this
      file's trailing section for the PR author to paste verbatim; the
      checkbox is left unticked because opening the actual PR is outside
      this apply phase's scope (no git operations).**

## 7. Validation
- [x] Full PHPUnit run for the three new classes + their tests, all
      green, including the query-count regression test (task group 4).
      36/36 new tests green; full-suite before/after tallies recorded in
      the apply-phase report (no regressions).
- [x] `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) — PASS on the
      3 new `lib/Service/` files (phpcs 0 errors, phpmd 0 violations,
      psalm "No errors found!", phpstan "No errors"); full-repo `lint`
      and `test:all` also verified green. No `register.d/`, manifest, or
      Vue file is touched by this change, so no
      `node tests/validate-registers.js`,
      `node tests/check-manifest-budget.js`, or
      `npm run check:nav-reachability` run is required — confirmed via
      `git status --porcelain`, which shows only `lib/Service/` and
      `tests/Unit/Service/` new files.
- [x] `openspec validate budget-projection-engine --strict` — PASS.

---

### Cross-reference finding for the PR description (task group 6)

> `budget-core-schema design.md` §6b describes `BudgetVsActualsReader` as
> resolving actuals "from `TrialBalanceLine`" in a way that reads as if
> that schema held queryable historical rows. It does not:
> `TrialBalanceService`'s own docblock is explicit that no
> `TrialBalanceLine` row is ever persisted (the schema exists for its
> OpenAPI shape and 5 illustrative seed rows only). `BudgetVsActualsReader`
> itself is actually implemented correctly — it reads `GLTransaction` +
> `GLLine` + `Account` directly, matching `budget-projection-engine`'s own
> `BudgetProjectionReader` (`design.md` §7b) — so this is a documentation
> mismatch in `budget-core-schema design.md` §6b's prose, not a code
> defect. Flagged here per this change's own task group 6 for whoever next
> touches `budget-core-schema`'s design doc; not corrected in that file by
> this change.
