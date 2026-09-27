# ar-invoice-payment-links Specification (delta)

## Purpose

A payment request and an invoice carry a working iDEAL or card link created
through integriq, and a payment through it is recorded without anyone pressing
settle. From shillinq matrix rows `sal-pay-link` and `rec-payment-request`.

## ADDED Requirements

### Requirement: A payment request gets a live link from the provider through integriq (REQ-RPL-001)

When a `PaymentRequest` is raised by the leges intake, the case panel, the
contribution raise or the portal pay flow, shillinq SHALL ask integriq's live
payment providers for a payment through a typed command event and SHALL store
the checkout link, the provider payment id and the checkout expiry on the
request. The request MUST NOT carry a link that no provider created.

#### Scenario: A case handler sends a leges payment link

- GIVEN a case handler on case Z-2026-00188 in dossiq with the payment requests panel, and a live Mollie source in integriq
- WHEN they create a leges request of EUR 97.50 and choose Send payment link
- THEN the applicant receives a mail with a Mollie checkout link
- AND the panel shows the request as pending with the link

### Requirement: Without a live provider there is no fake link (REQ-RPL-002)

When integriq is absent, the command event is not handled, or integriq's source
uses its log provider, the request SHALL carry no link and SHALL show that
online payment is not set up and where to set it up, and the Send payment link
action SHALL be disabled with that reason.

#### Scenario: An instance without a payment source

- GIVEN an instance whose integriq has no active payment source
- WHEN a bookkeeper opens a new payment request on the Payment requests page
- THEN the page says online payment is not set up and names integriq's payment source
- AND Send payment link is disabled

### Requirement: A payment reported by integriq is recorded automatically (REQ-RPL-003)

Shillinq SHALL take every `nl.conduction.payment.status` CloudEvent integriq
saves in its event register and reconcile it against the request with that
provider payment id: `captured` SHALL move the request to captured and settle
its invoice to paid, `failed` and `voided` SHALL be recorded with the reason.
A repeated event MUST change nothing.

#### Scenario: The applicant pays and the case sees it

- GIVEN the pending leges request of EUR 97.50 with provider payment id tr_example0002
- WHEN the applicant pays by iDEAL and integriq reports captured
- THEN the payment requests panel on case Z-2026-00188 shows paid with the date, without anyone pressing settle

#### Scenario: A paid invoice closes itself

- GIVEN invoice 2026-0412 with a pending request
- WHEN integriq reports the request captured
- THEN the invoice shows state paid on its AR invoice page

### Requirement: The invoice carries a pay link that works until it is paid (REQ-RPL-004)

The PDF and the mail of an open `ARInvoice` SHALL carry a pay URL signed for that
invoice, which, when followed, opens a live checkout for the amount still open,
reusing an unexpired checkout or creating a new one. For a paid or written-off
invoice the URL SHALL say so and open no checkout. A URL whose signature does
not verify MUST open nothing.

#### Scenario: A customer pays from the PDF three weeks later

- GIVEN invoice 2026-0412 mailed on 2026-10-01 with its pay link, whose first checkout expired the same day
- WHEN the customer follows the link on 2026-10-22
- THEN a new iDEAL checkout for EUR 302.50 opens

#### Scenario: The link after payment

- GIVEN invoice 2026-0412 paid
- WHEN the customer follows the link again
- THEN the page says the invoice has been paid and opens no checkout
