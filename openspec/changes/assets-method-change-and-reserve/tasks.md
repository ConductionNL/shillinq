# Tasks: assets-method-change-and-reserve

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Monthly posting

- [x] 1.1 `DepreciationRunJob` posting ended periods per administration, and the "Post missed depreciation" action (REQ-AMCR-001). Verify: PHPUnit for one period, a rerun and a catch-up preview; `npm run check:job-registration`.

## 2. Revision and extra depreciation

- [x] 2.1 Add the `revise` and `depreciateExtra` transitions and their fields to `FixedAsset` (REQ-AMCR-002, REQ-AMCR-003). Verify: `npm run check:registers`.
- [x] 2.2 `RecalculateDepreciationScheduleAction` for straight line, degressive and units of production (REQ-AMCR-002). Verify: PHPUnit per method with the oven example.
- [x] 2.3 Extra depreciation posting and recalculation (REQ-AMCR-003). Verify: PHPUnit.

## 3. Reinvestment reserve

- [x] 3.1 `ReinvestmentReserve` schema with calculation and lifecycle, and the reserve account setting (REQ-AMCR-004). Verify: `npm run check:registers`, `npm run check:seeds`.
- [x] 3.2 Reserve option in the disposal dialog and `FixedAssetDisposalService` (REQ-AMCR-004). Verify: PHPUnit that the gain goes to the reserve account.
- [x] 3.3 Apply on activate and release at expiry in the run (REQ-AMCR-005). Verify: PHPUnit for apply, partial apply and release.

## 4. End to end and strings

- [ ] 4.1 (Playwright written, not run: the local instance mounts the workspace checkout, not the branch, so it runs after landing. The change stays open until it passes.) Playwright `tests/e2e/assets-method-change-and-reserve.spec.ts`: revise the oven, sell the van into a reserve, apply it. Verify: passes locally.
- [x] 4.2 Dutch and English strings and a docs page on the reinvestment reserve. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
