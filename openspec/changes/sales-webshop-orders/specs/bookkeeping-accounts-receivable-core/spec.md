# bookkeeping-accounts-receivable-core Specification (delta)

## Purpose

An order a web shop takes becomes an issued sales invoice in shillinq through
an intake record integriq's shop connector writes. From shillinq matrix row
`sal-webshop-sync`.

## ADDED Requirements

### Requirement: A received web shop order becomes one issued sales invoice (REQ-SWO-001)

When a `WebshopOrder` is written for a known `WebshopChannel`, shillinq SHALL
write one `ARInvoice` with a line per order line and one for shipping, issue it
when the channel's issue mode is `auto-issue`, record its id on the order and
move the order to `invoiced`. A second order record with the same channel and
shop order number MUST NOT write an invoice and SHALL be marked `duplicate`.

#### Scenario: A tea order becomes an invoice

- GIVEN web shop order 100231 from J. Bakker for 2 x Earl Grey los 250 g at EUR 8.95 and shipping EUR 4.95, received on channel theehandelvandijk.nl
- WHEN the order is written
- THEN the Accounts Receivable page lists an issued invoice for Webwinkel particulieren with buyer J. Bakker and a total of EUR 22.85
- AND the Web shop orders page shows order 100231 as invoiced with a link to that invoice

#### Scenario: The same order sent twice

- GIVEN order 100231 already invoiced
- WHEN integriq writes order 100231 again
- THEN no second invoice exists and the Web shop orders page shows the second record as a duplicate

### Requirement: The debtor is a matched business or the shop's collective consumer debtor (REQ-SWO-002)

For a business buyer the invoicer SHALL use the `CustomerMaster` of the
channel's administration with the same VAT id, else the same KvK number, else
the same invoice email, and SHALL create one marked as created by the channel
when none matches. A consumer order SHALL be booked on the channel's collective
debtor with the buyer's name and address on the invoice.

#### Scenario: A returning business buyer is recognised

- GIVEN customer Lunchroom De Theepot with VAT id NL000099997B57
- WHEN an order arrives from a business buyer with that VAT id
- THEN the invoice is booked on Lunchroom De Theepot and no new customer is created

### Requirement: Web shop VAT follows the destination rules (REQ-SWO-003)

Each order line SHALL be invoiced net of VAT at the rate the order routes to: a
domestic order at the line's own rate, a consumer in another EU member state at
that state's rate through OSS, a business buyer in another member state with a
validated VAT id under reverse charge with the mention "Btw verlegd". An order
whose route cannot be determined MUST be refused, not invoiced at the Dutch
rate.

#### Scenario: A consumer in Belgium

- GIVEN order 100232 from a consumer in Antwerpen with prices including VAT
- WHEN the order is invoiced
- THEN the invoice lines carry the Belgian VAT rate and the invoice is marked for the OSS return

### Requirement: A paid order is settled on the payment clearing account (REQ-SWO-004)

An order received with payment status paid SHALL leave its invoice `paid`,
settled against the channel's clearing account, with the provider's payment id
recorded on the invoice for the later payout match. An order with payment
status pending SHALL leave its invoice issued and open.

#### Scenario: An iDEAL order is paid on arrival

- GIVEN order 100231 paid by iDEAL with provider payment id tr_example0001
- WHEN it is invoiced
- THEN its AR invoice page shows state paid and payment reference tr_example0001

### Requirement: A refunded or cancelled order is credited (REQ-SWO-005)

When an invoiced `WebshopOrder` is updated to payment status refunded or
cancelled, shillinq SHALL write an `ARInvoice` of type credit-note for the
refunded lines, referencing the original invoice, and SHALL move the order to
`credited`.

#### Scenario: A customer returns the tea

- GIVEN invoiced order 100231
- WHEN the shop reports the order refunded
- THEN the Accounts Receivable page lists a credit note of EUR 22.85 referencing the invoice of order 100231

### Requirement: An order that cannot be invoiced is refused visibly (REQ-SWO-006)

An order for an unknown channel, without lines, or whose line totals differ
from the order total by more than one cent per line SHALL NOT be invoiced; it
SHALL move to `refused` with a reason naming the problem and both totals where
they differ, and the bookkeeper SHALL be notified.

#### Scenario: Totals that do not add up

- GIVEN order 100233 whose lines add up to EUR 45.20 while the order total is EUR 45.60
- WHEN the order is written
- THEN no invoice is written
- AND the Web shop orders page shows order 100233 as refused with both totals in the reason
