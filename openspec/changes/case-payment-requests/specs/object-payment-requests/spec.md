# object-payment-requests Specification (delta)

---
status: proposed
---

## Purpose

A payment request can stand on any object, not only on an invoice. A domain
app appends one through a leaf, the debtor pays through the existing
checkout, shillinq books the receipt, and the domain app learns the outcome
from the object event. Requested by the dossiq competitor analysis,
register row 12.12.

## ADDED Requirements

### Requirement: A payment request may stand on an object (REQ-SOPR-001)

Shillinq SHALL extend `PaymentRequest` with `subjectKind` (enum `invoice`,
`object`, default `invoice`), `subject` (a semantic reference, ADR-048),
`requestType` (enum `leges`, `dwangsom`, `deposit`, `other`),
`description`, `debtor`, `dueAt` and `revenueAccount`, and SHALL make
`invoiceReference` optional. `subject` and `requestType` SHALL be required
when `subjectKind = object`; `invoiceReference` SHALL be required when
`subjectKind = invoice`. At most one `pending` request SHALL exist per
`(subject, requestType)`.

#### Scenario: A request on a case validates without an invoice

- GIVEN a `PaymentRequest` with `subjectKind = object`, a `subject` naming a case, `requestType = dwangsom` and an `amount`
- WHEN it is saved
- THEN OpenRegister accepts it and the existing invoice calculation does not run
- @e2e exclude schema validation; covered by PHPUnit on the register import and the calculation guard

#### Scenario: A second pending request of the same type is refused

- GIVEN a `pending` `leges` request on a case
- WHEN a second `leges` request is created on that case
- THEN validation refuses it and names the existing request
- @e2e exclude uniqueness invariant; covered by PHPUnit

### Requirement: Settlement of an object request books a receipt (REQ-SOPR-002)

When an object request reaches `captured`,
`PaymentReconciliationService` SHALL post one `GLTransaction` with a
receipt line against the `Account` mapped to the `requestType` in
`paymentRevenueAccounts` and a clearing line, carrying the subject in the
memo. An unmapped `requestType` SHALL leave the request in
`captured_unapplied` with a reason naming the missing mapping. A domain
app SHALL NOT book the receipt itself (ADR-107).

#### Scenario: A captured dwangsom lands on the mapped revenue account

- GIVEN `paymentRevenueAccounts` maps `dwangsom` to account 8400 and a `pending` dwangsom request
- WHEN the signed webhook reports the payment captured
- THEN the request is `captured` and one `GLTransaction` exists with a line on 8400 for the amount and the case in its memo
- @e2e exclude webhook and ledger posting; covered by PHPUnit on the reconciliation branch with a signed fixture

#### Scenario: An unmapped type does not book

- GIVEN no mapping for `deposit`
- WHEN a deposit request is captured
- THEN the request is `captured_unapplied`, the reason names `deposit`, and no `GLTransaction` exists
- @e2e exclude covered by the same PHPUnit suite

### Requirement: Requests are a data-provider leaf with append (REQ-SOPR-003)

Shillinq SHALL register a leaf `shillinq-payment-requests` of kind
`data-provider`, storage strategy `app-local`, through
`RegisterLeafProvidersEvent`. `list` SHALL return the host object's
requests with state, amount, type, link and confirmation. `create` SHALL
append one request for the host object as the calling user, SHALL refuse a
caller without the `payment.request` action or without read on the host
object, and SHALL NOT call any action in the consuming app (ADR-066
decision 2).

#### Scenario: dossiq raises a dwangsom request on a case

- GIVEN dossiq places the leaf and a handler with the `payment.request` action
- WHEN dossiq's financial integration calls `create` with `requestType = dwangsom` and an amount
- THEN one `pending` request exists with the case as subject and `list` returns it with a `paymentLink`
- e2e: `tests/e2e/payment-request-leaf.spec.ts`

#### Scenario: A caller without the action is refused

- GIVEN a user whose groups are not mapped to `payment.request`
- WHEN that user calls `create`
- THEN the provider answers 403 and no request exists
- @e2e exclude authorization guard; covered by PHPUnit on `PaymentRequestLeafProvider::create()`

### Requirement: A panel shows and acts on the requests (REQ-SOPR-004)

Shillinq SHALL register a leaf `shillinq-payment-requests-panel` of kind
`render-surface` with `widget` and `tab` under one id. It SHALL list the
host object's requests and offer "Send payment link" (mails the link to
`debtor.email`) and "Mark paid by other means" (settles with a typed
`settlementReference` and books through REQ-SOPR-002). Both actions SHALL
run in shillinq's own bundle and DI context.

#### Scenario: A handler sends the link from the case

- GIVEN a `pending` request on a case with a debtor email
- WHEN the handler activates "Send payment link"
- THEN one mail with the `paymentLink` is dispatched to that address and the request records `linkSentAt`
- e2e: `tests/e2e/payment-request-panel.spec.ts`

#### Scenario: Descriptor and JS registration agree

- GIVEN both leaves are registered
- WHEN gate-24 inspects the app
- THEN each id has a descriptor and a JS registration with the complete render pair
- @e2e exclude parity is checked mechanically by gate-24

### Requirement: The debtor pays an object request through the portal (REQ-SOPR-005)

An object request whose `debtor` resolves to the subject's
`customerMasterId` claim SHALL appear in the customer manifest's
`paymentRequests` collection, and the `pay` action of
`portal-payment-initiation` SHALL charge the request's own `amount`,
server-side, when `subjectKind = object`. A request whose debtor resolves
to another master SHALL be unreachable, as REQ-SPC-021 requires.

#### Scenario: A citizen pays leges from the portal

- GIVEN a `pending` leges request whose debtor is the logged-in portal subject's master
- WHEN the subject activates pay
- THEN a checkout URL is returned for exactly the request's amount and the request moves to `authorized` on the provider's callback
- @e2e exclude needs a live provider round trip; the amount derivation is covered by PHPUnit on the initiation endpoint with an object request fixture
