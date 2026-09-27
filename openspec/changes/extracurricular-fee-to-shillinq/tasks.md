# Tasks: extracurricular-fee-to-shillinq

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 18. -->

No Nextcloud migration class (migration.md): task 1 is the schema change the
repair step imports.

## Implementation Tasks

### Task 1: Schema: PaymentRequest 0.4.0 and ARInvoice.contribution
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-the-payment-request-references-the-chargeable-in-the-owning-app-req-scon-004`
- **files**: `lib/Settings/register.d/ar-invoice-payment-links.json`, `lib/Settings/register.d/school-contributions.json`, `tests/Unit/Service/SchoolContributionsFragmentTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is built THEN PaymentRequest is 0.4.0 with subject.app, beneficiary, voluntary, raiseBatchId, settledAt, settledVia and requestType contribution
  - GIVEN the merged register WHEN it is built THEN ARInvoice is 0.14.0 with a nullable contribution group, and the seed rows carry the contribution shape
- [ ] Implement
- [ ] Test

### Task 2: The beneficiary joins the uniqueness key
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-the-raise-is-idempotent-per-chargeable-and-child-req-scon-003`
- **files**: `lib/Service/ObjectPaymentRequestValidator.php`, `tests/Unit/Service/ObjectPaymentRequestValidatorTest.php`
- **acceptance_criteria**:
  - GIVEN a pending contribution request for child A WHEN one for child B on the same subject is validated THEN it is accepted, and a second for A is refused naming the first
  - GIVEN two leges requests without a beneficiary WHEN validated THEN the existing refusal holds
- [ ] Implement
- [ ] Test

### Task 3: The invoice and request builder, with the voluntary text
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-a-voluntary-contribution-says-so-on-the-invoice-req-scon-007`
- **files**: `lib/Service/ContributionInvoiceBuilder.php`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `tests/Unit/Service/ContributionInvoiceBuilderTest.php`
- **acceptance_criteria**:
  - GIVEN a voluntary charge in Dutch WHEN the invoice is built THEN invoiceNote carries the notice and the line ends with "(vrijwillig)"
  - GIVEN a recipient amount WHEN built THEN it overrides the charge; the request references the chargeable and the child
- [ ] Implement
- [ ] Test

### Task 4: Debtor resolution and the portal claim
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-the-debtor-resolves-to-a-customer-the-portal-can-scope-req-scon-005`
- **files**: `lib/Service/ContributionDebtorResolver.php`, `tests/Unit/Service/ContributionDebtorResolverTest.php`
- **acceptance_criteria**:
  - GIVEN a portal subject without a claim and no customer WHEN resolved THEN one customer is created and the claim event is dispatched
  - GIVEN a customer with the email WHEN resolved THEN no customer is created
- [ ] Implement
- [ ] Test

### Task 5: The raise service, endpoint and route
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-a-bulk-raise-creates-one-invoice-and-one-payment-request-per-guardian-req-scon-001`
- **files**: `lib/Service/ContributionRaiseService.php`, `lib/Controller/ContributionController.php`, `appinfo/routes.php`, `tests/Unit/Service/ContributionRaiseServiceTest.php`, `tests/Unit/Controller/ContributionControllerTest.php`
- **acceptance_criteria**:
  - GIVEN three recipients WHEN raised THEN three issued invoices and three invoice-backed object requests exist
  - GIVEN a retried call WHEN raised THEN nothing new is written and each recipient is skipped naming its request
  - GIVEN a caller without payment.request WHEN raising THEN 403 and nothing written (REQ-SCON-002)
- [ ] Implement
- [ ] Test

### Task 6: Settlement through the invoice, and the settled edge
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-shillinq-signals-the-moment-a-request-is-settled-req-scon-009`
- **files**: `lib/Service/PaymentReconciliationService.php`, `lib/Service/PaymentSettlementService.php`, `lib/Controller/PaymentRequestActionController.php`, `tests/Unit/Service/PaymentReconciliationServiceTest.php`, `tests/Unit/Service/PaymentSettlementServiceTest.php`, `tests/Unit/Controller/PaymentRequestActionControllerTest.php`
- **acceptance_criteria**:
  - GIVEN an invoice-backed object request WHEN captured THEN the invoice moves to lifecycleState paid and no receipt is posted (REQ-SCON-006)
  - GIVEN a pending request WHEN captured twice THEN settledAt and settledVia are saved once
  - GIVEN a cash settlement covering the amount WHEN recorded THEN settledVia is cash
- [ ] Implement
- [ ] Test

### Task 7: A voluntary contribution gets one reminder at most
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-a-voluntary-contribution-is-never-dunned-beyond-one-reminder-req-scon-008`
- **files**: `lib/Service/Dunning/VoluntaryContributionPolicy.php`, `lib/Service/DunningRunService.php`, `tests/Unit/Service/Dunning/VoluntaryContributionPolicyTest.php`, `tests/Unit/Service/DunningRunServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a voluntary invoice 60 days late WHEN ticked twice THEN stage 1 runs once with no costs
  - GIVEN a voluntary invoice WHEN stage 2 or the incasso transfer is asked THEN it is refused
- [ ] Implement
- [ ] Test

### Task 8: A parent pays from the portal
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-a-guardian-sees-and-pays-the-contribution-from-the-portal-req-scon-010`
- **files**: `lib/Portal/PortalContributionProvider.php`, `lib/Service/Payment/PortalPaymentSessionService.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Service/Payment/PortalPaymentSessionServiceTest.php`
- **acceptance_criteria**:
  - GIVEN audience parent WHEN the manifest is asked THEN it lists the AR invoices, the payment requests and the pay action
  - GIVEN an issued contribution invoice (lifecycleState) WHEN a parent pays THEN the raised request is reused; after a failed one the fresh request keeps the reference
- [ ] Implement
- [ ] Test

### Task 9: The leaf reads every page and projects the new fields
- **spec_ref**: `openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md#requirement-the-payment-request-references-the-chargeable-in-the-owning-app-req-scon-004`
- **files**: `lib/Integration/PaymentRequestLeafProvider.php`, `tests/Unit/Integration/PaymentRequestLeafProviderTest.php`
- **acceptance_criteria**:
  - GIVEN 250 requests on one chargeable WHEN listed THEN all 250 come back with beneficiary, invoiceReference, voluntary, settledAt and settledVia
- [ ] Implement
- [ ] Test

## Verification

- All tasks checked off
- `openspec validate extracurricular-fee-to-shillinq` passes
- Diff-scoped checks green, then `composer check:strict` once, `npm run lint`, `npm run format`, `npm run test:l10n` and the hydra gates
- Code review against the spec requirements (opsx-verify)

## Tests (company-wide ADR-009)

- PHPUnit unit tests for all new and changed business logic, per test-plan.md
- Newman: N/A, one endpoint covered by `ContributionControllerTest`; no Postman collection covers the payment routes today
- Playwright: N/A, no screen in this change

## Documentation (company-wide ADR-010)

- N/A for `docs/`: no user-facing screen. The contract for the consuming apps is contract.md, and the PR body says what learniq and portaliq do on their side.

## i18n (company-wide ADR-005)

- Dutch and English strings for the voluntary notice and the "(voluntary)" suffix in `l10n/`
