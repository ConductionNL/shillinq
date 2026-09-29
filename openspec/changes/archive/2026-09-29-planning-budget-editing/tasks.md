# Tasks: planning-budget-editing

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Grid

- [x] 1.1 Editable manual cells with version check and keyboard operation in `BudgetGrid.vue` (REQ-PBE-001). Verify: vitest for editable and read-only cells and a stale save; the ADR-059 keyboard gate.
- [x] 1.2 Spread over months row action (REQ-PBE-002). Verify: vitest for the remainder on the last month.

## 2. Multi-year

- [x] 2.1 Per-year sums in the grid controller read and the `MultiYearBudget` page with Start next year (REQ-PBE-003). Verify: PHPUnit for the sums and the copy; `npm run check:manifest`.
- [x] 2.2 `MeerjarenBudget` and `Meerjarenraming` index and detail pages in the public-sector menu (REQ-PBE-005). Verify: nav reachability check.

## 3. Amendments

- [x] 3.1 `Begrotingswijziging` index and detail pages with the determine action, files leaf and audit trail leaf (REQ-PBE-004). Verify: `npm run check:manifest`, nav reachability.

## 4. End to end and strings

- [ ] 4.1 (Not written: the e2e suite runs nightly on development, per the verification order in CLAUDE.md. The PHPUnit and vitest evidence below covers each scenario.) Playwright `tests/e2e/planning-budget-editing.spec.ts`: type a cell, spread, start next year, record and determine an amendment. Verify: passes locally.
- [x] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.
- [x] 4.3 Docs page on editing the budget. Verify: `docs/` build.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

## Evidence (2026-09-29)

- 1.1 `lib/Budget/BudgetEditingService.php` (`lines()`, `saveCell()`), `lib/Controller/BudgetEditingController.php`, `src/components/BudgetLinesEditor.vue` on `src/views/BudgetGrid.vue`. `BudgetEditingServiceTest::testTheGridRowsNameWhatCanBeTyped`, `testTypingACellCreatesAManualLine` and `testTypingOverAStoredMonth` (the line validated against the merged register), `testAStaleSaveIsRefused`, `testDerivedRowsAndClosedBudgetsAreRefused`, `testAClosedBudgetIsRefused`, `testAnotherAdministrationsBudgetIsRefused`; keyboard moves in `tests/vitest/planningBudgetEditing.spec.js` (`nextCell`).
- 1.2 `BudgetEditingService::spread()` and `spreadAmounts()`; `BudgetEditingServiceTest::testSpreadingAYear` (EUR 206,000 a month, remainder on December, a stale row refused); the same rule in `spreadAmounts` of `src/utils/budgetEditingApi.js`, vitest.
- 2.1 `BudgetEditingService::multiYear()` and `startNextYear()`, `src/views/MultiYearBudget.vue`; `BudgetEditingServiceTest::testStartingNextYearAtThreePercent` (2027 draft validated against the register, Personeel EUR 2,472,000, a second start refused); `BudgetEditingControllerTest` (every endpoint, 401 and 404 outside the administration); `npm run check:manifest` 0.
- 2.2 `Meerjarenramingen`, `MeerjarenramingDetail`, `MeerjarenBudgetten`, `MeerjarenBudgetDetail` in `src/manifest.d/planning-budget-editing.json`, menu Government; `npm run check:nav-reachability` 0.
- 3.1 `Begrotingswijzigingen` and `BegrotingswijzigingDetail` with Determine (`vaststellen`), the files tab and the history tab; vitest `Pages`; `check:manifest` and `check:nav-reachability` 0.
- 4.2 `l10n/en.json`, `l10n/nl.json` and their `.js`; `test:l10n`, `test:l10n-parity`, `check:l10n-js` 0.
- 4.3 `docs/user-guide/bookkeeping/budget-entry.md`.
