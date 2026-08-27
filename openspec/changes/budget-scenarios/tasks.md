# Tasks: budget-scenarios

**Sequencing note**: this change requires `budget-known-costs` to have
landed (`CashflowRecurring.contractReference` and
`KnownCostScheduleExpander`) before task groups 1–6 can be implemented
(`proposal.md` Impact). Task group 8 (grid embedding) additionally requires
`budget-grid-view` to have landed `BudgetGrid.vue` (`design.md` §10) —
check current repo state before starting either.

## 0. Pre-flight — confirm no collision, no migration needed (REQ-BSC-001)
- [x] `grep -rln '"BudgetScenario"' lib/ src/ tests/ openspec/specs/` —
      confirm zero matches before this change starts (no prior fragment or
      seed object already uses this slug).
- [x] Confirm `lib/Settings/register.d/zzp-cashflow-13wk.json` is not
      touched by this change's diff at any point (`git diff --stat` check,
      repeated at task group 10's validation step). Untouched — verified by
      file-content inspection (no git diff run per the implementer's
      "no git operations" instruction; confirmed by re-reading the file's
      `CashflowScenario`/`CashflowRecurring` schemas unchanged from what
      was read at design time).

## 1. `BudgetScenario` schema + lifecycle (REQ-BSC-001, REQ-BSC-002)
- [x] Add `lib/Settings/register.d/budget-scenarios.json`: `BudgetScenario`
      — `administrationId`, `name`, `description`, `isDefault` (boolean,
      default false), `x-openregister-lifecycle`
      (`draft -> active -> archived`, `publish`/`archive` transitions, no
      guard on either transition — `isDefault` is set via
      `BudgetScenarioDefaultPromoter`, not a lifecycle transition,
      `design.md` §2a). `x-openregister-audit-trail.enabled: true`.
- [x] **RULING 1 (2026-08-20)**: in the same fragment's `objects[]` array,
      seed exactly one `LedgerGroup` object (schema owned by
      `budget-core-schema`, not redeclared here) — `code: "VLA-LIQ"`,
      `name: "Liquide middelen"`, `accountRanges: [{"from": "1000", "to":
      "1099"}]`, `includedAccountNumbers: []`, `excludedAccountNumbers: []`,
      `parentLedgerGroupId: null`, `@self.seedExemption: "anchor"`,
      `@self.slug: "ledger-group-vla-liq"` — sourced from
      `lib/Settings/statements/rj270-balance-sheet.json`'s own `VLA-LIQ`
      section (`design.md` §4c). Do **not** seed any other
      `rj270-balance-sheet.json` section — this is the one leaf
      `LEDGER_AMOUNT_DELTA` needs, not a restoration of
      `budget-core-schema`'s deliberately-excluded balance-sheet hierarchy.
- [x] `node tests/validate-registers.js` — PASS.

## 2. `BudgetScenarioDefaultPromoter` (REQ-BSC-002)
- [x] Add `lib/Service/BudgetScenarioDefaultPromoter.php`:
      `promote(scenarioId): void` — read current default for the target's
      `administrationId`, demote it if present, promote the target
      (`isDefault: true`, `status: active`), verify by re-read that exactly
      one default remains, log an error on mismatch (`design.md` §3b — a
      verified two-write sequence, not a claimed database transaction).
- [x] Unit tests: promoting with no existing default; promoting with an
      existing default (demotion asserted); promoting an
      already-default scenario is a no-op; the verification-mismatch
      logging path — implemented as a genuine INTERLEAVING simulation
      (`RacingDefaultObjectServiceDecorator`, a real decorator over the
      store double that injects a second `isDefault:true` row on the
      SECOND qualifying read, i.e. the post-promotion verification read,
      not a mocked read-back).

## 3. `BudgetScenarioModifier` schema + guard (REQ-BSC-003, REQ-BSC-004)
- [x] Add `BudgetScenarioModifier` to the same fragment: `administrationId`,
      `scenarioId` (FK), `modifierType` (enum `RECURRING_END|
      RECURRING_AMOUNT_CHANGE|LEDGER_AMOUNT_DELTA`), `effectiveDate`,
      `targetRecurId` (nullable), `newStandardAmount` (nullable),
      `targetLedgerGroupId` (nullable), `amountDeltaCents` (nullable) —
      `design.md` §4a. `x-openregister-audit-trail.enabled: true`.
- [x] Add `lib/Guard/BudgetScenarioModifierGuard.php` (ADR-031 exception
      path, `validateOnSave` precondition): reject a second `RECURRING_*`
      modifier in the same `scenarioId` sharing a `targetRecurId` with an
      existing modifier (`design.md` §5a); reject a `RECURRING_*` modifier
      missing `targetRecurId`, a `RECURRING_AMOUNT_CHANGE` missing
      `newStandardAmount`, or a `LEDGER_AMOUNT_DELTA` missing
      `targetLedgerGroupId`/`amountDeltaCents` (basic per-type required-field
      consistency, same shape as `CashflowRecurringGuard`'s own
      frequency-anchor check). Registered in `Application.php`'s
      `preconditions.save` tag map (unlike the still-unregistered
      pre-existing `CashflowRecurringGuard`/`ProgrammaLinkGuard` — this is
      NEW code, so it is wired to actually enforce).
