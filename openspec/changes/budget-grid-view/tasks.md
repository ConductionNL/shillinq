# Tasks: budget-grid-view

## 0. Pre-flight — dependency + seed-data gap (see `design.md` §0, §9.1)
- [ ] Confirm `budget-core-schema` groups 1-8 have landed (schemas
      `LedgerGroup`/`AnnualBudget`/`BudgetLine` importable, `AnnualBudgetDefaultGuard`
      enforcing, `BudgetVsActualsReader`/`Calculator` present) — this
      change's backend composes on top of them and cannot start before they
      exist. If not yet landed, STOP and coordinate rather than duplicating
      that schema/service work here.
- [ ] File the seed-data gap as a follow-up task against `budget-core-schema`
      (if still open) or as its own tiny seed-only change (if
      `budget-core-schema` has already archived): add a P&L-shaped
      `LedgerGroup` seed batch sourced from `lib/Settings/statements/
      rj270-pl.json`'s `level: 1` sections (mirroring the existing seed's
      own shape and `@self.seedExemption: "anchor"` treatment,
      `budget-core-schema` §3c), so a fresh administration's begroting grid
      shows something resembling the user's Omzet/Personeel/Huisvesting/ICT
      example on day one. **Not implemented in this change's own diff** —
      recorded and handed off per `design.md` §0.

## 1. Backend — `BudgetGridReader` (REQ-BGV-001, REQ-BGV-002, REQ-BGV-003, REQ-BGV-009)
- [ ] Add `lib/Service/BudgetGridReader.php`: `rowsFor(string $administrationId): array`
      (root + nested `LedgerGroup` tree, single upfront `findAll` per
      `design.md` §1c), `columnsFor(array $range, string $granularity): array`
      (period list generation, `design.md` §2a), `pastColumns(array $columns,
      string $administrationId): array` (exact-span `FiscalPeriod` lookup +
      `state` check, `design.md` §2c — cadence-mismatch columns excluded,
      not approximated).
- [ ] Delegate `BudgetLine`↔`LedgerGroup`↔`TrialBalanceLine` value
      resolution to `budget-core-schema`'s `BudgetVsActualsReader` — do not
      re-open that join (`design.md` §5). Fetch `BudgetLine` for every
      fiscal year in the displayed range via one `annualBudgetId => ['in' =>
      [...]]` filter (`SpendAnalyticsService.php:183` precedent), resolving
      each fiscal year's default `AnnualBudget` first (`design.md` §2b).
- [ ] PHPUnit: `BudgetGridReaderTest` — row tree with 2+ nesting levels;
      exact-span `FiscalPeriod` match required (a quarterly-only
      `FiscalPeriod` does NOT satisfy a month-granularity column, per
      `design.md` §2c); `open`/`closing` states do not count as past; a
      fiscal year with no default `AnnualBudget` in a multi-year range
      renders empty (not zero) for that column, per `design.md` §2b; the
      full query-count budget from `design.md` §1c's table is asserted
      against a call counter/mock, not just correctness of results.

## 2. Backend — `BudgetGridCalculator` (REQ-BGV-003, REQ-BGV-004, REQ-BGV-005, REQ-BGV-008)
- [ ] Add `lib/Service/BudgetGridCalculator.php`: per-column budget/actual/
      deviation values applying the `accountType`-driven sign convention
      (`design.md` §2d — `revenue`: `actual - budget`; `expenses`: `budget -
      actual`; `assets|liabilities|equity`: unsigned difference, no
      favorable/unfavorable framing, per the open question); the cumulative
      `TOTAAL` pair (`design.md` §3 — begroot cumulative unconditional sum,
      werkelijk cumulative sums only past columns); the computed-row formula
      evaluator (`design.md` §4 — `group:<code>`/`row:<code>` references,
      `sum-group:<code>`/`section:<code> ± section:<code>`-equivalent
      arithmetic).
