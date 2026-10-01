# Tasks: tax-vat-number-check

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Fields

- [x] 1.1 Add `VendorMaster.vatId` with validation fields and `SupplierInvoice.sellerVatId`; the repair step folding `CustomerMaster.vatID` into `vatId` (REQ-TVNC-002). Verify: `npm run check:registers`; PHPUnit for the repair.

## 2. Triggers

- [x] 2.1 Check VAT number actions on `CustomerDetail` and the vendor profile page calling `lookupVatId`, writing the result to the record (REQ-TVNC-001). Verify: route-auth and IDOR gates on `lookupVatId`; vitest for the action states.
- [x] 2.2 Read the seller VAT number in `ingestUBLInvoice()` and validate foreign EU numbers after intake (REQ-TVNC-002, REQ-TVNC-003). Verify: PHPUnit with a Belgian UBL fixture and a Dutch one.

## 3. Display, end to end and strings

- [x] 3.1 Status shown on AR and supplier invoice detail pages next to the number (REQ-TVNC-001, REQ-TVNC-003). Verify: `npm run check:manifest`.
- [ ] 3.2 (Playwright written, not run: the local instance runs the workspace checkout, not the branch.) Playwright `tests/e2e/tax-vat-number-check.spec.ts` with VIES stubbed. Verify: passes locally.
- [x] 3.3 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