- [x] `node tests/validate-registers.js` — PASS.
- [x] Unit tests: same-`recurId` conflict rejected; different-`recurId`
      modifiers both accepted; per-type required-field checks; a
      `LEDGER_AMOUNT_DELTA` alongside a `RECURRING_*` modifier both
      accepted.

## 4. `BudgetScenarioReader` — batched store access (REQ-BSC-007)
- [x] Add `lib/Service/BudgetScenarioReader.php`: `ObjectServiceInterface`
      DI, 5-call budget (`design.md` §6c):
      `BudgetScenario.findAll([administrationId])`,
      `BudgetScenarioModifier.findAll([scenarioId: in [...]])`,
      `CashflowRecurring.findAll([administrationId])`,
      `BudgetLine.findAll([annualBudgetId: in [...]])`,
      `LedgerGroup.findAll([administrationId])`. Also adds
      `resolveAnnualBudgetIds(administrationId, fiscalYear)`, a small
      SEPARATE helper `BudgetScenarioController::evaluate()` needs to build
      the `$annualBudgetIds` argument `loadContext()` requires — outside
      the 5-call budget by design (a different query, not part of the
      evaluation read).
- [x] Unit tests: `testFullReadIssuesExactlyFiveFindAllCalls` (exactly 5
      calls regardless of modifier/`LedgerGroup` count, incl. a
      20-modifier volume test).

## 5. `BudgetScenarioEvaluator` — pure, non-destructive (REQ-BSC-005, REQ-BSC-006)
- [x] Add `lib/Service/BudgetScenarioEvaluator.php`: no constructor
      dependency on `ObjectServiceInterface` (mirrors
      `BegrotingswijzigingStacker`'s own "no persistence, no I/O"
      contract). Public surface: `evaluate(baseBudgetLines, ledgerGroups,
      cashflowRecurringRows, modifiers, fiscalYear): array` (`design.md`
      §6a).
- [x] Implement `base[ledgerGroupId][month]` resolution — sum every
      `BudgetLine` targeting a node regardless of `source`, reusing
      `budget-known-costs design.md` §8d's own consumer contract; apply
      `budget-core-schema` §3d's parent-rollup rule to the resulting
      `base`/`scenario` values identically (`design.md` §6b).
