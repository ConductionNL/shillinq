# object-payment-requests Specification

## Purpose
A payment request in shillinq asks a debtor for money on behalf of another app, for leges, a deposit or a school contribution, with or without an invoice behind it. This spec covers who raised a request and how: the requester is stored on every request, and an app holding the `paymentActionApps` grant can raise one as the system, with `requestedBy` set to `app:<appId>`, while every other caller is refused and nothing is written. The object-backed request itself is specified by the open change `case-payment-requests`.

## Requirements

### Requirement: REQ-SOPR-009: The requester of a leaf payment request SHALL be kept

PaymentRequest SHALL declare `requestedBy` (the Nextcloud user id of the caller),
so the value the leaf API writes (REQ-SOPR-003) is stored. Every key the leaf
writes SHALL be a declared PaymentRequest property.

#### Scenario: The leaf payload writes only declared fields

- GIVEN a mapped caller raising a request on a host object
- WHEN the leaf builds the request
- THEN `requestedBy` is the caller and every key is declared on PaymentRequest
- @e2e exclude leaf provider; covered by `PaymentRequestLeafProviderTest::testEveryWrittenKeyIsADeclaredPaymentRequestProperty`

### Requirement: A granted app raises a request without a user (REQ-SOPR-010)

Shillinq SHALL let an administrator grant `payment.request` to an app in the
app config key `paymentActionApps` (a JSON object from action to app ids).
The leaf SHALL offer `createAsApp(appId, register, schema, objectId,
payload)` beside `create`. It SHALL raise the same request as `create` when
the app is named for `payment.request` and is enabled, with or without a
signed-in user, and SHALL record `requestedBy` as `app:<appId>`. It SHALL
refuse with a 403 and write nothing for an app that is not named, an app
named only for another action, an app that is not enabled, and an id that is
not an app id. No app SHALL carry an action by default. `create` SHALL stay
as REQ-SOPR-003 has it: no payload key SHALL make it act for an app.

#### Scenario: larpinq raises the fee for a promoted registration

