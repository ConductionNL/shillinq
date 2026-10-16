---
kind: code
depends_on: [ar-invoice-payment-links, portal-payment-initiation]
---

# Proposal: case-payment-requests

Competitor gap register, row 12.12 "Payments" (`procest/_gaps/gap-register.md`
in ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
shillinq, size M. Opened by the small-owner lane of the OpenSpec phase.

## Why

A case app needs to ask a citizen or a company for money: leges, a
dwangsom, a deposit. dossiq has one path, `DwangsomPaymentCallbackController`,
an ERP callback under its `financial-integration` spec (register note). It
raises nothing and shows nothing; it waits for an ERP to say a sum was
paid. The register puts the row on shillinq: payments are shillinq's
accounts receivable over integriq's live payment providers, and dossiq only
has the callback.

Shillinq already carries the primitive. `PaymentRequest`
(`lib/Settings/register.d/ar-invoice-payment-links.json`) has an amount, a
gateway, a lifecycle from `pending` to `captured`, a `paymentLink`, a
confirmation summary and a signature-gated webhook
(`PaymentRequestWebhookController`, REQ-APL-004). `portal-payment-initiation`
lets a debtor start a checkout for a request they own. What every one of
those assumes is an `ARInvoice` behind the request. A case is not an
invoice.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/payments/entities/payment_integration.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- `PaymentRequest` gains a `subject`: a semantic reference (ADR-048) to the
  object the money is asked for, with `subjectKind` (`invoice`, `object`)
  and `invoiceReference` becoming optional. A request on an object carries
  its own `amount`, `description`, `debtor` and `dueAt`; a request on an
  invoice keeps reading them from the invoice as today.
- Settlement of an object request books through the ledger as a receipt
  against a configurable revenue account per `requestType` (`leges`,
  `dwangsom`, `deposit`, `other`), so ADR-107 holds: shillinq books, the
  domain app never does.
- A data-provider leaf per ADR-066 with id `shillinq-payment-requests`:
  for a host object it lists that object's requests with their state and
  link, and its `create` appends one request for the host object under the
  calling user's rights. A render-surface leaf `shillinq-payment-requests-panel`
  shows them and offers "Send payment link" and "Mark paid by other means".
- On every state change the request emits the existing
  `x-openregister-notifications` rule so the domain app reacts to the
  object event, never to a callback into its controller.
- The portal: an object request appears in the debtor's existing "Pay my
  invoices" collection (REQ-SPC-020, REQ-SPC-021), scoped by the same
  server-derived claim, so `portal-payment-initiation`'s `pay` action works
  on it unchanged.

## How dossiq consumes it

The register's dossiq half: "financial-integration raises a payment
request instead of an ERP callback". dossiq places the two leaves on its
case detail page, and its `financial-integration` spec replaces the
dwangsom ERP callback with a `create` on `shillinq-payment-requests`
carrying `requestType = dwangsom` and the case as subject. dossiq reads the
captured state from the object event, retires
`DwangsomPaymentCallbackController`, and writes `case.paymentIndication`
from the request state. That is one task in dossiq's umbrella
`competitor-parity-2026-09`.

`leges-at-intake` (this repo, next change) is the intake-time
specialisation of this primitive.

## ADRs

- ADR-107: shillinq is the only ledger; a domain app never books income.
- ADR-048: the subject is a semantic reference, not a dossiq slug.
- ADR-066: two leaves, read and append only, no verb into dossiq.
- ADR-022 and ADR-067: the provider stays integriq's
  `live-payment-providers`; shillinq consumes its REST surface and the
  `nl.conduction.payment.status` CloudEvent as it does today.
- ADR-031: the request lifecycle and the notification stay declarative.

## Existing specs it extends

`ar-invoice-payment-links` (the `PaymentRequest` schema and webhook),
`portal-payment-initiation` (the subject-facing checkout) and
`portal-contribution` (the customer manifest, REQ-SPC-020 and 021).

## Out of scope

- A new gateway. Mollie through integriq's provider stays the one rail.
- Refunds. A captured object request is reversed through the existing
  credit path, not here.
- The intake form itself. `leges-at-intake` covers when and where the
  request is raised.
