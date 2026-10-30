# school-contributions Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- extracurricular-fee-to-shillinq

## Purpose

A school contribution (ouderbijdrage, overblijfbijdrage, schoolreisje, a club or
course fee) is an ordinary `ARInvoice` (schema:Invoice) with no order behind it,
raised in bulk for a set of guardians from a chargeable definition another app
owns. Each invoice carries one `PaymentRequest` (schema:Invoice) that references
the definition, so the owning app finds the payment state and hears when it is
settled. A voluntary contribution is marked on the invoice and never dunned
beyond one reminder (Wet vrijwillige ouderbijdrage, in force since 2021-08-01).
Decision D19; ADR-107 (shillinq books), ADR-048 (semantic references), ADR-066
(no verb into the consuming app), ADR-046 (portal contribution).

## ADDED Requirements

### Requirement: A bulk raise creates one invoice and one payment request per guardian (REQ-SCON-001)

Shillinq SHALL accept a chargeable definition, a charge (kind, description,
amount, currency, voluntary flag, administration) and 1 to 200 recipients, and
for each recipient SHALL create one `ARInvoice` in lifecycle state `issued` with
one line, no order reference and a `contribution` group, and one `PaymentRequest`
with `subjectKind = object`, `requestType = contribution` and `invoiceReference`
set to that invoice. A recipient's own `amount` SHALL override the charge amount.
A recipient that fails SHALL be reported with a reason and SHALL NOT stop the
others. The raise SHALL be exposed as `POST /apps/shillinq/api/contributions/raise`
and as `ContributionRaiseService::raise()` for in-process callers, with the same
array in and out.

#### Scenario: Three guardians are billed for the ouderbijdrage

- GIVEN a `FeeItem` in learniq for the ouderbijdrage of 60 euro and three recipients with a debtor and a child each
- WHEN a coordinator with the `payment.request` action raises it
- THEN three issued `ARInvoice` rows exist, each with one line of 60 euro and `contribution.kind = parental-contribution`
- AND three `PaymentRequest` rows exist with `subjectKind = object`, `requestType = contribution` and the matching `invoiceReference`
- AND the response reports `raised: 3` with the invoice and request id per recipient
- @e2e exclude API-only, no screen in this change; covered by `ContributionRaiseServiceTest::testRaisesOneInvoiceAndOneRequestPerRecipient`

#### Scenario: A reduction for one guardian

- GIVEN a charge of 60 euro and one recipient carrying `amount: 30`
- WHEN the raise runs
- THEN that recipient's invoice and request carry 30 euro
- @e2e exclude pure payload arithmetic; covered by `ContributionInvoiceBuilderTest::testRecipientAmountOverridesTheCharge`

#### Scenario: One failing recipient does not stop the batch

- GIVEN three recipients of which the second names a `customerMasterId` that does not exist
- WHEN the raise runs
- THEN the first and third are raised and the second is reported `failed` with a reason naming the missing customer
- @e2e exclude failure isolation inside one call; covered by `ContributionRaiseServiceTest::testOneFailingRecipientDoesNotStopTheOthers`

#### Scenario: A malformed call is refused whole

- GIVEN a call whose chargeable has no `id`, or with 201 recipients, or an amount of zero
- WHEN the raise runs
- THEN nothing is written and the endpoint answers 400 naming the problem
- @e2e exclude input validation; covered by `ContributionRaiseServiceTest::testRefusesAMalformedCall` and `ContributionControllerTest`

### Requirement: Only a user with the payment.request action raises (REQ-SCON-002)

The raise SHALL refuse a caller without a session (401 on the endpoint) and a
caller whose groups do not carry `payment.request` (403), through the same
`PaymentActionAuthorizer` the payment request leaf uses, and SHALL write nothing
when it refuses.

#### Scenario: A teacher without the action is refused

