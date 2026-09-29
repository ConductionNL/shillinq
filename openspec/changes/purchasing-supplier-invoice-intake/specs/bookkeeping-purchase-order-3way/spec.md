# bookkeeping-purchase-order-3way Specification (delta)

## Purpose

A supplier invoice without a purchase order is booked without typing, and
a duplicate invoice number is flagged however the invoice arrived. From
shillinq matrix rows `pur-ubl-import` and `pur-duplicate-check`.

## ADDED Requirements

### Requirement: An imported invoice finds its supplier (REQ-PSII-001)

On intake, shillinq SHALL resolve a UBL invoice's supplier to a `Payee` of
the administration by KvK number, then by VAT number, and SHALL store the
payee and the IBAN the invoice names. When no payee is found, the invoice
page SHALL say the supplier is not recognised and offer to choose or create
one.

#### Scenario: A UBL invoice is linked to its supplier

- GIVEN payee Drukkerij Van der Meer B.V. with KvK 12345678
- WHEN a bookkeeper imports UBL invoice 2026-0455 whose supplier party carries KvK 12345678
- THEN the supplier invoice page shows Drukkerij Van der Meer B.V. as supplier and IBAN NL20INGB0001234567 from the invoice

### Requirement: An invoice without an order is booked through accounts payable (REQ-PSII-002)

A received `SupplierInvoice` SHALL offer "Book without order". The transition SHALL require a resolved payee,
an expense account on every line (defaulting to the payee's default expense
account), and a reason for any open duplicate or IBAN warning. It SHALL
write one `APTransaction` for the invoice, issue it so that the ledger shows
the expense, the input VAT and the creditor, and link the two records. It
MUST be refused for an invoice with a line linked to a purchase order.

#### Scenario: A bookkeeper books a printing invoice without an order

- GIVEN received invoice 2026-0455 from Drukkerij Van der Meer B.V. for EUR 1,000.00 plus EUR 210.00 VAT, its line coded to 4300 Drukwerk
- WHEN the bookkeeper presses Book without order
- THEN the invoice shows state approved and links to an AP transaction in state issued
- AND the general ledger shows 4300 debit EUR 1,000.00, input VAT debit EUR 210.00 and the creditor credit EUR 1,210.00

#### Scenario: An order-backed invoice keeps the three-way match

- GIVEN a received invoice whose line links to purchase order PO-2026-031
- WHEN the bookkeeper presses Book without order
- THEN the transition is refused with a message naming PO-2026-031 and the three-way match

### Requirement: A duplicate invoice number is flagged however the invoice arrived (REQ-PSII-003)

Shillinq SHALL apply one duplicate rule to every supplier invoice: another
supplier invoice or AP transaction with the same number for the same payee
in the administration. A UBL import SHALL still be refused with 409; a CSV
import SHALL report every skipped row by invoice number; a typed invoice
SHALL be saved with a visible warning linking to the earlier one, and it
MUST NOT be booked until a reason is recorded. A failed lookup MUST be
treated as a possible duplicate.

#### Scenario: A typed invoice repeats an imported number

- GIVEN imported invoice 2026-0455 from Drukkerij Van der Meer B.V.
- WHEN a bookkeeper types a new supplier invoice 2026-0455 for the same supplier and saves
- THEN the invoice page shows a warning that 2026-0455 already exists, with a link to it
- AND Book without order asks for a reason before it proceeds
