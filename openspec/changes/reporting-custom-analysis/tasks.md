# Tasks: reporting-custom-analysis

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Widgets

- [ ] 1.1 Five `IAPIWidgetV2` classes under `lib/Dashboard/` and their registration (REQ-RCA-001). Verify: PHPUnit per widget with and without an active administration.
- [ ] 1.2 Live check in launchpad's widget picker (REQ-RCA-001). Verify: screenshot in the PR body.

## 2. Pivot

- [ ] 2.1 `GET /api/analysis/pivot` with the axis vocabulary and caps (REQ-RCA-002). Verify: PHPUnit; route-auth and IDOR gates.
- [ ] 2.2 `FinancialPivot` page with export and its Reports card (REQ-RCA-002). Verify: `npm run check:manifest`; vitest for the table.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/reporting-custom-analysis.spec.ts`. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