- GIVEN a user whose groups are not mapped to `payment.request`
- WHEN that user calls the raise
- THEN the endpoint answers 403 and no invoice or request exists
- @e2e exclude authorization guard; covered by `ContributionControllerTest::testRefusesACallerWithoutThePaymentRequestAction`

### Requirement: The raise is idempotent per chargeable and child (REQ-SCON-003)

Before writing, the raise SHALL skip a recipient for whom a `PaymentRequest` that
is not `voided` already stands on the same chargeable with the same beneficiary
and request type, and SHALL report it `skipped` with reason `already-raised` and
the standing request's id. Two recipients in one call with the same beneficiary
SHALL raise once. The pending-uniqueness check of `ObjectPaymentRequestValidator`
SHALL include the beneficiary, so two pending requests on one chargeable for two
children are allowed and two for one child are refused. A recipient without a
beneficiary SHALL use its debtor's `CustomerMaster` as the beneficiary.

#### Scenario: A retried chunk bills nobody twice

- GIVEN a raise that already billed the guardian of child A for a `FeeItem`
- WHEN the same call is sent again
- THEN no new invoice or request is written for child A and the result says `skipped`, `already-raised`, with the existing request id
- @e2e exclude idempotency invariant; covered by `ContributionRaiseServiceTest::testSkipsARecipientWhoseChildIsAlreadyBilled`

#### Scenario: Two children on one fee item are both pending

- GIVEN a pending contribution request for child A on a `FeeItem`
- WHEN a pending request for child B on the same `FeeItem` is validated
- THEN the validator accepts it, and a second pending request for child A is refused naming the first
- @e2e exclude validator invariant; covered by `ObjectPaymentRequestValidatorTest::testTheBeneficiaryJoinsTheUniquenessKey`

### Requirement: The payment request references the chargeable in the owning app (REQ-SCON-004)

Every raised `PaymentRequest` SHALL carry `subject` as a semantic reference with
`app`, `type`, `register`, `schema` and `id` of the chargeable, and `beneficiary`
naming the child. The `ARInvoice.contribution` group SHALL carry the same
chargeable and beneficiary, the kind, the voluntary flag and the batch id.
Identity SHALL stay `register`, `schema` and `id`: `app` and `type` describe the
reference and never make two references differ. The `shillinq-payment-requests`
leaf's `list` on the chargeable SHALL return every request on it, beyond the
first 200, with `beneficiary`, `invoiceReference`, `voluntary`, `settledAt` and
`settledVia`.

#### Scenario: Learniq finds the payment state of its fee item

- GIVEN 250 contribution requests raised on one `FeeItem`
- WHEN learniq lists the leaf on that `FeeItem`
- THEN it receives all 250, each with its beneficiary, its state and its `settledAt`
- @e2e exclude leaf read over OpenRegister; covered by `PaymentRequestLeafProviderTest::testListReadsEveryPageOfRequests`

#### Scenario: The reference names the owning app

- GIVEN a raise for portaliq's `activityOffer`
- WHEN the request is written
- THEN `subject` reads `{app: portaliq, type: activity-offer, register: portaliq, schema: activityOffer, id}` and the invoice's `contribution.chargeable` reads the same
- @e2e exclude payload shape; covered by `ContributionInvoiceBuilderTest::testTheRequestReferencesTheChargeableAndTheChild`

### Requirement: The debtor resolves to a customer the portal can scope (REQ-SCON-005)

For each recipient the raise SHALL resolve one `CustomerMaster`: the given
`customerMasterId` when it exists; else, for a `portalSubjectRef`, the
`customerMasterId` claim on that guardian's portal account; else the customer with
that email in the administration; else a new customer created from the name and
the email. When a `portalSubjectRef` was given and its account carries no claim,
the raise SHALL dispatch portaliq's `PortalAccountClaimRequestedEvent` duck-typed
(behind `class_exists()`) to link the account to the customer, and SHALL report
`portalLinked` per recipient. Without portaliq installed the raise SHALL still
bill, with `portalLinked: false`.