- GIVEN `paymentActionApps` is `{"payment.request": ["larpinq"]}` and larpinq is enabled
- AND nobody is signed in (larpinq's daily job moves a waitlisted registration up)
- WHEN larpinq calls `createAsApp('larpinq', ...)` on the registration with an amount
- THEN one `pending` request exists with the registration as subject and `requestedBy` `app:larpinq`
- @e2e exclude a background caller has no browser; covered by PHPUnit on `PaymentRequestLeafProvider::createAsApp()`

#### Scenario: An app without the grant is refused

- GIVEN `paymentActionApps` names only dossiq for `payment.request`
- WHEN larpinq calls `createAsApp('larpinq', ...)`
- THEN the leaf answers 403 and no request exists
- @e2e exclude authorization guard; covered by PHPUnit on `PaymentRequestLeafProvider::createAsApp()`

#### Scenario: A request cannot claim an app

- GIVEN larpinq carries the grant
- WHEN an anonymous caller posts to the leaf's create route with `callerApp: larpinq` in the body
- THEN the leaf answers 403 and no request exists
- @e2e exclude authorization guard; covered by PHPUnit on `PaymentRequestLeafProvider::create()`

### Requirement: A fee rule has a name and a calculation

A fee schedule MAY carry a `name`, a `variantValue` and a `calculation`: `fixed`, `percentage` (with `percentage`,
`minimumAmount` and `baseProperty`), `per_unit` (with `unitPrice` and `baseProperty`) or `refund_percentage` (with
`percentage`). A schedule whose calculation lacks a field it needs SHALL be refused at save. Resolving for an object
SHALL prefer a schedule for the object's own variant over one without a variant, and SHALL work the amount out from the
object; when the object lacks the base value the amount SHALL be left off rather than be zero.

#### Scenario: Building costs

- **GIVEN** a percentage fee of 2.35 with a minimum of 125 on `bouwkosten`
- **WHEN** a case with `bouwkosten` 100000 is resolved
- **THEN** the amount is 2350.00; with `bouwkosten` 1000 it is 125.00

#### Scenario: Trees on the felling route

- **GIVEN** a type fee of 245 and a per-unit fee of 82.40 for variant "Kappen"
- **WHEN** a case on variant "Kappen" with 2 trees is resolved
- **THEN** the per-unit fee applies with 164.80; a case on another variant gets 245

### Requirement: An event fee is a request type (REQ-ORS-001)

`PaymentRequest.requestType` SHALL accept `event-fee`, and a capture of an `event-fee` request SHALL book against the revenue account the `paymentRevenueAccounts` mapping gives for `event-fee`.

#### Scenario: Larpinq asks for an event fee

- GIVEN an administrator who mapped `event-fee` to revenue account 8100
- WHEN larpinq creates a request through the `shillinq-payment-requests` leaf with `requestType: event-fee` and amount 85.00 for Anna's registration
- THEN the request is saved as `pending`
- AND its capture books 85.00 against account 8100

### Requirement: A request carries a unique transfer reference (REQ-ORS-002)

The leaf's `create` SHALL accept `paymentReference` and store it on the request. Validation SHALL refuse a reference of fewer than 6 characters and a reference that a `pending` or `authorized` request already carries.

#### Scenario: A reference in use is refused

- GIVEN a pending request with reference `WC26-0042`
- WHEN larpinq creates another request with reference `WC26-0042`
- THEN the leaf refuses it and names the existing request

### Requirement: A bank line that quotes a reference is matched to the request (REQ-ORS-003)

When a `BankStatementLine` is saved whose `reference` or `remittanceInfo` contains a pending object request's `paymentReference` as a whole token, shillinq SHALL create a `ReconciliationMatch` with `targetType` `payment-request`. The match SHALL be confirmed at once only when exactly one request matches and the line's amount equals the amount still open; otherwise it SHALL stay a `candidate`.

#### Scenario: A transfer with the reference and the full amount is matched

- GIVEN a pending request `WC26-0042` for 85.00
- WHEN a bookkeeper imports a CAMT.053 statement with a line of 85.00 whose remittance reads "Winter Court WC26-0042 Anna"
- THEN a confirmed `payment-request` match links the line to the request

#### Scenario: A transfer for less waits for the bookkeeper

- GIVEN the same request
- WHEN the line is 50.00
- THEN the match is a `candidate` on the unmatched items page and the request stays `pending`

### Requirement: A confirmed bank match settles the request once (REQ-ORS-004)

On a confirmed `payment-request` match, shillinq SHALL append a `bank-transfer` settlement with the line's amount and reference, set `settledAt`, and book the receipt with the bank account on the debit side. When the request has an `invoiceReference`, it SHALL settle that invoice instead and book nothing on the object.

#### Scenario: Larpinq sees the registration paid

- GIVEN the confirmed match from REQ-ORS-003
- WHEN larpinq's listener reads the request's object event
- THEN the request shows `settledVia` `bank-transfer` and a `settledAt`
- AND the ledger holds one receipt of 85.00 for it

### Requirement: The debtor gets one receipt mail (REQ-ORS-005)

When `settledAt` is first set on a request with `subjectKind` `object` and a known `debtor.email`, shillinq SHALL mail the debtor the description, the amount, the date, the reference and the confirmation summary, and SHALL record `receiptSentAt`. A request with `receiptSentAt` set SHALL NOT be mailed again.

#### Scenario: Anna gets a receipt after paying online

- GIVEN Anna's pending request with her email address
- WHEN the payment provider reports it captured
- THEN Anna receives one mail naming "Winter Court 2026", 85.00 and `WC26-0042`
- AND a replay of the same provider event sends no second mail

### Requirement: An invoice asked for at create stands behind the request (REQ-ORS-006)

When the leaf's `create` receives `invoiceRequested: true`, shillinq SHALL issue one `ARInvoice` to the debtor's `CustomerMaster` for the request's description and amount and SHALL set the request's `invoiceReference`, so a capture settles the invoice and books the income once. Such a call SHALL name the `administrationId` that bills the invoice; without it, or without a debtor, the leaf SHALL refuse the call and write nothing.

#### Scenario: A player who asked for an invoice gets one

- GIVEN Sanne's registration with "Request an invoice" chosen
- WHEN larpinq creates her request with `invoiceRequested: true`
- THEN an issued invoice for 85.00 exists for Sanne
- AND the request's `invoiceReference` names it
