# Test Plan: extracurricular-fee-to-shillinq

All cases are PHPUnit unit tests (`tests/Unit/`), run with
`vendor/bin/phpunit -c phpunit-unit.xml --filter <Class>`. This change ships no
screen, so there is no Playwright case.

## Test Cases

### TC-1: Bulk raise writes one invoice and one request per recipient
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-a-bulk-raise-creates-one-invoice-and-one-payment-request-per-guardian-req-scon-001`
- **type**: functional
- **persona**: a school coordinator raising the ouderbijdrage
- **preconditions**: a charge of 60 euro, three recipients, an authorizer that allows `payment.request`, an in-memory object service
- **steps**: `ContributionRaiseService::raise()`
- **expected result**: three `ARInvoice` saves with `lifecycleState = issued` and three `PaymentRequest` saves with `subjectKind = object`, `requestType = contribution` and the matching `invoiceReference`; `raised = 3`
- **test command**: `--filter ContributionRaiseServiceTest`

### TC-2: A failing recipient does not stop the batch; a malformed call writes nothing
- **spec_ref**: `...#requirement-a-bulk-raise-creates-one-invoice-and-one-payment-request-per-guardian-req-scon-001`
- **type**: functional
- **preconditions**: one recipient naming a missing `customerMasterId`; separately, a chargeable without `id`, 201 recipients, amount 0
- **steps**: raise
- **expected result**: the other recipients are raised and the bad one is `failed` with a reason; the malformed calls throw `InvalidArgumentException` before any save
- **test command**: `--filter ContributionRaiseServiceTest`

### TC-3: Only a user with payment.request raises
- **spec_ref**: `...#requirement-only-a-user-with-the-paymentrequest-action-raises-req-scon-002`
- **type**: security
- **preconditions**: an authorizer that refuses; separately, no session
- **steps**: `ContributionController::raise()`
- **expected result**: 403 and 401, no service call writes
- **test command**: `--filter ContributionControllerTest`

### TC-4: Idempotency per chargeable and child
- **spec_ref**: `...#requirement-the-raise-is-idempotent-per-chargeable-and-child-req-scon-003`
- **type**: functional
- **preconditions**: a pending contribution request for child A on the chargeable; a call with child A twice and child B once
- **steps**: raise; validator on a second pending request for A and a first for B
- **expected result**: A is `skipped` naming the standing request, the duplicate A in the call is skipped, B is raised; the validator refuses a second pending A and accepts B
- **test command**: `--filter 'ContributionRaiseServiceTest|ObjectPaymentRequestValidatorTest'`

### TC-5: The request references the chargeable and the child; the leaf reads every page
- **spec_ref**: `...#requirement-the-payment-request-references-the-chargeable-in-the-owning-app-req-scon-004`
- **type**: functional
- **preconditions**: a portaliq `activityOffer` chargeable; 250 stored object requests on one chargeable
- **steps**: `ContributionInvoiceBuilder::buildRequest()`; `PaymentRequestLeafProvider::list()`
- **expected result**: `subject` carries `app`, `type`, `register`, `schema`, `id`; the invoice's `contribution.chargeable` equals it; the leaf returns 250 rows with `beneficiary` and `settledAt`
- **test command**: `--filter 'ContributionInvoiceBuilderTest|PaymentRequestLeafProviderTest'`

### TC-6: Debtor resolution and the portal claim
- **spec_ref**: `...#requirement-the-debtor-resolves-to-a-customer-the-portal-can-scope-req-scon-005`
- **type**: functional
- **preconditions**: (a) a portal subject without a claim and no customer; (b) a customer with the email; (c) a portal account with a claim
- **steps**: `ContributionDebtorResolver::resolve()`
- **expected result**: (a) one customer created and the claim event dispatched with app `shillinq`, claim `customerMasterId`; (b) no create; (c) the claimed customer is used and no event is dispatched
- **test command**: `--filter ContributionDebtorResolverTest`

