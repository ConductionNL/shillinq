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

`{ id, kind, number, title, status, amount, currency, issuedOn, dueOn, openAmount, paymentState, href, actions[] }`.
`paymentState` is one of `none`, `outstanding`, `paid`, `waived`, `stale`, with the meaning `CasePaymentState` already
gives them in dossiq. `href` opens the document in shillinq.

## D-4 Authorization before the read

The leaf lists only documents in administrations the caller may read (`AdministrationContextService`). A user who may
open the case but not the administration sees the count and the state, not amounts or numbers. Acts check their own
action first: `quote.create`, `invoice.issue`, `invoice.credit`, `payment.request` (exists), `payable.raise`. The
action list lives in `paymentActionGroups` and `paymentActionApps`, which already hold `payment.request`.

## D-5 The panel reads placement props

`kinds` (default: all five) and `actions` (default: every act the caller may do). `CnDetailWidgetHost` passes widget
`props` to the panel already. The panel reports `{count, noun, rows, actions}` to `CnLinkedWidget` like the payment
request panel does.

## D-6 Compatibility

`shillinq-payment-requests` and `shillinq-payment-requests-panel` stay registered and unchanged. The new panel shows
a superset. Apps move their placements in their own repos.
