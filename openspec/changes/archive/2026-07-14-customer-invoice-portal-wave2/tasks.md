# Tasks: customer-invoice-portal-wave2

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## Implementation Tasks

### Task 1: Append the ARInvoice + PaymentRequest customer collections

- **spec_ref**: `openspec/changes/customer-invoice-portal-wave2/specs/portal-contribution/spec.md#requirement-customer-subjects-can-see-their-own-ar-invoices-req-spc-020`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN a customer subject WHEN `getContribution()` is called THEN the manifest keeps the five Wave-1 collections first, then appends `salesInvoices` (schema `ARInvoice`, scopeField `customerId`, scopeClaim `customerMasterId`) and `paymentRequests` (schema `PaymentRequest`, scopeField `invoiceReference`, scopeClaim `customerMasterId`, reverse `via` `{register: shillinq, schema: ARInvoice, scopeField: customerId, targetField: id, match: scopeField}`)
  - GIVEN `salesInvoices` WHEN inspected THEN its `fields` whitelist includes `invoiceNumber`/`lines`/`dunning`/`sourceDocumentUri`/`ublXml` and EXCLUDES `glTransactionId`/`matchedBankLineId`/`writeOff`/`administrationId`/`settlementReference`
  - GIVEN `paymentRequests` WHEN inspected THEN its `fields` includes `paymentLink` (pay-now) and the manifest stays read-only (`actions: []`, `notifications: []`); supplier + accountant manifests are byte-for-byte unchanged
- [x] Implement
- [x] Test

### Task 2: Pin the IDOR boundary (security headline) and full contract in PHPUnit

- **spec_ref**: `openspec/changes/customer-invoice-portal-wave2/specs/portal-contribution/spec.md#requirement-another-debtors-invoice-is-unreachable-idor-req-spc-021`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the suite WHEN run via the php:8.3-cli container THEN it asserts NO customer collection scopes by `administrationId` or lacks a `scopeClaim`, `salesInvoices` scopes by the CustomerMaster UUID (`customerId`/`customerMasterId`), and `paymentRequests` is reachable ONLY through the reverse `via` join on `ARInvoice.customerId` (`match: scopeField`) — so another debtor's invoice/payment cannot enter the result set
  - GIVEN the previously-excluded schemas WHEN asserted THEN `ARInvoice`/`PaymentRequest` are removed from the customer exclusion set, dunning-run + goods-receipt schemas stay excluded, and no cross-audience leakage occurs
  - GIVEN the full suite WHEN run THEN it passes with zero new failures (baseline ~3716 green; 4 pre-existing Symfony\HeaderUtils env errors are not ours)
- [x] Implement
- [x] Test

### Task 3: Spec delta + gates

- **spec_ref**: `openspec/changes/customer-invoice-portal-wave2/specs/portal-contribution/spec.md`
- **files**: `openspec/changes/customer-invoice-portal-wave2/*`, `openspec/specs/portal-contribution/spec.md`
- **acceptance_criteria**:
  - GIVEN the change WHEN validated THEN `openspec validate customer-invoice-portal-wave2` passes and the delta ADDs REQ-SPC-020…022 to the `portal-contribution` capability
  - GIVEN the repo gates (`php -l`, phpcs, hydra mechanical gates) WHEN run THEN the changed files pass with zero new violations and no register JSON / route / portaliq file was touched
- [x] Implement
- [x] Test

## Quality checklist

- New manifest wiring covered by PHPUnit unit tests (`tests/Unit/Portal/`), incl. the mandatory IDOR boundary test
- No new API endpoints → no Newman collection; no UI change → no Playwright (the portal renders in portaliq)
- All tests pass (`vendor/bin/phpunit` in the php:8.3 container)
- No user-facing strings added inside shillinq (manifest labels are portal-side data; English source per i18n policy)
- `openspec validate customer-invoice-portal-wave2` passes
