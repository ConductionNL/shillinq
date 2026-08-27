# Tasks: budget-grid-view

## 0. Pre-flight — dependency check (see `design.md` §0)
- [x] Confirm `budget-core-schema` groups 1-8 have landed (schemas
      `LedgerGroup`/`AnnualBudget`/`BudgetLine` importable, `AnnualBudgetDefaultGuard`
      enforcing, the P&L-shaped `LedgerGroup` seed per its amended §3c/§3d,
      `BudgetVsActualsReader`/`Calculator` present, computing actuals from
      `GLTransaction`+`GLLine`+`Account` per its amended §6b) — this change's
      backend composes on top of them and cannot start before they exist. If
      not yet landed, STOP and coordinate rather than duplicating that
      schema/service work here. **The former seed-data-gap follow-up (this
      group's earlier "File the seed-data gap" task) is removed — resolved
      directly in `budget-core-schema`'s own amendment, nothing left to file
      here.**

## 1. Backend — `BudgetGridReader` (REQ-BGV-001, REQ-BGV-002, REQ-BGV-003, REQ-BGV-009)
- [x] Add `lib/Service/BudgetGridReader.php`: `rowsFor(string $administrationId): array`
      (root + nested `LedgerGroup` tree, single upfront `findAll` per
      `design.md` §1c), `columnsFor(array $range, string $granularity): array`
      (period list generation, `design.md` §2a), `pastColumns(array $columns,
      string $administrationId): array` (`FiscalPeriod` lookup + `state`
      check — exact-span match, OR the column's calendar span fully
      contained within a coarser closed/audit-locked `FiscalPeriod`, per
      `design.md` §2c amended).
- [x] **Amended: actuals come from `GLTransaction`+`GLLine`+`Account`, not
      `TrialBalanceLine`** (`design.md` §1c/§2c/§5 amendment note —
      `TrialBalanceLine` has no persisted rows,
      `TrialBalanceService.php`'s own docblock confirms it). Delegate the
      `BudgetLine`↔`LedgerGroup`↔GL-activity value resolution to
      `budget-core-schema`'s `BudgetVsActualsReader` — do not re-open that
      join or re-implement the GL batching here. Fetch `BudgetLine` for
      every fiscal year in the displayed range via one
      `annualBudgetId => ['in' => [...]]` filter
      (`SpendAnalyticsService.php:183` precedent), resolving each fiscal
      year's default `AnnualBudget` first (`design.md` §2b). Fetch
      `LedgerGroup` and `FiscalPeriod` once each, unfiltered by period
      (`design.md` §1c).
- [x] PHPUnit: `BudgetGridReaderTest` — row tree with 2+ nesting levels; a
      column is past via an exact-span closed `FiscalPeriod` AND via a
      column contained within a coarser closed `FiscalPeriod` (both count,
      per `design.md` §2c amended); `open`/`closing` states do not count as
      past; a fiscal year with no default `AnnualBudget` in a multi-year
      range renders empty (not zero) for that column, per `design.md` §2b;
      the full query-count budget from `design.md` §1c's table (a flat,
      small constant, NOT scaling with the number of past columns) is
      asserted against a call counter/mock, not just correctness of
      results.

## 2. Backend — `BudgetGridCalculator` (REQ-BGV-003, REQ-BGV-004, REQ-BGV-005, REQ-BGV-008)
- [x] Add `lib/Service/BudgetGridCalculator.php`: per-column budget/actual/
      deviation values applying the `accountType`-driven sign convention
      (`design.md` §2d — `revenue`: `actual - budget`; `expenses`: `budget -
      actual`; `assets|liabilities|equity`: unsigned difference, no
      favorable/unfavorable framing, per the open question); the cumulative
      `TOTAAL` pair (`design.md` §3 — begroot cumulative unconditional sum,
      werkelijk cumulative sums only past columns); the computed-row formula
      evaluator (`design.md` §4 — `<code> [+|-] <code> …` arithmetic over
      root `LedgerGroup` codes and other computed-row codes, each carrying
      its own explicit `favorableDirection`).
- [x] Seed the page-config computed rows themselves (task group 6) against
      the real P&L root codes `budget-core-schema`'s amended seed now
      provides: `BRUTO-MARGE = omzet - kostprijs-van-de-omzet`,
      `KOSTEN = personeel + huisvesting + afschrijvingen-op-vaste-activa +
      exploitatie-en-machinekosten + verkoopkosten + algemene-kosten`,
      `BEDRIJFSRESULTAAT = BRUTO-MARGE - KOSTEN`,
      `FINANCIEEL-RESULTAAT = rentebaten - rentelasten`,
      `RESULTAAT-VOOR-BELASTINGEN = BEDRIJFSRESULTAAT + FINANCIEEL-RESULTAAT`,
      `NETTORESULTAAT = RESULTAAT-VOOR-BELASTINGEN - vennootschapsbelasting`
      — the full `rj270-pl.json` waterfall (`design.md` §4), plus at least
      one % row (`NETTORESULTAAT-PCT = NETTORESULTAAT / omzet`, `asPercent:
      true`).
- [x] PHPUnit: one case per `accountType` value proving the sign is NOT
      inverted (the task brief's own explicit warning) — a revenue account
      10,000 over budget shows a POSITIVE/favorable deviation, an expense
      account 10,000 over budget shows a NEGATIVE/unfavorable deviation, for
      the identical raw `actual - budget = +10,000` input; a mixed-type
      `LedgerGroup` sums correctly-signed per-member deviations, never one
      row-wide sign; cumulative werkelijk excludes future columns; a parent
      `LedgerGroup` (`Omzet`/`Personeel`/`Kostprijs van de omzet`) with no
      own `BudgetLine` resolves via child rollup (`budget-core-schema
      design.md` §3d — this calculator's own test, since the grid is the
      first real consumer of that rule); the computed-row formula evaluator
      against the full `rj270-pl.json`-matching fixture above, asserting
      `BEDRIJFSRESULTAAT` ties to `Bedrijfsresultaat`'s known value for a
      worked example.

## 3. Manifest + registry (REQ-BGV-006, REQ-BGV-007)
- [x] Run `node tests/check-manifest-budget.js` and confirm current
      headroom before writing the fragment — report the number, do not
      assume `proposal.md`'s 2026-08-20 estimate still holds.
      (Measured 2026-08-20: headroom 23,566B — matches proposal.md exactly.)
- [x] Check whether the `Budgets` top-level nav group (`budget-core-schema`
      §7b) already exists in the effective manifest. If yes, add
      `BudgetGrid` as a new child under it. If no, create the `Budgets`
      group with the same id/label `budget-core-schema` §7b specifies, with
      `BudgetGrid` as its sole initial child (`proposal.md`'s Impact
      section — no duplicate group either way this lands).
      (`budget-core-schema` group 9 already landed on this branch —
      `Budgets` exists with `AnnualBudgets`/`LedgerGroups`/`BudgetLines`
      children; `BudgetGrid` merges in as a 4th child via
      `mergeMenuItems()`.)
- [x] Add `src/manifest.d/budget-grid-view.json`: one page (`id:
      "BudgetGrid"`, `route: "/begroting/grid"`, `type: "custom"`,
      `component: "BudgetGrid"`) with a `_note` explaining the `type:
      "custom"` choice per `design.md` §7 (same justification shape as
      `BudgetLineCommitments`'s own `_note`).
- [x] Register `BudgetGrid` in `src/registry.js`
      (`{ kind: 'page', component: BudgetGrid }`), matching the
      `BudgetLineCommitments` entry's exact shape (`src/registry.js:437`).
- [x] `node tests/check-manifest-budget.js` — PASS, report the exact byte
      delta. **STOP and do not ship group 3-6 if this fails** — record the
      page-adding tasks as deferred, same fallback discipline
      `budget-core-schema` §8 already established, rather than silently
      exceeding the gate.
      (Measured: manifest.json+manifest.d total 1,102,734B → 1,105,670B,
      delta +2,936B; headroom 23,566B → 20,630B; PASS.)
- [x] `npm run check:nav-reachability` — PASS (579 pages, 0 new orphans).

## 4. Frontend — `BudgetGrid.vue` row tree + expand/collapse (REQ-BGV-002, REQ-BGV-006)
- [x] Add `src/views/BudgetGrid.vue`: fetches the row tree + column set once
      on mount from the `BudgetGridReader` endpoint (one request, no
      per-expand fetch — `design.md` §1c); renders root `LedgerGroup` rows;
      client-side toggle reveals children or resolved `Account` leaves from
      the already-fetched payload (`design.md` §1b).
- [x] Row toggle: `tabindex="0"`, `role="button"`, `:aria-expanded`,
      `@click`, `@keyup.enter` **and** `@keyup.space` (ADR-059, `design.md`
      §6 — closes the Space-key gap the `BudgetLineCommitments.vue`
      precedent left open, in this change's own new component only).
      (Implemented as a real `<button type="button">` — a native button is
      already keyboard-operable via Enter/Space without a handler per key,
      but `@keyup.enter`/`@keyup.space` are bound explicitly too so the
      behaviour is asserted, not merely inherited.)
- [x] `Account` leaf rows render as a real link/`NcButton`-to-route to
      `ChartOfAccountsDetail` (`/chart-of-accounts/:id`) — not a `@click`
      handler on a non-interactive element (`design.md` §6, ADR-059
      Decision 3).
- [x] Add `data-testid` hooks: `budget-grid-row` (each row),
      `budget-grid-expand-toggle`, `budget-grid-account-link`,
      `budget-grid-column-header`, `budget-grid-total-column` — for the
      Playwright spec (group 7) and for gate-19's e2e-coverage traceability.

## 5. Frontend — period range/granularity controls + column rendering (REQ-BGV-001, REQ-BGV-003)
- [x] Add the range/granularity header controls (start period, end period,
      granularity select — `month` default) that drive `BudgetGridReader
      ::columnsFor()`.
- [x] Render each column: budget-only for future columns, actual +
      text-labelled deviation for past columns (`design.md` §2d — never
      colour-only). A past column's actual value is always resolved from
      the GL-postingDate-bucketed data for exactly that column's own
      calendar span — no apportionment indicator needed (`design.md` §2c
      amended removed this limitation).
- [x] Render the `TOTAAL` column's begroot/werkelijk cumulative pair +
      deviation (`design.md` §3).

## 6. Frontend — computed/subtotal rows (REQ-BGV-008)
- [x] Add the `computedRows` page-config block (`design.md` §4) to
      `src/manifest.d/budget-grid-view.json`'s `BudgetGrid` page config,
      populated with the full `rj270-pl.json`-matching waterfall from task
      group 2 (`BRUTO-MARGE`/`KOSTEN`/`BEDRIJFSRESULTAAT`/
      `FINANCIEEL-RESULTAAT`/`RESULTAAT-VOOR-BELASTINGEN`/`NETTORESULTAAT`
      plus at least one % row), referencing the real root `LedgerGroup`
      `code`s `budget-core-schema`'s amended seed provides — no longer
      contingent on any follow-up seed task (resolved directly in
      `budget-core-schema`).
- [x] Client-side (or `BudgetGridCalculator`-side — implementation choice,
      `design.md` §4) formula evaluator for the `<code> [+|-] <code> …`
      arithmetic grammar.
      (Implemented server-side in `BudgetGridCalculator::evaluateComputedRows()`
      — `BudgetGridController` evaluates it once per column and ships
      already-resolved values, so the client never re-implements the
      grammar.)

## 7. e2e coverage (REQ-BGV-002, REQ-BGV-003, REQ-BGV-006, REQ-BGV-007)
- [x] Add `tests/e2e/budget-grid-view.spec.ts` covering
      `budget-grid-view::grid-renders-rows-and-columns`,
      `budget-grid-view::verzamelpost-expand-reveals-children`,
      `budget-grid-view::expand-keyboard-operable`,
      `budget-grid-view::grootboek-drill-through-navigates`,
      `budget-grid-view::past-column-shows-actuals-and-deviation`
      (`design.md` §10), modelled on
      `tests/e2e/budget-line-commitments.spec.ts` (SPDX header,
      `becomesVisible` helper, dismiss-wizard helper, data-defensive
      `test.skip()` when no `LedgerGroup`/`BudgetLine`/posted
      `GLTransaction`+`GLLine`/`FiscalPeriod` seed data exists for the
      current administration — **not** `TrialBalanceLine` seed data, which
      this change no longer reads). **Written, not executed** — per this
      task's own brief ("Write the Playwright spec, do NOT execute it").
- [x] Tag each Playwright test `@e2e budget-grid-view::<slug>` matching
      `specs/budget-grid-view/spec.md`'s scenario ids exactly (gate-19 /
      `hydra-gate-e2e-coverage`).

## 8. Validation
- [x] `node tests/check-manifest-budget.js` — PASS (report exact delta,
      task group 3).
- [x] `npm run check:nav-reachability` — PASS (task group 3).
- [x] Run the full PHPUnit suite for touched files: `BudgetGridReaderTest`,
      `BudgetGridCalculatorTest` — all green.
      (Full app tally: Tests: 4675, Assertions: 45462, Failures: 0,
      Skipped: 1 pre-existing.)
- [ ] `npx playwright test tests/e2e/budget-grid-view.spec.ts` — **NOT RUN**,
      per this task's own explicit instruction ("Write the Playwright spec,
      do NOT execute it").
- [x] `hydra-gate-keyboard-operable-controls` (ADR-059, once that gate
      lands) or a manual keyboard-only walkthrough of the grid's expand/
      collapse and drill-through — record the result.
      (No such named gate exists yet in the current hydra-gates inventory;
      gate-12 `nc-input-labels`, gate-32 `semantic-controls`, and gate-36
      `tabindex-positive` — the closest existing accessibility gates —
      all PASS against this change. A manual walkthrough could not be run
      in this sandbox, no live instance available; the toggle is a real
      `<button>`, which is keyboard-operable by construction, plus
      explicit `@keyup.enter`/`@keyup.space` handlers, asserted by the
      written (not executed) Playwright spec's own
      `expand-keyboard-operable` test.)
- [x] `openspec validate budget-grid-view --strict` — PASS.
