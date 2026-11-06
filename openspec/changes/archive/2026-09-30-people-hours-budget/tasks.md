# Tasks: people-hours-budget

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Fields and declarations

- [x] 1.1 Add `loggedHours`, `hoursUsedPercent` (calculation), `hoursWarnedAt80`, `hoursWarnedAt100` and the two notifications to `ProjectAssignment` in a `register.d` fragment (REQ-PHB-001, REQ-PHB-002). Verify: `npm run check:registers`; the notification-dialect gate.

## 2. Listener

- [x] 2.1 `AssignmentHoursListener` on the hour source named by `hours-to-humaniq`, re-summing per assignment (REQ-PHB-001). Verify: PHPUnit for create, update, delete.
- [x] 2.2 Reset of the warned flags when `estimatedHours` changes (REQ-PHB-002). Verify: PHPUnit.
- [x] 2.3 Repair step summing existing hours (design Migration Plan). Verify: PHPUnit on a seeded register.

## 3. Pages, end to end and strings

- [x] 3.1 Hours card on `ProjectDetail` and the over-budget filter on the projects index (REQ-PHB-003). Verify: `npm run check:manifest`.
- [ ] 3.2 (Playwright written, not run: no live instance in this lane.) Playwright `tests/e2e/people-hours-budget.spec.ts`: book to 80 percent, see the warning. Verify: passes locally.
- [x] 3.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
