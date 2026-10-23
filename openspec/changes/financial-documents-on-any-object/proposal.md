---
kind: code
depends_on: [case-payment-requests, inbound-payable-from-another-app]
---

# Proposal: financial-documents-on-any-object

## Why

Ruben, 23 Oct: "we should have generic support for attaching quotations, invoices etc to object. And invoices should
have payment support from shillinq."

shillinq already does half of this. A `PaymentRequest` names the object it is about in `subject` (ADR-048 semantic
reference), and the `shillinq-payment-requests` leaf shows the requests on any host object's detail page. dossiq
places that leaf on the case as "Fees and payments".

The other documents do not know their object. `Quote`, `ARInvoice` (which also carries credit and debit notes through
`invoiceType`) and `InboundPayable` have no `subject`. So a case cannot list its quotation, its invoice or the penalty
the municipality owes the applicant. Each app that needs one invents its own link, as dossiq did with its payout row.

## What changes

- `Quote`, `ARInvoice` and `InboundPayable` gain the same optional `subject` that `PaymentRequest` has: app, type,
  register, schema, id. It is written at creation and frozen once the document is issued.
- `PayableRaisedEvent` carries an optional `subject`, so an app that raises a payout says which object it is for.
- One data leaf, `shillinq-financial-documents`, lists every financial document standing on an object: quotes,
  invoices, credit notes, payment requests and payouts, in one row shape.
- One render leaf, `shillinq-financial-documents-panel`, shows them on any detail page and offers the acts. A placement
  narrows it with `kinds` and `actions` props, so a case page can show only invoices and payment requests.
- An invoice on an object is payable through shillinq: "Request payment" raises a `PaymentRequest` for the invoice
  (`subjectKind: invoice`) with the host object as its `subject`, and the payment link and the portal follow from there.
- Apps raise documents without a user through the existing `createAsApp` grant, widened to the new actions.
- The `shillinq-payment-requests` leaves stay registered. Placements keep working and move over in their own app.

## Not in this change

- No new OpenRegister or nc-vue mechanism. The leaf catalogue (ADR-066) and `CnIntegrationWidget` /
  `CnLinkedWidget` already mount a panel on any detail page with placement props.
- The quote to order to invoice flow (`sales-quote-and-order-to-invoice`) stays its own change. This change only lets
  a quote say which object it is about.
- `Invoice` (the bookings and Peppol schema beside `ARInvoice`) is not touched. Merging the two is a separate decision.
