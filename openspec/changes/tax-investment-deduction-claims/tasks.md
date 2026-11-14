# Tasks: tax-investment-deduction-claims

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Backend

- [ ] 1.1 `InvestmentAssetListener` creating or updating the `InvestmentAsset` on activation, keeping a manual override (REQ-IDC-001). Verify: PHPUnit with the real event classes; the payload validated against the real register fragment.
- [ ] 1.2 `InvestmentDeductionService::calculateYear` writing draft claims, refusing a year without tables (REQ-IDC-002). Verify: PHPUnit with the 2026 seed tiers.
- [ ] 1.3 `POST /api/investment-deductions/{year}/calculate` with route auth and the administration check (REQ-IDC-002). Verify: PHPUnit; route-auth and IDOR gates.

## 2. Pages

- [ ] 2.1 `InvestmentDeductions` page with totals and the recalculate action, and the tab on `FixedAssetDetail` (REQ-IDC-003). Verify: `npm run check:manifest`; nav reachability.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/tax-investment-deduction-claims.spec.ts`: activate an asset, see eligibility, recalculate the year. Verify: passes locally.
- [ ] 3.2 Dutch and English strings. Verify: `npm run test:l10n`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
