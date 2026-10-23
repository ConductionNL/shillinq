# Design: financial-documents-on-any-object

## D-1 One reference shape, copied from PaymentRequest

`subject` is the exact object shape `PaymentRequest.subject` already has (`ar-invoice-payment-links.json`). One shape
means one finder query (`subject.register`, `subject.schema`, `subject.id`) for every document kind, and the portal
scope logic that already reads it keeps working.

A document has one subject. The alternative is a `linkedObjects` array, as `Contract` has. A contract covers many cases
by nature. A quotation or an invoice is about one thing; when it is about several, the invoice lines name them.

## D-2 Kinds are a view over existing schemas, not a new schema

| kind | schema | filter |
|---|---|---|
| quote | Quote | |
| invoice | ARInvoice | `invoiceType` standard or debit-note |
| credit-note | ARInvoice | `invoiceType` credit-note |
| payment-request | PaymentRequest | `subjectKind` object, or an invoice request whose invoice is on this subject |
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
