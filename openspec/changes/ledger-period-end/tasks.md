# Tasks: ledger-period-end

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Checklist

- [ ] 1.1 Add `InstantiateCloseChecklistAction` and reference it on `FiscalPeriod.startClose`; extend the item category enum; mark `CloseChecklistInstance` deprecated (REQ-LPE-001). Verify: PHPUnit for empty list, non-empty list and missing template; `npm run check:registers`.
- [ ] 1.2 Resolve, Reopen and Add item actions in `PeriodCloseDetail.vue`, read-only when closed or locked (REQ-LPE-002). Verify: vitest for the component states.
- [ ] 1.3 Close checklist templates index and detail pages in `src/manifest.d/bookkeeping-period-close.json` with a settings menu entry (REQ-LPE-003). Verify: `npm run check:manifest` and the nav reachability check.

## 2. Deferrals

- [ ] 2.1 Add the `DeferralSchedule` schema with its lifecycle in a `register.d` fragment (REQ-LPE-004). Verify: `npm run check:registers`, `npm run check:seeds` with the seed from design.md.
- [ ] 2.2 "Spread over periods" line action on the AP and AR invoice detail pages creating the schedule and the opening entry (REQ-LPE-004). Verify: PHPUnit for the schedule maths (day count, remainder on the last release).
- [ ] 2.3 Release step in `SoftCloseExecutor` posting ended periods and completing the schedule (REQ-LPE-005). Verify: PHPUnit for a run, a rerun and the last release.

## 3. Accruals

- [ ] 3.1 Make `SoftCloseExecutor` write and post the `JournalEntry` it names, and the reversal per `reversalPattern` (REQ-LPE-006). Verify: PHPUnit asserting one entry per rule and period across two runs.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/ledger-period-end.spec.ts`: start close, resolve an item, spread an invoice line (REQ-LPE-001, REQ-LPE-002, REQ-LPE-004). Verify: passes locally.
- [ ] 4.2 Run `occ background-job:execute` for `SoftCloseJob` on a seeded instance and read the posted entries (REQ-LPE-005, REQ-LPE-006). Verify: the transaction ids are in the PR body.
- [ ] 4.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push; `@spec` tags on new methods.
