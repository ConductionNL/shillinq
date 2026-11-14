# Tasks: tax-vat-position-and-suppletie

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. VAT position

- [x] 1.1 `VatPositionService` sharing the box logic with `VATReturnService` (REQ-VPS-001). Verify: PHPUnit red first; the position of a closed period equals its prepared return. Built: `lib/Service/Tax/VatPositionService.php` over `VATReturnService::positionByBox()`, which runs the one `scanRubrieken()` derivation; `tests/Unit/Service/Tax/VatPositionServiceTest.php` (spec scenario 1a/5b/payable, the lines behind a box, position equals the prepared return).
- [x] 1.2 `GET /api/vat-position` with the administration check (REQ-VPS-001). Verify: controller test with a caller from another administration; route-auth and IDOR gates. Built: `VatPositionController::show`, `tests/Unit/Controller/VatPositionControllerTest.php`; hydra gates unchanged against development.
- [ ] 1.3 Position panel on `VATReports` with drill-down to lines (REQ-VPS-001). Verify: `npm run check:manifest`; vitest.

## 2. Supplementary return

- [ ] 2.1 Detect and prepare endpoints and their page actions (REQ-VPS-002). Endpoints built: `POST /api/vat-returns/{returnId}/detect-drift` and `POST /api/vat-corrections/{correctionId}/prepare` in `VatSuppletieController`, each checking the object's administration first (`tests/Unit/Controller/VatSuppletieControllerTest.php`, services doubled). Controller test over the real `VatSuppletieDetectionService` and `VATReturnService` built (`tests/Unit/Controller/VatSuppletieControllerRealServiceTest.php`): the written `VatCorrection` validates against the merged register, which needed `glAccountNumber` stored as '' instead of null for a delta with no filed line. Left: the page actions on the return and correction pages. Verify: PHPUnit on the controller with the real `VatSuppletieDetectionService`; the `VatCorrection` written validated against the real fragment.
- [ ] 2.2 Filing through the Digipoort hand-off, download before (REQ-VPS-002). Verify: PHPUnit.
- [ ] 2.3 Detection after a period close (REQ-VPS-003). Verify: listener test with the real event class, asserting `detect` runs for each filed return.

## 3. Strings and end to end

- [ ] 3.1 Dutch and English strings. Verify: `npm run test:l10n`.
- [ ] 3.2 Playwright `tests/e2e/tax-vat-position-and-suppletie.spec.ts`. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
