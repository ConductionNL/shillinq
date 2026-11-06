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
