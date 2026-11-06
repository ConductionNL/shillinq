# Design: financial-documents-on-any-object

## D-1 One list shape, copied from Contract

Ruben, 23 Oct: a document can link to several objects. Every kind gets `linkedObjects`, with the item shape
`Contract.linkedObjects` has (`register`, `schema`, `id`, optional `type` and `label`) plus an optional `app`, so a
payment request's `subject` converts without losing a field. One shape means one finder for every kind: a document
belongs on an object when any item matches its register, schema and id.

The list freezes when the document is issued: a quote or invoice when it leaves `draft`, a payment request or payout
when it is raised. `LinkedObjectsLockListener` enforces it on `ObjectUpdatingEvent`, the way
`PaymentRunLineLockListener` locks an approved run. OpenRegister's `immutable` keyword is not used: it freezes on the
first value, so a draft invoice could not gain a second case.

`PaymentRequest.subject` stays. `ObjectPaymentRequestValidator` keys its one-open-request invariant on it and the
portal scope reads it. The list is written beside it, and the repair step backfills old rows.

## D-2 Kinds are a view over existing schemas, not a new schema

Every row below is a document whose `linkedObjects` contain the host object.

| kind | schema | filter |
|---|---|---|
| quote | Quote | |
| invoice | ARInvoice | `invoiceType` standard or debit-note |
| credit-note | ARInvoice | `invoiceType` credit-note |
| payment-request | PaymentRequest | |
| payout | InboundPayable | |

No `FinancialDocument` base schema. Money keeps one home per kind (ADR-107), and a base schema would be a second
place to read amounts from.

## D-3 Row shape

`{ id, kind, number, status, amount, currency, issuedOn, dueOn, openAmount, paymentState, path, restricted }`.
`paymentState` is one of `none`, `outstanding`, `paid`, `waived`, the words dossiq's case already uses. `path` is the
document's page in shillinq, for the panel to pass through `generateUrl`; only invoices and credit notes have one
today. A quote has no amount of its own, so its `amount` is null. Rows sort newest first by `issuedOn`.

## D-4 Authorization before the read

The finder reads twice per schema: past the caller's rights to count, and as the caller to know what they may open. A
document the caller may not read is listed with `restricted: true` and only its kind, status and payment state. A case
never reads "nothing owed" while an invoice its handler cannot open is outstanding. Acts check their own
action first: `quote.create`, `invoice.issue`, `invoice.credit`, `payment.request` (exists), `payable.raise`. The
action list lives in `paymentActionGroups` and `paymentActionApps`, which already hold `payment.request`.

## D-5 The panel reads placement props

`kinds` (default: all five) and `actions` (default: every act the caller may do). `CnDetailWidgetHost` passes widget
`props` to the panel already. The panel reports `{count, noun, rows, actions}` to `CnLinkedWidget` like the payment
request panel does.

## D-6 Compatibility

`shillinq-payment-requests` and `shillinq-payment-requests-panel` stay registered and unchanged. The new panel shows
a superset. Apps move their placements in their own repos.
