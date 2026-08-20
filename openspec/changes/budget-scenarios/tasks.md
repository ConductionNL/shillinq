# Tasks: budget-scenarios

**Sequencing note**: this change requires `budget-known-costs` to have
landed (`CashflowRecurring.contractReference` and
`KnownCostScheduleExpander`) before task groups 1–6 can be implemented
(`proposal.md` Impact). Task group 8 (grid embedding) additionally requires
`budget-grid-view` to have landed `BudgetGrid.vue` (`design.md` §10) —
check current repo state before starting either.

## 0. Pre-flight — confirm no collision, no migration needed (REQ-BSC-001)
- [ ] `grep -rln '"BudgetScenario"' lib/ src/ tests/ openspec/specs/` —
      confirm zero matches before this change starts (no prior fragment or
      seed object already uses this slug).
- [ ] Confirm `lib/Settings/register.d/zzp-cashflow-13wk.json` is not
      touched by this change's diff at any point (`git diff --stat` check,
      repeated at task group 10's validation step).

## 1. `BudgetScenario` schema + lifecycle (REQ-BSC-001, REQ-BSC-002)
- [ ] Add `lib/Settings/register.d/budget-scenarios.json`: `BudgetScenario`
      — `administrationId`, `name`, `description`, `isDefault` (boolean,
      default false), `x-openregister-lifecycle`
      (`draft -> active -> archived`, `publish`/`archive` transitions, no
      guard on either transition — `isDefault` is set via
      `BudgetScenarioDefaultPromoter`, not a lifecycle transition,
      `design.md` §2a). `x-openregister-audit-trail.enabled: true`.
- [ ] **RULING 1 (2026-08-20)**: in the same fragment's `objects[]` array,
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
- [ ] `node tests/validate-registers.js` — PASS.

## 2. `BudgetScenarioDefaultPromoter` (REQ-BSC-002)
- [ ] Add `lib/Service/BudgetScenarioDefaultPromoter.php`:
      `promote(scenarioId): void` — read current default for the target's
      `administrationId`, demote it if present, promote the target
      (`isDefault: true`, `status: active`), verify by re-read that exactly
      one default remains, log an error on mismatch (`design.md` §3b — a
      verified two-write sequence, not a claimed database transaction).
- [ ] Unit tests: promoting with no existing default; promoting with an
      existing default (demotion asserted); promoting an
      already-default scenario is a no-op; the verification-mismatch
      logging path (mocked read-back returning an unexpected count).

## 3. `BudgetScenarioModifier` schema + guard (REQ-BSC-003, REQ-BSC-004)
- [ ] Add `BudgetScenarioModifier` to the same fragment: `administrationId`,
      `scenarioId` (FK), `modifierType` (enum `RECURRING_END|
      RECURRING_AMOUNT_CHANGE|LEDGER_AMOUNT_DELTA`), `effectiveDate`,
      `targetRecurId` (nullable), `newStandardAmount` (nullable),
      `targetLedgerGroupId` (nullable), `amountDeltaCents` (nullable) —
      `design.md` §4a. `x-openregister-audit-trail.enabled: true`.
- [ ] Add `lib/Guard/BudgetScenarioModifierGuard.php` (ADR-031 exception
      path, `validateOnSave` precondition): reject a second `RECURRING_*`
      modifier in the same `scenarioId` sharing a `targetRecurId` with an
      existing modifier (`design.md` §5a); reject a `RECURRING_*` modifier
      missing `targetRecurId`, a `RECURRING_AMOUNT_CHANGE` missing
      `newStandardAmount`, or a `LEDGER_AMOUNT_DELTA` missing
      `targetLedgerGroupId`/`amountDeltaCents` (basic per-type required-field
      consistency, same shape as `CashflowRecurringGuard`'s own
      frequency-anchor check).
- [ ] `node tests/validate-registers.js` — PASS.
- [ ] Unit tests: same-`recurId` conflict rejected; different-`recurId`
      modifiers both accepted; per-type required-field checks; a
      `LEDGER_AMOUNT_DELTA` alongside a `RECURRING_*` modifier both
      accepted.

## 4. `BudgetScenarioReader` — batched store access (REQ-BSC-007)
- [ ] Add `lib/Service/BudgetScenarioReader.php`: `ObjectServiceInterface`
      DI, 5-call budget (`design.md` §6c):
      `BudgetScenario.findAll([administrationId])`,
      `BudgetScenarioModifier.findAll([scenarioId: in [...]])`,
      `CashflowRecurring.findAll([administrationId])`,
      `BudgetLine.findAll([annualBudgetId: in [...]])`,
      `LedgerGroup.findAll([administrationId])`.
- [ ] Unit tests: `testQueryCountIsFixed` (exactly 5 calls regardless of
      modifier/`LedgerGroup` count).

## 5. `BudgetScenarioEvaluator` — pure, non-destructive (REQ-BSC-005, REQ-BSC-006)
- [ ] Add `lib/Service/BudgetScenarioEvaluator.php`: no constructor
      dependency on `ObjectServiceInterface` (mirrors
      `BegrotingswijzigingStacker`'s own "no persistence, no I/O"
      contract). Public surface: `evaluate(baseBudgetLines, ledgerGroups,
      cashflowRecurringRows, modifiers, fiscalYear): array` (`design.md`
      §6a).
