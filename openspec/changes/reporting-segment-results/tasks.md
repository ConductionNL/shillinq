# Tasks: reporting-segment-results

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Line fields

- [ ] 1.1 Add `signedAmount` (calculation), `accountClass` and `countsInResult` to `GLLine` in a `register.d` fragment (REQ-RSR-001). Verify: `npm run check:registers`; PHPUnit on the calculation through OpenRegister's evaluator.
- [ ] 1.2 `GLLineResultStampListener` for posted and reversed, registered in `Application.php` (REQ-RSR-002). Verify: PHPUnit for post, reversal and reversal of a reversal.
- [ ] 1.3 Repair step stamping existing lines (design Migration Plan). Verify: PHPUnit on a seeded register.

## 2. Aggregations and dashboard

- [ ] 2.1 Rewrite the five segment aggregations with the filter, `signedAmount`, and the revenue and costs split (REQ-RSR-002). Verify: an integration test against the aggregation endpoint with the seed data.
- [ ] 2.2 Revenue, costs and result columns and a period filter on `SegmentPnLDashboard.vue` (REQ-RSR-003). Verify: vitest for the row normalisation.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/reporting-segment-results.spec.ts` with the KP-300 example. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
