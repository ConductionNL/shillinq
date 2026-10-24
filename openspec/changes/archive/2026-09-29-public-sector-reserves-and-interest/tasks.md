# Tasks: public-sector-reserves-and-interest

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Reserves

- [x] 1.1 Add `ReserveMutation` and the per-year aggregations on `Reserve` in the programmabegroting fragment (REQ-PSRI-001). Verify: `npm run check:registers`, `npm run check:seeds`.
- [x] 1.2 Realising a mutation posts its journal entry (REQ-PSRI-001). Verify: PHPUnit.
- [x] 1.3 Reserve and investment index and detail pages in the public-sector menu (REQ-PSRI-001). Verify: `npm run check:manifest`, nav reachability.
- [x] 1.4 `ReserveMultiYearOverview` page with floor and ceiling flags (REQ-PSRI-002). Verify: vitest for the opening and closing chain.

## 2. Interest

- [x] 2.1 `InterestAllocationRun` schema with lifecycle, and the calculate and post service (REQ-PSRI-003). Verify: PHPUnit with the seed figures.
- [x] 2.2 Interest allocation page with Calculate and Post (REQ-PSRI-003). Verify: `npm run check:manifest`.

## 3. End to end and strings

- [ ] 3.1 (Written, not run: no live instance in this lane; the recipe is in the PR body.) Playwright `tests/e2e/public-sector-reserves-and-interest.spec.ts`. Verify: passes locally.
- [x] 3.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.

## Evidence (2026-09-29)

- 1.1 `lib/Settings/register.d/public-sector-reserves-and-interest.json` (own fragment rather than an edit of the programmabegroting one, ADR-037): `ReserveMutation` with the planned to realised lifecycle, `Reserve.openingBalanceYear`, `balanceAccountNumber`, `resultAccountNumber` and the `realisedByYear` aggregation on `ReserveMutation` (sum of `amount` per reserve, year and kind; first declared on `Reserve` with keys the engine does not read, moved before the PR), `Investering.taskFieldCode`, demo data for the design's seed. `npm run check:registers` 0, `npm run check:seeds` 0 (baseline lowered 53 to 51, two inherited seeds had been fixed).
- 1.2 `RealiseReserveMutationAction` over `ReserveBalances::realise()`: `testARealisedWithdrawalIsPostedAndCountsInItsYear` (journal 0520 D / 8990 C EUR 250,000 through postDirect, mutation and journal validated against the merged register), `testAReserveWithoutAccountsRefusesTheRealisation`.
- 1.3 `src/manifest.d/public-sector-reserves-and-interest.json`: Reserves, Reserve mutations (Realise), Investments, under Government. `npm run check:manifest` 0, `check:nav-reachability` 0 new orphans.
- 1.4 Built as a dashboard (`ReserveMultiYearOverview`, an object-table over `GET /api/v1/public-sector/reserves/overview`), not a custom page: hydra gate 69 refuses a new custom page. The chain is computed server-side, so its test is PHPUnit, not vitest: `testTheOverviewShowsThePlan` (closing 550,000 to 1,150,000, each year opening where the last closed, marked planned), `testTheOverviewFlagsFloorAndCeiling`, `ReserveOverviewControllerTest` (member 200, outsider 404, anonymous 401).
- 2.1 `InterestAllocationRun` (draft, calculated, posted; Calculate, Reopen, Post) with `InterestAllocationAction` over `InterestAllocationService`: `testThe2026InterestRunIsCalculatedAndPosted` (EUR 4,500,000 book value, EUR 54,000 on 5.2, EUR 9,600 to the reserve, journal 4810 D / 8050 C / 8990 D / 0520 C, a realised addition on the reserve, next year's opening 809,600), `testAnInvestmentWithoutBookValueOrTaskFieldIsLeftOut`, `testARunWithoutARateIsRefused`. The register validator refused a null inside a run line (excludedReason), found by the test before ship.
- 2.2 Interest allocation index and detail pages with Calculate, Reopen and Post. `npm run check:manifest` 0.
- 3.1 `tests/e2e/public-sector-reserves-and-interest.spec.ts` written for the three scenarios; not run in this lane.
- 3.2 English and Dutch strings for every label, schema title and message: `npm run test:l10n` 0, `test:l10n-parity` 0, `check:schema-l10n` 0 (baseline 12214 to 12210).

Design notes at build: amounts on the new schemas are euros (`amount`, not `amountCents`), matching `Investering.gross` and the journal lines; the run has a `calculated` state between draft and posted so Calculate and Post are separate transitions; the run carries its interest cost account and Treasury account, and a reserve its two accounts, because a journal line needs account numbers.