#### Scenario: A guardian who has never been billed

- GIVEN a recipient with a `portalSubjectRef`, a name and an email, no claim on the account and no customer with that email
- WHEN the raise runs
- THEN one `CustomerMaster` is created with that name and email, the invoice's `customerId` is its id, and the claim event is dispatched for the account
- @e2e exclude cross-app claim dispatch; covered by `ContributionDebtorResolverTest::testCreatesACustomerAndLinksThePortalAccount`

#### Scenario: A guardian billed before is found again

- GIVEN a customer with that email already exists in the administration
- WHEN the raise runs for a recipient with that email
- THEN no second customer is created and the existing one is billed
- @e2e exclude lookup order; covered by `ContributionDebtorResolverTest::testFindsTheCustomerByEmailBeforeCreatingOne`

### Requirement: An invoice-backed payment request settles its invoice (REQ-SCON-006)

When a `PaymentRequest` with an `invoiceReference` is captured,
`PaymentReconciliationService` SHALL settle that invoice through the `paid`
transition whatever the request's `subjectKind`, and SHALL NOT post the object
receipt, so income is booked once. The object receipt SHALL stay the path for an
object request without an invoice. The settlement SHALL read the invoice state
from `lifecycleState` (falling back to `state`) and SHALL write
`lifecycleState = paid`.

#### Scenario: A paid contribution closes its invoice and books nothing extra

- GIVEN an issued contribution invoice and its pending object request with `invoiceReference`
- WHEN the signed webhook reports the payment captured
- THEN the invoice's `lifecycleState` is `paid`, the request is `captured`, and no `GLTransaction` is posted by the object branch
- @e2e exclude webhook and ledger posting; covered by `PaymentReconciliationServiceTest::testAnInvoiceBackedObjectRequestSettlesItsInvoiceAndBooksNoReceipt`

### Requirement: A voluntary contribution says so on the invoice (REQ-SCON-007)

When the charge is voluntary, the invoice SHALL carry in `invoiceNote` that the
contribution is voluntary and that the child takes part whether or not it is paid,
the line description and the request description SHALL end with "(voluntary)",
and both texts SHALL come from the app's translation catalogue in the requested
language (Dutch by default). A compulsory charge SHALL carry neither.

#### Scenario: The ouderbijdrage invoice tells the parent it is voluntary

- GIVEN a voluntary charge raised in Dutch
- WHEN the invoice is built
- THEN `invoiceNote` reads "Deze bijdrage is vrijwillig. Uw kind doet mee, of u nu betaalt of niet." and the line ends with "(vrijwillig)"
- @e2e exclude invoice text; covered by `ContributionInvoiceBuilderTest::testAVoluntaryChargeIsMarkedOnTheInvoice`

### Requirement: A voluntary contribution is never dunned beyond one reminder (REQ-SCON-008)

For an invoice whose `contribution.voluntary` is true, the dunning SHALL send at
most one reminder: `tickInvoice` SHALL pick no stage after the first and no first
stage twice, `executeStage` SHALL refuse a stage above 1 or a second run, and SHALL
drop collection costs and interest from the one run it allows, and
`transferToIncasso` SHALL refuse the hand-over. A compulsory contribution and
every other invoice SHALL be dunned as before.

#### Scenario: A late voluntary contribution gets one friendly reminder

- GIVEN a voluntary contribution invoice 60 days overdue on a ladder whose stage 3 fires at 60 days
- WHEN the dunning ticks it
- THEN stage 1 runs once without collection costs or interest, and a later tick runs nothing
- @e2e exclude scheduled dunning; covered by `DunningRunServiceTest::testAVoluntaryContributionGetsOneReminderAtMost`

#### Scenario: A voluntary contribution is never handed to a collection agency

- GIVEN a voluntary contribution invoice
- WHEN an operator asks for the incasso transfer
- THEN the service refuses and no dossier is sent
- @e2e exclude refusal path; covered by `DunningRunServiceTest::testAVoluntaryContributionIsNeverHandedToACollectionAgency`