### TC-7: An invoice-backed capture settles the invoice and books no receipt
- **spec_ref**: `...#requirement-an-invoice-backed-payment-request-settles-its-invoice-req-scon-006`
- **type**: regression
- **preconditions**: an issued invoice (`lifecycleState = issued`) and its pending object request with `invoiceReference`
- **steps**: `PaymentReconciliationService::reconcile()` with outcome captured
- **expected result**: the invoice is saved with `lifecycleState = paid`; no `GLTransaction` save; the request is `captured` with a confirmation summary
- **test command**: `--filter PaymentReconciliationServiceTest`

### TC-8: The voluntary text
- **spec_ref**: `...#requirement-a-voluntary-contribution-says-so-on-the-invoice-req-scon-007`
- **type**: functional
- **preconditions**: a voluntary charge and a compulsory charge, language `nl`
- **steps**: `ContributionInvoiceBuilder::buildInvoice()`
- **expected result**: the voluntary invoice carries the notice in `invoiceNote` and the suffix on the line; the compulsory one carries neither
- **test command**: `--filter ContributionInvoiceBuilderTest`

### TC-9: One reminder at most, never to a collection agency
- **spec_ref**: `...#requirement-a-voluntary-contribution-is-never-dunned-beyond-one-reminder-req-scon-008`
- **type**: functional
- **preconditions**: a voluntary invoice 60 days late on a three-stage ladder; the same invoice with a stage 1 run already written; an incasso request
- **steps**: `tickInvoice()`, `executeStage()` with stage 2, `transferToIncasso()`
- **expected result**: the first tick runs stage 1 with no costs; the second runs nothing; stage 2 and the transfer are refused; a compulsory invoice still reaches stage 3
- **test command**: `--filter 'DunningRunServiceTest|VoluntaryContributionPolicyTest'`

### TC-10: The settled edge, once, on both routes
- **spec_ref**: `...#requirement-shillinq-signals-the-moment-a-request-is-settled-req-scon-009`
- **type**: functional
- **preconditions**: a pending request; a replayed capture; a cash settlement covering the amount
- **steps**: `reconcile()` twice; `PaymentRequestActionController::settle()`; `PaymentSettlementService::stampSettled()`
- **expected result**: the first capture saves `settledAt` and `settledVia = provider`, the replay saves nothing; the cash settlement saves `settledVia = cash`; a partial settlement stamps nothing; an existing `settledAt` is never moved
- **test command**: `--filter 'PaymentReconciliationServiceTest|PaymentSettlementServiceTest|PaymentRequestActionControllerTest'`

### TC-11: A parent pays from the portal; a retry keeps the reference
- **spec_ref**: `...#requirement-a-guardian-sees-and-pays-the-contribution-from-the-portal-req-scon-010`
- **type**: api
- **preconditions**: a `parent` audience claim, an issued contribution invoice (`lifecycleState`), a pending raised request; separately, only a failed request
- **steps**: `PortalPaymentSessionService::initiate()`; `PortalContributionProvider::getContribution(['audience' => 'parent'])`
- **expected result**: a checkout URL through the raised request; on retry the minted request carries the contribution reference; the parent manifest lists the two AR collections and the `pay` action
- **test command**: `--filter 'PortalPaymentSessionServiceTest|PortalContributionProviderTest'`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-SCON-001 bulk raise | TC-1, TC-2 |
| REQ-SCON-002 authorization | TC-3 |
| REQ-SCON-003 idempotency | TC-4 |
| REQ-SCON-004 reference and leaf | TC-5 |
| REQ-SCON-005 debtor | TC-6 |
| REQ-SCON-006 invoice settlement | TC-7 |
| REQ-SCON-007 voluntary text | TC-8 |
| REQ-SCON-008 voluntary dunning | TC-9 |
| REQ-SCON-009 settled signal | TC-10 |
| REQ-SCON-010 portal | TC-11 |

## Out of Scope

- A live Mollie round trip and a live integriq delivery: they need external
  services; the payload and the edge are asserted at unit level.
- Playwright: no screen in this change.