- [ ] PHPUnit: one case per `accountType` value proving the sign is NOT
      inverted (the task brief's own explicit warning) — a revenue account
      10,000 over budget shows a POSITIVE/favorable deviation, an expense
      account 10,000 over budget shows a NEGATIVE/unfavorable deviation, for
      the identical raw `actual - budget = +10,000` input; a mixed-type
      `LedgerGroup` sums correctly-signed per-member deviations, never one
      row-wide sign; cumulative werkelijk excludes future/cadence-mismatched
      columns; the computed-row formula evaluator against a small fixture
      matching `rj270-pl.json`'s own `BEDR-RES = SOM-OPB - SOM-KOS` shape.

## 3. Manifest + registry (REQ-BGV-006, REQ-BGV-007)
- [ ] Run `node tests/check-manifest-budget.js` and confirm current
      headroom before writing the fragment — report the number, do not
      assume `proposal.md`'s 2026-08-20 estimate still holds.
- [ ] Check whether the `Budgets` top-level nav group (`budget-core-schema`
      §7b) already exists in the effective manifest. If yes, add
      `BudgetGrid` as a new child under it. If no, create the `Budgets`
      group with the same id/label `budget-core-schema` §7b specifies, with
      `BudgetGrid` as its sole initial child (`proposal.md`'s Impact
      section — no duplicate group either way this lands).
- [ ] Add `src/manifest.d/budget-grid-view.json`: one page (`id:
      "BudgetGrid"`, `route: "/begroting/grid"`, `type: "custom"`,
      `component: "BudgetGrid"`) with a `_note` explaining the `type:
      "custom"` choice per `design.md` §7 (same justification shape as
      `BudgetLineCommitments`'s own `_note`).
- [ ] Register `BudgetGrid` in `src/registry.js`
      (`{ kind: 'page', component: BudgetGrid }`), matching the
      `BudgetLineCommitments` entry's exact shape (`src/registry.js:437`).
- [ ] `node tests/check-manifest-budget.js` — PASS, report the exact byte
      delta. **STOP and do not ship group 3-6 if this fails** — record the
      page-adding tasks as deferred, same fallback discipline
      `budget-core-schema` §8 already established, rather than silently
      exceeding the gate.
- [ ] `npm run check:nav-reachability` — PASS.

## 4. Frontend — `BudgetGrid.vue` row tree + expand/collapse (REQ-BGV-002, REQ-BGV-006)
- [ ] Add `src/views/BudgetGrid.vue`: fetches the row tree + column set once
      on mount from the `BudgetGridReader` endpoint (one request, no
      per-expand fetch — `design.md` §1c); renders root `LedgerGroup` rows;
      client-side toggle reveals children or resolved `Account` leaves from
      the already-fetched payload (`design.md` §1b).
- [ ] Row toggle: `tabindex="0"`, `role="button"`, `:aria-expanded`,
      `@click`, `@keyup.enter` **and** `@keyup.space` (ADR-059, `design.md`
      §6 — closes the Space-key gap the `BudgetLineCommitments.vue`
      precedent left open, in this change's own new component only).
- [ ] `Account` leaf rows render as a real link/`NcButton`-to-route to
      `ChartOfAccountsDetail` (`/chart-of-accounts/:id`) — not a `@click`
      handler on a non-interactive element (`design.md` §6, ADR-059
      Decision 3).
- [ ] Add `data-testid` hooks: `budget-grid-row` (each row),
      `budget-grid-expand-toggle`, `budget-grid-account-link`,
      `budget-grid-column-header`, `budget-grid-total-column` — for the
      Playwright spec (group 7) and for gate-19's e2e-coverage traceability.

## 5. Frontend — period range/granularity controls + column rendering (REQ-BGV-001, REQ-BGV-003)
- [ ] Add the range/granularity header controls (start period, end period,
      granularity select — `month` default) that drive `BudgetGridReader
      ::columnsFor()`.
- [ ] Render each column: budget-only for future/cadence-mismatched columns
      (with the "actuals not available at this granularity" indicator per
      `design.md` §2c where applicable), actual + text-labelled deviation
      for past columns (`design.md` §2d — never colour-only).
- [ ] Render the `TOTAAL` column's begroot/werkelijk cumulative pair +
      deviation (`design.md` §3).

## 6. Frontend — computed/subtotal rows (REQ-BGV-008)
- [ ] Add the `computedRows` page-config block (`design.md` §4) to
      `src/manifest.d/budget-grid-view.json`'s `BudgetGrid` page config,
      seeded with Bruto Marge / Kosten / Bedrijfsresultaat / % rows matching
      the user's own spreadsheet labels, referencing root `LedgerGroup`
      `code`s (contingent on task 0's seed follow-up landing — if it has
      not, these rows render as empty/dash against whatever `LedgerGroup`
      codes actually exist, not hidden, so the config is still verifiable
      against `budget-core-schema`'s day-one balance-sheet seed).
- [ ] Client-side (or `BudgetGridCalculator`-side — implementation choice,
      `design.md` §4) formula evaluator for `group:<code>`/`row:<code>`/
      `sum-group:<code>` references.

## 7. e2e coverage (REQ-BGV-002, REQ-BGV-003, REQ-BGV-006, REQ-BGV-007)
- [ ] Add `tests/e2e/budget-grid-view.spec.ts` covering
      `budget-grid-view::grid-renders-rows-and-columns`,
      `budget-grid-view::verzamelpost-expand-reveals-children`,
      `budget-grid-view::expand-keyboard-operable`,
      `budget-grid-view::grootboek-drill-through-navigates`,
      `budget-grid-view::past-column-shows-actuals-and-deviation`
      (`design.md` §10), modelled on
      `tests/e2e/budget-line-commitments.spec.ts` (SPDX header,
      `becomesVisible` helper, dismiss-wizard helper, data-defensive
      `test.skip()` when no `LedgerGroup`/`BudgetLine`/`FiscalPeriod` seed
      data exists for the current administration).
- [ ] Tag each Playwright test `@e2e budget-grid-view::<slug>` matching
      `specs/budget-grid-view/spec.md`'s scenario ids exactly (gate-19 /
      `hydra-gate-e2e-coverage`).

## 8. Validation
- [ ] `node tests/check-manifest-budget.js` — PASS (report exact delta,
      task group 3).
- [ ] `npm run check:nav-reachability` — PASS (task group 3).
- [ ] Run the full PHPUnit suite for touched files: `BudgetGridReaderTest`,
      `BudgetGridCalculatorTest` — all green.
- [ ] `npx playwright test tests/e2e/budget-grid-view.spec.ts` — PASS.
- [ ] `hydra-gate-keyboard-operable-controls` (ADR-059, once that gate
      lands) or a manual keyboard-only walkthrough of the grid's expand/
      collapse and drill-through — record the result.
- [ ] `openspec validate budget-grid-view --strict` — PASS.