- [ ] Implement `base[ledgerGroupId][month]` resolution — sum every
      `BudgetLine` targeting a node regardless of `source`, reusing
      `budget-known-costs design.md` §8d's own consumer contract; apply
      `budget-core-schema` §3d's parent-rollup rule to the resulting
      `base`/`scenario` values identically (`design.md` §6b).
- [ ] Implement `RECURRING_END`/`RECURRING_AMOUNT_CHANGE` evaluation: build
      an in-memory hypothetical `CashflowRecurring` copy, call
      `budget-known-costs`'s `KnownCostScheduleExpander::expand()` on both
      the hypothetical and the real row, delta = hypothetical − real, add
      into `scenario[...]` (`design.md` §6a step 2, REQ-BSC-006 — never a
      second schedule-math implementation).
- [ ] Implement `LEDGER_AMOUNT_DELTA` evaluation: add `amountDeltaCents`
      into `scenario[targetLedgerGroupId][effectiveDate's month]` only
      (`design.md` §6a step 3).
- [ ] Unit tests: `testZeroModifiersEqualsBase`,
      `testLedgerAmountDeltaAppliesToSingleMonth`,
      `testIndependentModifiersSumOrderIndependently`,
      `testRecurringEndCapsScheduleAtEffectiveDate`,
      `testRecurringAmountChangeAppliesFromEffectiveDateForward`,
      `testParentLedgerGroupRollupAppliesToScenarioValuesToo`,
      and the cross-change consistency test named in REQ-BSC-006 (construct
      the same hypothetical input `KnownCostBudgetWriter` would, assert
      identical output from the shared expander).

## 6. Minimal pages + nav placement (REQ-BSC-008)
- [ ] Run `node tests/check-manifest-budget.js` before starting; confirm
      headroom covers the estimated 1,200–2,700B (measured 32,182B
      headroom 2026-08-20 before `budget-known-costs`'s own pages land —
      re-verify, do not assume).
- [ ] Add `src/manifest.d/budget-scenarios.json`: `BudgetScenarios`/
      `BudgetScenarioDetail` (detail includes a "Promote to default" action
      calling `BudgetScenarioDefaultPromoter` and a child `BudgetScenarioModifier`
      collection), `BudgetScenarioModifiers`/`BudgetScenarioModifierDetail`,
      `BudgetScenarioComparison` (`type: "custom"`, standalone comparison
      table, `design.md` §9) — nested under the `Budgets` top-level group
      (check current manifest state before writing this fragment, per the
      either-order convention every sibling in this wave already follows).
- [ ] `node tests/check-manifest-budget.js` — PASS, report exact byte
      delta.
- [ ] `npm run check:nav-reachability` — PASS.

## 7. e2e coverage (REQ-BSC-002, REQ-BSC-003, REQ-BSC-005, REQ-BSC-008)
- [ ] Add `tests/e2e/budget-scenarios.spec.ts` covering
      `budget-scenarios::scenario-comparison-renders-base-and-scenario`,
      `budget-scenarios::promote-to-default-demotes-previous-default`,
      `budget-scenarios::modifier-crud-reachable` (`design.md` §11),
      modelled on `tests/e2e/budget-line-commitments.spec.ts`.
- [ ] Tag each Playwright test with `@e2e budget-scenarios::<slug>`
      matching `specs/budget-scenarios/spec.md`'s scenario ids exactly.

## 8. Grid embedding — requires `budget-grid-view` to have landed (deferred, `design.md` §10)
- [ ] **Before starting**: confirm `src/views/BudgetGrid.vue` exists in
      this checkout. If it does not, STOP — this task group is not yet
      implementable; land task groups 1–7 and defer this group.
- [ ] Add a scenario-selector control to `BudgetGrid.vue` (a prop/slot,
      exact integration point to be determined by reading that component
      once it exists) that calls `BudgetScenarioEvaluator` via
      `BudgetScenarioReader` and overlays `scenario`/`delta` values
      alongside the grid's existing `base`/actual columns, reusing
      `budget-grid-view`'s own §2d sign convention for favourable/
      unfavourable framing on the delta.
- [ ] Playwright coverage for the grid-embedded selector (a fourth scenario
      to `tests/e2e/budget-scenarios.spec.ts` or a new spec, implementer's
      choice, tagged `@e2e budget-scenarios::grid-scenario-selector`).

## 9. Spec sync
- [ ] Confirm this change adds no MODIFIED delta against any existing
      capability spec (`design.md` §1b — no collision, no rename); only
      `specs/budget-scenarios/spec.md` (ADDED) exists under this change's
      own `specs/`.

## 10. Validation
- [ ] `node tests/check-manifest-budget.js` — PASS (task group 6).
- [ ] `node tests/validate-registers.js` — PASS (task groups 1, 3).
- [ ] `npm run check:nav-reachability` — PASS (task group 6).
- [ ] `git diff --stat` confirms `lib/Settings/register.d/zzp-cashflow-13wk.json`
      is untouched (task group 0's confirmation, re-checked at the end).
- [ ] Full PHPUnit run for new files: `BudgetScenarioDefaultPromoterTest`,
      `BudgetScenarioModifierGuardTest`, `BudgetScenarioReaderTest`,
      `BudgetScenarioEvaluatorTest` — all green.
- [ ] `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) — PASS.
- [ ] `npx playwright test tests/e2e/budget-scenarios.spec.ts` — PASS (task
      group 8's scenario included only if that group was not deferred).
- [ ] `openspec validate budget-scenarios --strict` — PASS.
