# Tasks: receivables-sepa-direct-debit

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Backend

- [x] 1.1 `DirectDebitBatchService::propose` (REQ-SDD-011). Verify: PHPUnit red first with the real guards; collections and batch validated against the real fragment; an invoice without an active mandate is skipped with the reason.
  - Built: `lib/Service/Receivables/DirectDebitBatchService.php`. Open (`issued`, `overdue`) invoices with `paymentMethod: direct_debit`, the invoice's own mandate else the customer's `defaultMandateId`, one draft batch per scheme and sequence type (FRST for a recurring mandate's first collection, RCUR after, OOFF for a one-off), skipped invoices named with `no-mandate`, `mandate-inactive`, `mandate-ended`, `already-collected`, `not-euro` or `nothing-due`. Pinned by `DirectDebitBatchServiceTest`; every write is validated against the shipped fragment. The guards stay on the collections' `submit` transition (design D1); no endpoint calls `propose` yet, which is 2.1's page action.
- [x] 1.2 `SepaPain008Generator` and the `generate` action, XSD validated (REQ-SDD-012). Verify: PHPUnit asserts the XML validates against `pain.008.001.02.xsd` and carries the control sum; an invalid IBAN keeps the batch draft.
  - Built: `lib/Receivables/SepaPain008Generator.php` (one PmtInf per batch: SEPA, CORE or B2B, the batch's sequence type, creditor scheme id, a DrctDbtTxInf per collection with mandate reference and signing date), `SepaPain008Validator` (creditor name, IBAN and creditor identifier, every debtor IBAN's check digits naming its mandate, then the shipped `lib/Receivables/xsd/pain.008.001.02.xsd`), `lib/Service/Receivables/DirectDebitFileService.php` and `GenerateDirectDebitFileAction` on the batch's `generate` transition. A file with errors throws, so the transition fails and the batch stays draft with the errors. The creditor identifier is the new `Administration.sepaCreditorIdentifier`, else the one the batch's mandates agree on. Pinned by `SepaPain008GeneratorTest` and `DirectDebitFileServiceTest`.
- [x] 1.3 Download endpoint (REQ-SDD-012). Verify: controller test; a caller from another administration is refused; route-auth and IDOR gates.
  - Built: `lib/Controller/DirectDebitBatchController.php`: `GET /api/v1/direct-debit-batches/{id}/file` answers the stored XML as an attachment (409 before generate), and `POST /api/v1/direct-debit-batches/propose` runs 1.1's `propose` for the page action. Both `#[NoAdminRequired]`, administration checked in the body; another administration's batch is the same 404 as a missing one. Pinned by `DirectDebitBatchControllerTest`; route-auth and no-admin-idor report nothing on the new methods.
- [x] 1.4 `succeed` registers the payment, `reject` keeps the invoice open (REQ-SDD-013). Verify: PHPUnit with the real `InvoiceSettlementService`.
  - Built: `SettleDirectDebitCollectionAction` on `succeed` settles the invoice through `InvoiceSettlementService` (`mark-paid` / `pay-overdue`; a refused settlement refuses the transition); `RecordDirectDebitRejectAction` on `reject` requires a four-character reason code, stores it upper-cased and leaves the invoice open. Pinned by `DirectDebitCollectionOutcomeActionsTest`.

## 2. Pages

- [x] 2.1 `SepaMandates`, `DirectDebitBatches` and batch detail with propose, generate and download (REQ-SDD-011, 012). Verify: `npm run check:manifest`; nav reachability; vitest on the actions.
  - Built: `src/manifest.d/receivables-sepa-direct-debit.json` under Bookkeeping: Direct debit mandates (index and page with its collections and lifecycle), Direct debit batches (index with Propose direct debit: `ProposeDirectDebitModal` through `POST /api/v1/direct-debit-batches/propose`), the batch page (its collections, the Generate pain.008 transition, Download pain.008 once a file is stored) and Direct debit collections (index and page with the Settled and Rejected transitions; Rejected asks for the reason code through the transition's declared input). Helpers in `src/utils/directDebitApi.js` and `directDebitActions.js`, registered in `src/manifestActions.js`; the routes are in `openapi.json`. `check:manifest`, `check:nav-reachability`, `check:manifest-shell` pass; the manifest budget is re-measured and raised by the fragment's size. Pinned by `receivablesSepaDirectDebit.spec.js` and `proposeDirectDebitModal.spec.js`.

## 3. Strings and end to end

- [x] 3.1 Dutch and English strings. Verify: `npm run test:l10n`, `check:schema-l10n`.
  - Built: every new `t()`/`n()` string, manifest label and schema title/description in `l10n/en.json` and `l10n/nl.json`, `.js` rebuilt. `test:l10n`, `test:l10n-parity` and `check:l10n-js` pass; `check:schema-l10n` adds no uncovered string (it is already 10 over its baseline on development).
- [ ] 3.2 Playwright `tests/e2e/receivables-sepa-direct-debit.spec.ts`: register a mandate, propose, generate, download. Verify: passes locally.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