- [x] Implement `RECURRING_END`/`RECURRING_AMOUNT_CHANGE` evaluation: build
      an in-memory hypothetical `CashflowRecurring` copy, call the
      `KnownCostScheduleExpanderInterface::expand()` port on both
      the hypothetical and the real row, delta = hypothetical − real, add
      into `scenario[...]` (`design.md` §6a step 2, REQ-BSC-006 — never a
      second schedule-math implementation). **Integration note**:
      `budget-known-costs`'s concrete `KnownCostScheduleExpander` class
      lives on sibling branch `feat/budget-known-costs` (PR #967), not on
      this branch's own base (`feat/budget-core-schema`) — see
      `lib/Service/KnownCostScheduleExpanderInterface.php`'s own docblock
      and `Application.php`'s DI binding for the exact integration point
      (resolves to the real class via `class_exists()` at container-
      resolution time; fails loud, not silently, when still absent).
- [x] Implement `LEDGER_AMOUNT_DELTA` evaluation: add `amountDeltaCents`
      into `scenario[targetLedgerGroupId][effectiveDate's month]` only
      (`design.md` §6a step 3).
- [x] Unit tests: `testZeroModifiersEqualsBase`,
      `testLedgerAmountDeltaAppliesToSingleMonth`,
      `testIndependentModifiersSumOrderIndependently`,
      `testRecurringEndCapsScheduleAtEffectiveDate`,
      `testRecurringAmountChangeAppliesFromEffectiveDateForward`,
      `testParentLedgerGroupRollupAppliesToScenarioValuesToo`,
      `testRecurringModifierDelegatesEveryScheduleCallToTheSharedExpander`
      (the REQ-BSC-006 cross-change consistency test, against a fake
      `KnownCostScheduleExpanderInterface` implementation since the real
      class isn't in this branch's tree), and
      `testEvaluationLeavesInputsByteIdentical` (the REQ-BSC-005
      non-destructive proof — `var_export()` before/after comparison on
      every input array).

## 6. Minimal pages + nav placement (REQ-BSC-008)
- [x] Run `node tests/check-manifest-budget.js` before starting; confirm
      headroom covers the estimated 1,200–2,700B (measured 32,182B
      headroom 2026-08-20 before `budget-known-costs`'s own pages land —
      re-verify, do not assume). Re-measured at start of this task:
      23,566B headroom (budget-core-schema's own pages had already landed
      and consumed some of the original 32,182B).
- [x] Add `src/manifest.d/budget-scenarios.json`: `BudgetScenarios`/
      `BudgetScenarioDetail` (detail includes a "Promote to default" action
      calling `BudgetScenarioDefaultPromoter` and a child `BudgetScenarioModifier`
      collection), `BudgetScenarioModifiers`/`BudgetScenarioModifierDetail`,
      `BudgetScenarioComparison` (`type: "custom"`, standalone comparison
      table, `design.md` §9) — nested under the `Budgets` top-level group
      (check current manifest state before writing this fragment, per the
      either-order convention every sibling in this wave already follows).
      `BudgetScenarioComparison` is ALSO given its own top-level nav leaf
      (no `:id` route segment — the picker defaults to the administration's
      own default scenario), so it needs no nav-reachability baseline
      exception.
- [x] `node tests/check-manifest-budget.js` — PASS, byte delta +7,440B
      (manifest.d/ 650,045B → 657,485B), 16,126B headroom remaining.
- [x] `npm run check:nav-reachability` — PASS, 0 new orphans.

## 7. e2e coverage (REQ-BSC-002, REQ-BSC-003, REQ-BSC-005, REQ-BSC-008)
- [x] Add `tests/e2e/budget-scenarios.spec.ts` covering
      `budget-scenarios::scenario-comparison-renders-base-and-scenario`,
      `budget-scenarios::promote-to-default-demotes-previous-default`,
      `budget-scenarios::modifier-crud-reachable` (`design.md` §11),
      modelled on `tests/e2e/budget-core-schema.spec.ts` (the closer sibling
      precedent for this wave — `budget-line-commitments.spec.ts` was
      checked and found to be a narrower single-schema precedent; the
      nav-group/gotoRoute/dismissOverlays helpers are identical either way).
      NOT EXECUTED, per the implementer's brief — `tsc --noEmit` and
      `eslint` both pass clean on the file.
- [x] Tag each Playwright test with `@e2e budget-scenarios::<slug>`
      matching `specs/budget-scenarios/spec.md`'s scenario ids exactly.

## 8. Grid embedding — requires `budget-grid-view` to have landed (deferred, `design.md` §10)
- [x] **Before starting**: confirm `src/views/BudgetGrid.vue` exists in
      this checkout. **Confirmed ABSENT** (`find src -iname BudgetGrid.vue`
      — no match; `budget-grid-view` has not landed on this branch) — this
      task group is DEFERRED, per the task's own stated fallback. Task
      groups 1–7 land without it.
- [ ] Add a scenario-selector control to `BudgetGrid.vue` — DEFERRED,
      blocked on `budget-grid-view` landing `BudgetGrid.vue` first.
- [ ] Playwright coverage for the grid-embedded selector — DEFERRED, same
      blocker.

## 9. Spec sync
- [x] Confirm this change adds no MODIFIED delta against any existing
      capability spec (`design.md` §1b — no collision, no rename); only
      `specs/budget-scenarios/spec.md` (ADDED) exists under this change's
      own `specs/`. Confirmed by directory listing — only that one spec
      file exists under this change's `specs/`.

## 10. Validation
- [x] `node tests/check-manifest-budget.js` — PASS (task group 6).
- [x] `node tests/validate-registers.js` — PASS (task groups 1, 3).
- [x] `npm run check:nav-reachability` — PASS (task group 6).
- [x] `lib/Settings/register.d/zzp-cashflow-13wk.json` confirmed untouched
      (task group 0's confirmation, re-checked at the end — by file-content
      inspection, not `git diff --stat`, per the "no git operations"
      instruction).
- [x] Full PHPUnit run for new files: `BudgetScenarioDefaultPromoterTest`
      (6), `BudgetScenarioModifierGuardTest` (9), `BudgetScenarioReaderTest`
      (7), `BudgetScenarioEvaluatorTest` (9) — all 31 green, 96 assertions.
      Full-suite before/after tally: 4657→4688 tests (+31), 45416→45512
      assertions (+96), 0 failures/errors both runs.
- [x] `composer check:strict`-equivalent (PHPCS/PHPMD/PHPStan/Psalm run
      individually against every changed PHP file, since `vendor/` had to
      be copied in fresh for this worktree) — PASS, 0 errors. 3 pre-existing
      `Application.php` PHPCS warnings (missing `@spec` on the class/
      `register()`/`boot()`) left as-is — confirmed pre-existing (identical
      on the unmodified base file) and out of this change's reasonable
      scope (a shared 1300+-line bootstrap class's top-level docblock, not
      a decision this change should make unilaterally).
- [ ] `npx playwright test tests/e2e/budget-scenarios.spec.ts` — NOT RUN,
      per the implementer's brief ("write the Playwright spec, do NOT
      execute it").
- [x] `openspec validate budget-scenarios --strict` — PASS ("Change
      'budget-scenarios' is valid").
