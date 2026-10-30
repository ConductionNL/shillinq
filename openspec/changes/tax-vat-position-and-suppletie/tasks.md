# Tasks: tax-vat-position-and-suppletie

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. VAT position

- [ ] 1.1 `VatPositionService` sharing the box logic with `VATReturnService` (REQ-VPS-001). Verify: PHPUnit red first; the position of a closed period equals its prepared return.
- [ ] 1.2 `GET /api/vat-position` with the administration check (REQ-VPS-001). Verify: controller test with a caller from another administration; route-auth and IDOR gates.
- [ ] 1.3 Position panel on `VATReports` with drill-down to lines (REQ-VPS-001). Verify: `npm run check:manifest`; vitest.

## 2. Supplementary return

- [ ] 2.1 Detect and prepare endpoints and their page actions (REQ-VPS-002). Verify: PHPUnit on the controller with the real `VatSuppletieDetectionService`; the `VatCorrection` written validated against the real fragment.
- [ ] 2.2 Filing through the Digipoort hand-off, download before (REQ-VPS-002). Verify: PHPUnit.
- [ ] 2.3 Detection after a period close (REQ-VPS-003). Verify: listener test with the real event class, asserting `detect` runs for each filed return.

## 3. Strings and end to end

- [ ] 3.1 Dutch and English strings. Verify: `npm run test:l10n`.
- [ ] 3.2 Playwright `tests/e2e/tax-vat-position-and-suppletie.spec.ts`. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
