# Design: case-payment-requests

Kind: code. One schema extension, one booking rule, two leaves, one
portal projection.

## Context

`PaymentRequest` is invoice-shaped: `invoiceReference` is required and
`amount` is a calculation over the invoice. `PaymentReconciliationService`
settles the request and flips the invoice. The change loosens the shape so
a request can stand on any object, and gives the reconciliation a second
branch that books a receipt without an invoice.

## D1. `PaymentRequest`, extended

| property | change |
|---|---|
| `subjectKind` | new, enum `invoice`, `object`, required, default `invoice` |
| `subject` | new, semantic reference (ADR-048) `{type, register, schema, id}`; required when `subjectKind = object` |
| `invoiceReference` | now optional; required when `subjectKind = invoice` |
| `requestType` | new, enum `leges`, `dwangsom`, `deposit`, `other`; required when `subjectKind = object` |
| `description` | new, string, shown on the checkout and the receipt |
| `debtor` | new, reference to `CustomerMaster` or `{name, email}` when no master exists |
| `dueAt` | new, date-time, optional |
| `revenueAccount` | new, string, resolved from the `requestType` mapping at settlement |

The existing `amount` calculation applies only when `subjectKind =
invoice`; an object request stores `amount` as given. Uniqueness: at most
one `pending` request per `(subject, requestType)`.

## D2. Booking on settlement

`PaymentReconciliationService::reconcile()` gains a branch. When the
request is an object request and reaches `captured`, it posts one
`GLTransaction` with a receipt line against `revenueAccount` and a bank
or PSP clearing line, carrying the subject reference in the transaction
memo. A `paymentRevenueAccounts` setting maps each `requestType` to an
`Account`; an unmapped type refuses settlement with a named reason and
leaves the request in `captured_unapplied`, the state that already exists
for that case.

## D3. `shillinq-payment-requests`, kind data-provider

- `lib/Integration/PaymentRequestLeafProvider.php`, storage strategy
  `app-local`.
- `list(register, schema, objectId)` returns the object's requests with
  `state`, `amount`, `requestType`, `paymentLink`, `capturedAt`,
  `confirmationSummary`.
- `create(register, schema, objectId, payload)` appends a `PaymentRequest`
  with `subjectKind = object`, `subject` from the host, `requestedBy` from
  the caller. Refuses when the caller lacks the `payment.request` action
  (existing ADR-023 matrix) or when a `pending` request of that type exists.
- Registered on `RegisterLeafProvidersEvent` behind `class_exists()`.

## D4. `shillinq-payment-requests-panel`, kind render-surface

- Same id on both halves (gate-24): `widget` and `tab`.
- Lists the requests, offers "Send payment link" (mails the `paymentLink`
  through the existing dunning mail path to `debtor.email`) and "Mark paid
  by other means" (transitions to `captured` with `settlementReference`
  typed by the user, and books through D2).
- Reads through the data-provider leaf; writes through
  `POST /apps/shillinq/api/payment-requests/{id}/send` and `.../settle`.

## D5. The portal projection

The customer manifest's `paymentRequests` collection (REQ-SPC-020) already
scopes by the debtor's `customerMasterId` claim. An object request with a
`debtor` that resolves to that master appears in it. `portal-payment-initiation`'s
`pay` action (REQ-SPPI-002) reads the amount server-side from the request
instead of the invoice when `subjectKind = object`; nothing else changes.

## D6. Events out

The request's existing `x-openregister-notifications` rule already fires
on state change. The domain app subscribes to the object event on its own
side; shillinq never calls it (ADR-041, ADR-066).

## Risks

- A request created on an object the caller cannot read. `create` runs
  the read check on the host object before it appends.
- Double booking on webhook replay. Guarded by the existing
  `payment_intent.lastOutcome` check in integriq and the idempotent
  reconciliation on this side.
- A debtor without a `CustomerMaster`. The request stores `{name, email}`;
  the portal projection then shows nothing (no claim), and the payment
  link by mail is the path.
