# Design: purchasing-supplier-invoice-intake

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

**Two AP records.** Purchasing keeps `SupplierInvoice`
(`lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json:753`,
lifecycle field `statusCode`: received, matching, matched, exception,
approved, paid, rejected), shown on `SupplierInvoices`
(`/inkoop/supplier-invoices`) and `SupplierInvoiceDetail`
(`src/manifest.json:13697` and `:13746`). The AP sub-ledger keeps
`APTransaction` (`register.d/bookkeeping-accounts-payable-core.json:298`),
which is what payment runs pay and what the AP pages and ageing read.
Nothing connects the two: no code in `SupplierInvoiceService`,
`ThreeWayMatchingEngine` or `GRIRClearingService` writes an
`APTransaction`.

**Import.** `SupplierInvoiceImportController::importUbl()`
(`lib/Controller/SupplierInvoiceImportController.php:189`) parses, calls
`duplicateExists()` (line 304) and returns 409 on a hit, then
`SupplierInvoiceService::ingestUBLInvoice()` (`lib/Service/SupplierInvoiceService.php:186`)
saves the invoice with `statusCode` received. `importCsv()` (line 233)
skips duplicates silently. `duplicateExists()` treats a lookup failure as
"no duplicate". `parseUblInvoice()` (line 572) reads the supplier as the
first `PartyIdentification/cbc:ID` or `EndpointID` into `supplierId`, which
the schema describes as a uuid FK to a vendor; it does not read
`cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID`. Lines
(`bookkeeping-purchase-order-3way-07-multi-po-consolidation.json`) carry
product, quantity, price, VAT rate and PO links, and no ledger account;
`gl-account-suggestion-consume.json` adds `suggestedGlAccount` at invoice
level.

**Posting.** The only ledger entry for a supplier invoice comes from
`GRIRClearingListener` on `matching` to `matched`
(`lib/Listener/GRIRClearingListener.php:126`). An invoice without an order
never matches, so it is never booked.

**Supplier records.** `Payee` (`bookkeeping-accounts-payable-core.json:11`)
has `kvkNumber`, `vatNumber`, `bankAccount.iban` and
`defaultExpenseAccountNumber`. `SupplierQualification`
(`register.d/procurement-governance.json:11`) has `supplierId`, `taxId` and
`iban`. Typed `APTransaction` records already refuse a duplicate number
through `APGuard::isInvoiceNumberUnique` on `receive`
(`lib/AppInfo/Application.php:908`); typed `SupplierInvoice` records are
not checked.

## Goals / Non-Goals

**Goals**

- An invoice without an order is booked and payable after one review, with no typing beyond the account coding.
- The same duplicate rule applies however the invoice arrived.
- A changed IBAN never pays silently.

**Non-Goals**

- Merging `SupplierInvoice` and `APTransaction`. That is schema consolidation work.
- Peppol receipt and OCR.

## Decisions

### D1. Hand over to the AP sub-ledger, do not post from purchasing

A new transition `bookWithoutOrder` (received to approved) on
`SupplierInvoice` declares a guard and an action. The guard,
`SupplierInvoiceBookingGuard`, allows it when no line links to a purchase
order, a payee is resolved, every line has an account, and any duplicate or
IBAN warning has an acknowledgement reason. The action,
`hand-to-accounts-payable`, writes one `APTransaction` (vendor, number,
dates, currency, total, tax, lines with accounts, source document), runs its
`receive` and `issue` transitions, and stores `apTransactionId` on the
supplier invoice. `APTransaction.issue` declares `materialise-gl-transaction`,
served by an `APTransaction` mapper added to the handler of
`ledger-posting-path`: debit each line's expense account, debit input VAT,
credit the payee's creditor account.

Alternative considered: post a journal entry straight from the supplier
invoice. Rejected: the invoice would be in the ledger but not in the AP
sub-ledger, so it would never be proposed for payment, aged, or matched to
a bank line.

### D2. Resolve the payee on intake

`parseUblInvoice()` also reads the supplier's `PartyTaxScheme/CompanyID`
and `PaymentMeans/PayeeFinancialAccount/ID`. Intake looks up a `Payee` in
the administration by `kvkNumber`, then `vatNumber`, and stores `payeeId`
and `payeeIban` on the invoice. With no hit the invoice shows "Supplier not
recognised" and a picker to choose or create one.

Alternative considered: match on name. Rejected: names are not unique and
the migration repair already treats a name-only match as unsafe
(`MigrateProductVendorMasterToPipelinq.php`).

### D3. One duplicate check, a warning with a reason

`SupplierInvoiceChecks::duplicateOf()` finds another `SupplierInvoice` or
`APTransaction` with the same number for the same payee in the
administration. The UBL import keeps its 409; the CSV import reports the
skipped rows by number; a typed invoice gets `duplicateOfId` on save and a
warning on its page. Booking needs `duplicateAcknowledgedReason`. A lookup
failure is treated as a possible duplicate, not as none.

Alternative considered: reject a typed duplicate outright. Rejected: two
suppliers do reuse numbers across years, and the row asks for a warning.

### D4. The IBAN check blocks payment, not booking

`SupplierInvoiceChecks::ibanMismatch()` compares `payeeIban`, normalised,
with `Payee.bankAccount.iban` and the payee's `SupplierQualification.iban`.
On a mismatch the invoice gets `ibanMismatch` with both values and a
warning. Booking needs an acknowledgement reason, and the handed-over
`APTransaction` is written with `paymentBlocked` true and reason "IBAN on
invoice differs from supplier record", which `banking-payment-run` then
keeps out of every run until someone releases it.

Alternative considered: refuse to book. Rejected: the liability exists
whatever account it names; what must not happen is paying the wrong one.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| When an invoice may be booked without order | Declarative transition with a `requires` guard | The lifecycle owns the gate. |
| Writing the AP transaction | Imperative lifecycle action handler | Cross-schema write; declared on the transition it serves. |
| Posting the AP transaction | Declarative `materialise-gl-transaction` on `APTransaction.issue` | Same handler as every other posting. |
| Duplicate and IBAN checks | Imperative, one shared service | Queries across two schemas and two supplier records. |
| Warnings on the page | Declarative manifest fields and badges | Plain fields on the object. |

## Seed Data

Fields are added to `SupplierInvoice` (`payeeId`, `payeeIban`,
`duplicateOfId`, `duplicateAcknowledgedReason`, `ibanMismatch`,
`ibanAcknowledgedReason`, `apTransactionId`) and `lines[].accountNumber`.
Seed for "Gemeente Voorbeeld": payee "Drukkerij Van der Meer B.V.", KvK
12345678, IBAN NL20INGB0001234567, default account 4300 Drukwerk; a UBL
invoice 2026-0455 from that KvK for EUR 1,210.00 (EUR 1,000.00 plus EUR
210.00 VAT) with the same IBAN; a second UBL invoice 2026-0456 naming IBAN
NL02ABNA0123456789; a typed invoice numbered 2026-0455 again.

## Risks / Trade-offs

- [Payee and pipelinq contact drift] → the payee is shillinq's AP master (ADR-107 decision 1); a contact in pipelinq is not consulted here.
- [Order-backed invoices are not handed over] → named in Open Questions of the proposal; this change does not make that gap worse.

## Migration Plan

No data migration. Received invoices without an order that exist today
can be booked through the new transition once coded.

## Open Questions

- See the proposal: whether order-backed invoices are handed over after matching.
