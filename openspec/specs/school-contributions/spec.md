# school-contributions Specification

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

## Requirements

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

### Requirement: The voluntary reminder has its own template in English and Dutch (REQ-SCON-011)

Shillinq SHALL ship two templates in its docudesk template registry,
`tpl-dunning-voluntary-contribution-en` and `tpl-dunning-voluntary-contribution-nl`.
Each SHALL say that the contribution is voluntary and that the child takes part
whether the guardian pays or not, and SHALL say where to pay and where to say "I
will not pay". Neither SHALL name a payment term or due date, collection costs,
interest or a bank account. The template SHALL be chosen by
`ARInvoice.contribution.language`: a language starting with `en` picks English,
anything else, and a missing language, picks Dutch. The raise SHALL write
`contribution.language` from the charge.

#### Scenario: A Dutch guardian gets the Dutch voluntary reminder

- GIVEN a voluntary contribution invoice with `contribution.language = nl`, 60 euro, line text "Ouderbijdrage 2026-2027 (vrijwillig)"
- WHEN the reminder is rendered
- THEN the template id is `tpl-dunning-voluntary-contribution-nl`
- AND the body names the line text and 60,00 and says the contribution is voluntary and the child takes part
- AND the body names no term, costs, interest or IBAN, and no merge field is left unfilled
- @e2e exclude template rendering has no screen; covered by `VoluntaryReminderTemplateTest::testTheDutchReminderIsVoluntaryAndNamesNoTermsCostsOrInterest`

#### Scenario: An English guardian gets the English one, an unknown language gets Dutch

- GIVEN one invoice with `contribution.language = en_GB` and one without a language
- WHEN each reminder is rendered
- THEN the first uses `tpl-dunning-voluntary-contribution-en` and the second `tpl-dunning-voluntary-contribution-nl`
- @e2e exclude template rendering has no screen; covered by `VoluntaryReminderTemplateTest::testTheLanguagePicksTheTemplateAndFallsBackToDutch`

### Requirement: Every route to the voluntary reminder selects that template (REQ-SCON-012)

For an invoice whose `contribution.voluntary` is true, `DunningRunService::executeStage()`
SHALL write the voluntary template's id, rendered subject and rendered body on
the `DunningRun`, over the stage's `templateId` and over any template id, subject
or body the caller passed. `tickInvoice()` reaches the run through
`executeStage()` and SHALL therefore get the same template. Every other invoice
SHALL keep the stage template as before.

#### Scenario: The tick sends the voluntary letter, not stage 1

- GIVEN a voluntary contribution 60 days late on a ladder whose stage 1 uses `tpl-friendly`
- WHEN the dunning ticks it
- THEN the one run carries `templateId = tpl-dunning-voluntary-contribution-nl` and a rendered body that says the contribution is voluntary
- @e2e exclude scheduled dunning; covered by `DunningRunServiceTest::testAVoluntaryContributionGetsOneReminderAtMost`

#### Scenario: An operator cannot send the generic letter by hand

- GIVEN a voluntary contribution invoice
- WHEN `executeStage()` is called with `templateId = tpl-dunning-stage1-nl` and a rendered body that names an IBAN
- THEN the saved run carries the voluntary template id and its own body
- @e2e exclude operator dunning endpoint; covered by `DunningRunServiceTest::testExecuteStageAlwaysUsesTheVoluntaryTemplate`

### Requirement: A guardian can say they will not pay a voluntary contribution (REQ-SCON-013)

The parent portal manifest SHALL declare an endpoint-forward action `decline`
("I will not pay") with the field `invoiceId`, forwarded to
`POST /apps/shillinq/api/portal/contributions/decline`. The receiver SHALL verify
the `X-Portal-Subject` assertion, accept only the `parent` and `customer`
audiences, resolve the guardian's `customerMasterId` from their own portal account
and find the invoice by its uuid, keeping it only when that customer owns it. When the invoice is a
voluntary contribution in `issued` or `overdue`, it SHALL set `lifecycleState` to
`declined`, stamp `contribution.declinedAt`, and set every `pending` payment
request on the invoice to `voided`. An invoice this guardian already declined
SHALL answer the same success without a write. Any other target (foreign,
compulsory, paid, written off, missing or malformed) SHALL get one uniform 403.
The customer manifest SHALL NOT carry the action.

#### Scenario: A guardian declines the ouderbijdrage

- GIVEN an overdue voluntary contribution owned by the guardian's customer and one pending payment request on it
- WHEN the guardian chooses "I will not pay"
- THEN the invoice is `declined` with `contribution.declinedAt` set, the request is `voided`, and the answer is 200 `declined`
- @e2e exclude server-to-server receiver; covered by `ContributionDeclineServiceTest::testAGuardianDeclinesTheirOwnVoluntaryContribution`

#### Scenario: A compulsory, foreign or paid invoice cannot be declined

- GIVEN a compulsory contribution, another guardian's voluntary contribution, and a paid voluntary contribution
- WHEN the guardian asks to decline each
- THEN each answer is the same 403 and nothing is saved
- @e2e exclude server-to-server receiver; covered by `ContributionDeclineServiceTest::testEverythingButAnOpenOwnVoluntaryContributionIsForbidden`

#### Scenario: The receiver refuses a request without a verified parent assertion

- GIVEN no assertion, and separately a supplier assertion
- WHEN the decline endpoint is called
- THEN the first answer is 401 and the second 403, before any read
- @e2e exclude server-to-server receiver; covered by `PortalContributionDeclineControllerTest::testTheReceiverGatesTheAssertionAndTheAudience`

### Requirement: A declined contribution is closed (REQ-SCON-014)

`ARInvoice` SHALL declare a lifecycle state `declined`, with transitions
`decline` from `issued` and `decline-overdue` from `overdue`, each requiring
`VoluntaryDeclineGuard::requireVoluntary`, so a bookkeeper can record a refusal
received by mail or phone for a voluntary contribution and for nothing else. A
declined invoice SHALL NOT count as overdue. `tickInvoice()` SHALL run nothing for it and
`executeStage()` SHALL refuse it.

#### Scenario: Dunning leaves a declined contribution alone

- GIVEN a declined voluntary contribution 60 days past due
- WHEN the dunning ticks it, and an operator asks for stage 1 directly
- THEN the tick runs nothing and the direct call is refused, and no run exists
- @e2e exclude scheduled dunning; covered by `DunningRunServiceTest::testADeclinedContributionIsNeverReminded`

#### Scenario: The lifecycle guard admits only a voluntary contribution

- GIVEN a voluntary and a compulsory contribution invoice
- WHEN the `decline` transition asks the guard
- THEN the guard admits the first and refuses the second
- @e2e exclude lifecycle guard; covered by `VoluntaryDeclineGuardTest::testOnlyAVoluntaryContributionMayBeDeclined`
