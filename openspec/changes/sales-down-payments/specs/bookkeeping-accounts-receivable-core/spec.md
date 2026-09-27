# bookkeeping-accounts-receivable-core Specification (delta)

## Purpose

A part of an order is invoiced up front as a down payment and taken off the
final invoice. From shillinq matrix row `sal-down-payment`.

## ADDED Requirements

### Requirement: A down-payment invoice is raised on an order (REQ-SDP-001)

A user SHALL be able to raise an `ARInvoice` of down-payment kind for a
customer and an order, for a percentage of the order's net total or a fixed
net amount. The order SHALL be referenced by semantic type
`https://schema.org/Order`; when the reference does not resolve to an order
whose totals shillinq can read, the user MUST enter the order's net total per
VAT rate. The down payment SHALL be split over the order's VAT rates in
proportion, SHALL carry invoice type code 386, and SHALL store the totals it
was computed from.

#### Scenario: A kitchen studio asks 30 percent up front

- GIVEN order Keuken Eiland 2026-117 for Familie De Boer with a net total of EUR 15,000 at 21 percent VAT
- WHEN a bookkeeper chooses New down-payment invoice on the Accounts Receivable page, picks the customer and the order and enters 30 percent
- THEN a draft invoice of EUR 4,500 plus EUR 945 VAT, total EUR 5,445, exists with type code 386 and the order named on it

### Requirement: A down payment is booked as an advance received (REQ-SDP-002)

Issuing a down-payment invoice SHALL post debit debtors, credit the
administration's advances account and credit VAT payable, and MUST NOT post to
a revenue account. The advances account SHALL default to 2310 Vooruitontvangen
bedragen in an administration seeded from the RGS template.

#### Scenario: The balance sheet shows the advance

- GIVEN the down-payment invoice of EUR 5,445 for Familie De Boer
- WHEN the bookkeeper issues it
- THEN the general ledger shows EUR 4,500 credited to 2310 and EUR 945 to VAT payable, and nothing on revenue

### Requirement: The final invoice deducts every open down payment of the order (REQ-SDP-003)

On a draft `ARInvoice` for a customer with issued down payments for an order
that no invoice has deducted, `ARInvoiceDetail` SHALL offer to make it the final
invoice of that order. Doing so SHALL add one negative line per down payment
and VAT rate at the down payment's own net and VAT, and SHALL reference each
down-payment invoice. Issuing it SHALL book the full revenue and debit the
advances account for the deductions.

#### Scenario: The kitchen is delivered and invoiced

- GIVEN the paid down payment of EUR 5,445 for order Keuken Eiland 2026-117
- WHEN the bookkeeper drafts the invoice for the kitchen at EUR 15,000 plus EUR 3,150 VAT and chooses Deduct down payments for that order
- THEN the invoice shows a deduction of minus EUR 4,500 and minus EUR 945 VAT referencing the down-payment invoice, and an amount due of EUR 12,705

### Requirement: A down payment is deducted once, and never beyond the invoice (REQ-SDP-004)

Issuing a final invoice MUST be refused when one of its deductions was already
deducted on another invoice, or when the deductions exceed the invoice's total.
On issue each deducted down payment SHALL record the final invoice that
deducted it.

#### Scenario: A second final invoice tries the same deduction

- GIVEN the down payment deducted on issued invoice 2026-0587
- WHEN a bookkeeper issues another draft that also deducts it
- THEN the issue is refused naming invoice 2026-0587

### Requirement: The order's down-payment position is visible (REQ-SDP-005)

`ARInvoiceDetail` of a down-payment or final invoice SHALL show, for its order,
every down payment with its amount, paid state and the invoice that deducted
it.

#### Scenario: The bookkeeper checks what is still to deduct

- GIVEN two down payments on order Keuken Eiland 2026-117, one deducted and one not
- WHEN the bookkeeper opens either down-payment invoice
- THEN the page lists both with their state and the invoice that deducted the first

### Requirement: The e-invoice carries the down payment and its deduction (REQ-SDP-006)

The UBL of a down-payment invoice SHALL carry invoice type code 386, and the UBL
of a final invoice SHALL carry the deduction lines as negative lines at their
VAT category and rate and a billing reference to each down-payment invoice.

#### Scenario: A municipality receives the final invoice over Peppol

- GIVEN a final invoice to Gemeente Voorbeeld deducting one down payment
- WHEN it is sent as an e-invoice
- THEN its UBL contains the negative deduction line and a billing reference to the down-payment invoice number