### Requirement: Shillinq signals the moment a request is settled (REQ-SCON-009)

The first time `PaymentSettlementService::report()` reports a request `paid` or
`overpaid`, shillinq SHALL write `settledAt` and `settledVia` in the same save,
once, and SHALL never clear or move them. `settledVia` SHALL be `provider` for a
capture and the settlement method for money recorded by hand. The documented
signal SHALL be OpenRegister's `ObjectUpdatedEvent` on `shillinq`/`PaymentRequest`
whose old object lacks `settledAt` and whose new object has it, and the same edge
in integriq's `com.nextcloud.openregister.object.updated` CloudEvent. Shillinq
SHALL NOT call the owning app.

#### Scenario: A captured contribution emits the edge once

- GIVEN a pending contribution request without `settledAt`
- WHEN the webhook reports it captured, and then reports it captured again
- THEN the first save carries `settledAt` and `settledVia = provider`, and the replay saves nothing
- @e2e exclude event edge over OpenRegister; covered by `PaymentReconciliationServiceTest::testACaptureStampsTheSettledEdgeOnce`

#### Scenario: Cash at the school desk settles it too

- GIVEN a pending contribution request of 60 euro
- WHEN an administrator records 60 euro cash against it
- THEN the saved request carries `settledAt` and `settledVia = cash`
- @e2e exclude settlement endpoint; covered by `PaymentSettlementServiceTest::testStampSettledMarksTheFirstPaidReport` and `PaymentRequestActionControllerTest::testSettleStampsTheSettledEdge`

### Requirement: A guardian sees and pays the contribution from the portal (REQ-SCON-010)

Shillinq's portal contribution SHALL serve the `parent` audience with the AR
invoice collection and the payment request collection, scoped by
`customerMasterId` exactly as for `customer`, and the `pay` action. The initiation
endpoint SHALL accept the `parent` audience, SHALL read the invoice state from
`lifecycleState` (falling back to `state`), SHALL reuse the raised pending request,
and when it mints a fresh one for a contribution invoice SHALL copy the
contribution reference onto it so the settled signal still names the owning app.

#### Scenario: A parent pays the schoolreisje

- GIVEN an issued contribution invoice whose customer is the parent's `customerMasterId` claim
- WHEN the parent activates pay in the portal
- THEN a checkout URL is returned for exactly the invoice amount through the raised pending request
- @e2e exclude needs a live provider round trip; covered by `PortalPaymentSessionServiceTest::testAParentPaysAnIssuedContributionInvoice`

#### Scenario: A retry after a failed payment keeps the reference

- GIVEN a contribution invoice whose only request failed
- WHEN the parent activates pay again
- THEN the new request carries `subjectKind = object`, the chargeable as `subject`, the beneficiary and `requestType = contribution`
- @e2e exclude mint path; covered by `PortalPaymentSessionServiceTest::testAFreshRequestForAContributionKeepsItsReference`

## Non-Functional Requirements

- **Performance:** one raise call of 200 recipients reads the standing requests
  of the chargeable once, not once per recipient.
- **Accessibility:** no screen in this change.
- **Internationalization:** the voluntary notice and the "(voluntary)" suffix
  SHALL exist in English and Dutch in `l10n/`.

## Acceptance Criteria

- A raise of N recipients writes N issued invoices and N requests, and a repeat
  of the same call writes none.
- A captured contribution closes its invoice and books no second receipt.
- A voluntary contribution invoice gets one reminder at most and never reaches a
  collection agency.
- `settledAt` is written once per request, on both routes to paid.
- A parent's portal lists and pays the contribution invoice.

## Notes

- Learniq and portaliq own their halves; see contract.md for what they call and
  what they listen for.
- The beneficiary is not validated against the owning app: shillinq reads nothing
  from another app's schema.
