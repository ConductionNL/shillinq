# Tasks: public-sector-reserves-and-interest

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Reserves

- [ ] 1.1 Add `ReserveMutation` and the per-year aggregations on `Reserve` in the programmabegroting fragment (REQ-PSRI-001). Verify: `npm run check:registers`, `npm run check:seeds`.
- [ ] 1.2 Realising a mutation posts its journal entry (REQ-PSRI-001). Verify: PHPUnit.
- [ ] 1.3 Reserve and investment index and detail pages in the public-sector menu (REQ-PSRI-001). Verify: `npm run check:manifest`, nav reachability.
- [ ] 1.4 `ReserveMultiYearOverview` page with floor and ceiling flags (REQ-PSRI-002). Verify: vitest for the opening and closing chain.

## 2. Interest

- [ ] 2.1 `InterestAllocationRun` schema with lifecycle, and the calculate and post service (REQ-PSRI-003). Verify: PHPUnit with the seed figures.
- [ ] 2.2 Interest allocation page with Calculate and Post (REQ-PSRI-003). Verify: `npm run check:manifest`.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/public-sector-reserves-and-interest.spec.ts`. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
