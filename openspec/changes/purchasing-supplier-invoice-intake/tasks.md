# Tasks: purchasing-supplier-invoice-intake

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Intake

- [ ] 1.1 Read `PartyTaxScheme/CompanyID` and `PaymentMeans/PayeeFinancialAccount/ID` in `SupplierInvoiceService::parseUblInvoice()` and resolve `payeeId` by KvK, then VAT number (REQ-PSII-001). Verify: PHPUnit with UBL fixtures for a KvK hit, a VAT hit and no hit.
- [ ] 1.2 Add `payeeId`, `payeeIban`, `duplicateOfId`, `duplicateAcknowledgedReason`, `ibanMismatch`, `ibanAcknowledgedReason`, `apTransactionId` and `lines[].accountNumber` to `SupplierInvoice`, with seed objects (REQ-PSII-001). Verify: `npm run check:registers` and a re-import with no failed schemas.

## 2. Checks

- [ ] 2.1 Add `lib/Service/Purchasing/SupplierInvoiceChecks.php` with `duplicateOf()` (fail closed) and `ibanMismatch()`, and call them from the UBL import, the CSV import and a save listener for typed invoices (REQ-PSII-003, REQ-PSII-004). Verify: PHPUnit for each channel, a lookup failure and IBAN normalisation.
- [ ] 2.2 Show the duplicate and IBAN warnings, with acknowledgement fields, on `SupplierInvoiceDetail` and a warning column on `SupplierInvoices` (REQ-PSII-003, REQ-PSII-004). Verify: Playwright on the seeded typed duplicate and the mismatched IBAN.

## 3. Booking

- [ ] 3.1 Add the `APTransaction` mapper to `MaterialiseGlTransactionAction` and declare `materialise-gl-transaction` on `APTransaction.issue` (REQ-PSII-002). Verify: PHPUnit for a balanced invoice with VAT and a refused unbalanced one.
- [ ] 3.2 Add `SupplierInvoiceBookingGuard` and register it as the `requires` of a new `bookWithoutOrder` transition on `SupplierInvoice` (REQ-PSII-002). Verify: PHPUnit for an order-linked line, a missing account, an unacknowledged warning and a clean invoice.
- [ ] 3.3 Add `lib/Lifecycle/Action/HandToAccountsPayableAction.php`, registered under `hand-to-accounts-payable`, writing and issuing the `APTransaction` and setting the payment block on an IBAN mismatch (REQ-PSII-002, REQ-PSII-004). Verify: PHPUnit that one AP transaction is written once, blocked when flagged.
- [ ] 3.4 Add the Book without order button and line account coding to `SupplierInvoiceDetail` (REQ-PSII-002). Verify: Playwright books seed invoice 2026-0455 and opens its AP transaction.

## 4. End to end

- [ ] 4.1 Live check on a local instance with `ledger-posting-path` and `banking-payment-run` merged: import 2026-0455, book it, see the ledger lines, propose a payment run that includes it; import 2026-0456 and see it left out as blocked (REQ-PSII-002, REQ-PSII-004). Verify: object ids and screenshots in the PR body.

## 5. Docs

- [ ] 5.1 User guide section on booking supplier invoices without an order, and a release note on the two warnings. Verify: the page builds in `docs/` and the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/purchasing-supplier-invoice-intake/tasks.md#task-N` on every new method, Dutch and English strings for every warning.
