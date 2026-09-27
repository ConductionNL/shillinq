# Tasks: planning-budget-editing

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Grid

- [ ] 1.1 Editable manual cells with version check and keyboard operation in `BudgetGrid.vue` (REQ-PBE-001). Verify: vitest for editable and read-only cells and a stale save; the ADR-059 keyboard gate.
- [ ] 1.2 Spread over months row action (REQ-PBE-002). Verify: vitest for the remainder on the last month.

## 2. Multi-year

- [ ] 2.1 Per-year sums in the grid controller read and the `MultiYearBudget` page with Start next year (REQ-PBE-003). Verify: PHPUnit for the sums and the copy; `npm run check:manifest`.
- [ ] 2.2 `MeerjarenBudget` and `Meerjarenraming` index and detail pages in the public-sector menu (REQ-PBE-005). Verify: nav reachability check.

## 3. Amendments

- [ ] 3.1 `Begrotingswijziging` index and detail pages with the determine action, files leaf and audit trail leaf (REQ-PBE-004). Verify: `npm run check:manifest`, nav reachability.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/planning-budget-editing.spec.ts`: type a cell, spread, start next year, record and determine an amendment. Verify: passes locally.
- [ ] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.
- [ ] 4.3 Docs page on editing the budget. Verify: `docs/` build.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
