# Financial documents on any object

## ADDED Requirements

### Requirement: A financial document names the object it is about (REQ-FDO-001)

`Quote`, `ARInvoice` and `InboundPayable` SHALL declare an optional `subject` with the shape `PaymentRequest.subject`
has: `app`, `type`, `register`, `schema`, `id`. A document SHALL keep its subject once it is issued, accepted or
approved; a write that changes it after that SHALL be refused. `PayableRaisedEvent` SHALL accept a `subject` and the
listener SHALL store it on the payable.

#### Scenario: An invoice raised on a case names the case

- GIVEN a case `c-1` in register `dossiq`, schema `case`
- WHEN an invoice is issued with `subject` `{app: dossiq, type: case, register: dossiq, schema: case, id: c-1}`
- THEN the stored ARInvoice carries that subject
- @e2e exclude schema property; covered by the register test with the merged register

#### Scenario: An issued invoice keeps its subject

- GIVEN an issued invoice on case `c-1`
- WHEN a caller writes `subject.id` `c-2`
- THEN the write is refused and the invoice still names `c-1`
- @e2e exclude write guard; covered by PHPUnit

#### Scenario: A payout raised by another app names its object

- GIVEN dossiq raises a payout with `PayableRaisedEvent` and a case as subject
- WHEN shillinq stores the InboundPayable
- THEN the payable carries the case as subject
- @e2e exclude event contract; covered by PHPUnit on `PayableRaisedListener`

### Requirement: One leaf lists every financial document on an object (REQ-FDO-002)

The data leaf `shillinq-financial-documents` SHALL list the quotes, invoices, credit notes, payment requests and
payouts whose subject is the host object, newest first, each as one row with `kind`, `number`, `status`, `amount`,
`currency`, `issuedOn`, `dueOn`, `openAmount` and `paymentState`. It SHALL check the caller's administration before it
reads. A caller who may not read the administration SHALL get the count and the payment state, and no amounts or
numbers.

#### Scenario: A case with an invoice and a payout

- GIVEN case `c-1` has an issued invoice of 120 euros and a payout of 357 euros
- WHEN the leaf is read for `c-1`
- THEN it answers two rows, kind `invoice` and kind `payout`, with their amounts
@e2e tests/e2e/financial-documents-on-any-object.spec.ts

#### Scenario: A caller outside the administration sees no amounts

- GIVEN a user who may open case `c-1` but is not a member of its administration
- WHEN the leaf is read for `c-1`
- THEN it answers a count of two and the payment states, and no amount, number or link
- @e2e exclude authorization guard; covered by PHPUnit on `FinancialDocumentLeafProvider`

### Requirement: The panel shows and creates documents, narrowed per placement (REQ-FDO-003)

The render leaf `shillinq-financial-documents-panel` SHALL show the rows on any detail page that places it. A placement
SHALL narrow it with the props `kinds` and `actions`. The panel SHALL offer only the acts the caller holds: new
quote (`quote.create`), issue invoice (`invoice.issue`), credit invoice (`invoice.credit`), request payment
(`payment.request`) and raise payout (`payable.raise`). The server SHALL refuse an act the caller does not hold, with a
403 and nothing written. The panel SHALL report its summary to the linked card as the payment request panel does.

#### Scenario: A case page shows only invoices and payment requests

- GIVEN a placement with `kinds: ["invoice", "payment-request"]`
- WHEN the case with a quote, an invoice and a payment request opens
- THEN the panel shows the invoice and the payment request, and not the quote
@e2e tests/e2e/financial-documents-on-any-object.spec.ts

#### Scenario: An act the caller does not hold is not offered and is refused

- GIVEN a user without `invoice.issue`
- WHEN the panel opens, and when the user posts an invoice to the leaf anyway
- THEN "Issue invoice" is not shown, and the post is refused with 403
- @e2e exclude authorization guard; covered by PHPUnit and vitest

### Requirement: An invoice on an object is payable through shillinq (REQ-FDO-004)

"Request payment" on an invoice row SHALL raise a `PaymentRequest` with `subjectKind` invoice, the invoice as
`invoiceReference` and the host object as `subject`. The request SHALL carry the invoice's open amount. On capture the
invoice SHALL settle and the row SHALL read paid. A second open request for the same invoice SHALL be refused.

#### Scenario: A citizen pays the invoice on their case

- GIVEN an issued invoice of 120 euros on case `c-1`
- WHEN the handler chooses "Request payment" and the debtor pays through the link
- THEN the invoice reads paid, and both rows on the case read paid
@e2e tests/e2e/financial-documents-on-any-object.spec.ts

### Requirement: An app raises a document without a user (REQ-FDO-005)

`createAsApp` SHALL accept the new kinds. It SHALL write only when `paymentActionApps` names the app for the act's
action and the app is enabled, and SHALL record the app as `app:<appId>`. No app SHALL carry an action by default.

#### Scenario: dossiq raises a payout from a background job

- GIVEN `paymentActionApps` is `{"payable.raise": ["dossiq"]}`
- WHEN dossiq raises a payout on case `c-1` with nobody signed in
- THEN one payout exists with the case as subject and `requestedBy` `app:dossiq`
- @e2e exclude a background caller has no browser; covered by PHPUnit

### Requirement: The payment request leaves keep working (REQ-FDO-006)

`shillinq-payment-requests` and `shillinq-payment-requests-panel` SHALL stay registered with their behaviour unchanged.

#### Scenario: An existing placement still renders

- GIVEN a page that places `shillinq-payment-requests-panel`
- WHEN it opens after this change
- THEN it shows the same requests and acts as before
- @e2e exclude unchanged component; covered by the existing panel tests
