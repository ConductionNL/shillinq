# Financial documents on any object

## ADDED Requirements

### Requirement: A financial document names the objects it is about (REQ-FDO-001)

`Quote`, `ARInvoice`, `PaymentRequest` and `InboundPayable` SHALL declare `linkedObjects`: a list of semantic
references (ADR-048) with the item shape `Contract.linkedObjects` has (`register`, `schema`, `id` required; `type`,
`label` optional) plus an optional `app`. Every item SHALL name a register, a schema and an id; a list with an item
that does not SHALL be refused. A quote or an invoice SHALL keep its list once it leaves `draft`. A payment request and
a payout SHALL keep their list from the moment they are raised. A change to a kept list SHALL be refused, with one
exception: an empty list MAY be filled with the payment request's own `subject`, which is what the migration writes.
`PayableRaisedEvent` SHALL accept `linkedObjects` and the payable SHALL store them. A payment request raised on an
object SHALL carry that object in `linkedObjects` beside `subject`. A repair step SHALL give every existing payment
request with a `subject` and no `linkedObjects` a one-item list holding that subject.

#### Scenario: An invoice names two cases

- GIVEN cases `c-1` and `c-2` in register `dossiq`, schema `case`
- WHEN an invoice is written with both in `linkedObjects`
- THEN the stored ARInvoice carries both references
- @e2e exclude schema property; covered by `LinkedObjectsFragmentTest`

#### Scenario: An issued invoice keeps its list

- GIVEN an issued invoice linked to case `c-1`
- WHEN a caller adds case `c-2`
- THEN the write is refused and the invoice still names only `c-1`
- @e2e exclude write guard; covered by `LinkedObjectsLockListenerTest`

#### Scenario: A draft quote can still change its list

- GIVEN a draft quote linked to case `c-1`
- WHEN a caller adds case `c-2`
- THEN the quote names both
- @e2e exclude write guard; covered by `LinkedObjectsLockListenerTest`

#### Scenario: A payout raised by another app names its object

- GIVEN dossiq raises a payout with `PayableRaisedEvent` and its case in `linkedObjects`
- WHEN shillinq stores the InboundPayable
- THEN the payable carries the case in `linkedObjects`
- @e2e exclude event contract; covered by `InboundPayableServiceTest`

#### Scenario: Existing payment requests get their subject as a list

- GIVEN a payment request with `subject` case `c-1` and no `linkedObjects`
- WHEN the repair step runs
- THEN its `linkedObjects` holds case `c-1` and its `subject` is unchanged
- @e2e exclude repair step; covered by `BackfillLinkedObjectsTest`

### Requirement: One leaf lists every financial document on an object (REQ-FDO-002)

The data leaf `shillinq-financial-documents` SHALL list the quotes, invoices, credit notes, payment requests and
payouts whose `linkedObjects` contain the host object, newest first, each as one row with `kind`, `number`, `status`, `amount`,
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
SHALL narrow it with the props `kinds` and `actions`. The leaf SHALL tell the panel which acts the caller holds, and the
panel SHALL offer only those: issue an invoice on the object (`invoice.issue`), request payment of an outstanding
invoice (`payment.request`), and send a payment link or record money that arrived another way on a payment request
(`payment.administer`). The server SHALL refuse an act the caller does not hold, with a 403 and nothing written, and
SHALL refuse an act in an administration the caller does not work in. The panel SHALL report its summary to the linked
card as the payment request panel does.

> New quote, credit invoice and raise payout followed as REQ-FDO-009.

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

### Requirement: A payout settled before finance kept it can be recorded (REQ-FDO-007)

`PayableRaisedEvent` SHALL accept `historicalState` (approved, paid, rejected or cancelled), `paidOn` and
`bankReference`. A payout raised with a historical state SHALL be stored in that state with `historical: true`, with no
journal entry and no callback, because the money moved outside shillinq. A historical paid payout SHALL need `paidOn`
as YYYY-MM-DD. Any other historical state SHALL be refused and nothing written.

> Assumption, made unattended: a payout dossiq paid through an ERP before shillinq kept payouts is recorded without a
> journal entry, because the ERP already booked it. If finance wants these booked, the import changes, not the reader.

#### Scenario: A penalty paid in March is recorded as paid

- GIVEN dossiq migrates a payout of 1,442 euros paid on 3 March 2026 with bank reference ERP-9
- WHEN it raises the payout with `historicalState: paid`
- THEN shillinq stores it as paid and historical, books nothing and calls nobody back
- @e2e exclude event contract; covered by `InboundPayableServiceTest`

### Requirement: The raising app reads its payouts back (REQ-FDO-008)

`PayablesRequestedEvent` SHALL answer an installed app with the payouts it raised, narrowed by correlation ids, by
category and by the year they were paid in, one row per payout with `payableId`, `reference`, `correlationId`,
`category`, `amount`, `payee`, `currency`, `state`, `paidOn`, `bankReference`, `historical` and `linkedObjects`. It SHALL NOT
answer with another app's payouts, and SHALL refuse an app that is not installed.

#### Scenario: The annual penalty audit reads 2026

- GIVEN dossiq raised one payout paid in 2026, one paid in 2025, and larpinq one paid in 2026
- WHEN dossiq asks for its payouts paid in 2026
- THEN shillinq answers with dossiq's 2026 payout only
- @e2e exclude event contract; covered by `InboundPayableServiceTest`

### Requirement: The panel starts a quote, credits an invoice and raises a payout (REQ-FDO-009)

The panel SHALL offer three more acts, each behind its own action and the caller's administration:

- new quote (`quote.create`): a draft quote linked to the object, with one line for the amount and description, for a
  customer named by id or by name and email;
- credit invoice (`invoice.credit`): for an issued or paid invoice, a credit note that is the negative of it, issued,
  on the same linked objects and referring to the invoice by number; a draft or a credit note SHALL be refused;
- raise payout (`payable.raise`): a payout to a payee and IBAN, with shillinq as the raising app, linked to the object;
  a payout finance refuses SHALL be a refusal with finance's words.

Each act SHALL be refused before anything is written.

#### Scenario: A handler credits an invoice raised in error

- GIVEN an issued invoice of 121 euros on case `c-1`
- WHEN the handler chooses "Credit invoice"
- THEN an issued credit note of minus 121 euros, referring to the invoice, is linked to case `c-1`
- @e2e exclude unit only; covered by `FinancialDocumentAuthoringTest`

#### Scenario: A quote is started on a case

- GIVEN a handler holding `quote.create`
- WHEN they start a quote of 4,500 euros for a customer on case `c-1`
- THEN a draft quote with one line of 4,500 euros is linked to case `c-1`
- @e2e exclude unit only; covered by `FinancialDocumentAuthoringTest`
