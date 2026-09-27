# Tasks: reporting-data-delivery

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Schedules

- [ ] 1.1 Add `ReportSchedule` with its lifecycle in a `register.d` fragment (REQ-RDD-001). Verify: `npm run check:registers`, `npm run check:seeds`.
- [ ] 1.2 `ScheduledReportJob` with filing, sharing, notifications and failure handling, registered in `info.xml` (REQ-RDD-002). Verify: PHPUnit for due, not due and failed runs; `npm run check:job-registration`.
- [ ] 1.3 Schedules page and "Schedule this report" on the report dialog (REQ-RDD-001). Verify: `npm run check:manifest`; vitest for the prefill.

## 2. Feed

- [ ] 2.1 `lib/Settings/feeds.json` with the four datasets and a validator refusing an unbound dataset (REQ-RDD-003). Verify: a unit test on the validator.
- [ ] 2.2 Hand the declaration to integriq's owner as an issue describing the endpoints it needs (REQ-RDD-003). Verify: issue link in the PR body.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/reporting-data-delivery.spec.ts` for creating and pausing a schedule. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
