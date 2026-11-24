# ar-invoice-payment-links Specification (delta)

## Purpose

A card payment is taken on the spot on the user's own phone and booked in the
administration. From shillinq matrix row `sal-tap-to-pay`.

## ADDED Requirements

### Requirement: A user takes an invoice's payment on the spot on their phone (REQ-RPPO-005)

On `ARInvoiceDetail` of an open invoice, a user with a registered payment
device SHALL be able to take the open amount as a point-of-sale payment on that
device through integriq, and the capture SHALL settle the invoice as any
provider payment does. When the provider cannot push the payment to the device,
the page SHALL show the checkout as a QR code instead.

#### Scenario: A plumber is paid at the door

- GIVEN a plumber with a registered phone and open invoice 2026-0601 of EUR 145.20
- WHEN they choose Take payment now on the invoice page and the customer taps their card on the phone
- THEN the invoice page shows paid within the minute, with the provider payment id

### Requirement: A counter sale is a simplified invoice issued on capture (REQ-RPPO-006)

A user SHALL be able to record a counter sale on a phone-sized page with lines,
VAT per line and an optional customer, take its payment as in REQ-RPPO-005,
and on capture shillinq SHALL issue it as a paid simplified invoice. A failed or
abandoned payment SHALL leave a cancelled draft and nothing booked.

#### Scenario: A market stall sells a tea tin

- GIVEN a shop assistant on the Counter sale page with one line Theeblik groot EUR 12.50 at 21 percent VAT
- WHEN the customer pays on the assistant's phone
- THEN a paid simplified invoice of EUR 12.50 on the counter-sales debtor exists

#### Scenario: The customer walks away

- GIVEN the same counter sale
- WHEN the payment is abandoned
- THEN the draft is cancelled and no invoice is issued
