# Tasks: arinvoice-lines-and-portal-amounts

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 13. -->

Stacked on `voluntary-contribution-reminder` (#1724). No Nextcloud migration class
(migration.md). Every fix starts with a test that fails.

## Implementation Tasks

### Task 1: The quick draft writes invoiceLines
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/shillinq-invoice-quick-draft/spec.md#requirement-the-quick-draft-saves-its-lines-as-invoicelines-req-iqd-006`
- **files**: `src/modals/invoiceQuickDraft.js`, `tests/vitest/invoiceQuickDraft.spec.js`
- **acceptance_criteria**:
  - GIVEN a real and an empty draft line WHEN the payload is built THEN invoiceLines holds one EN 16931 line and there is no lines key
- [x] Implement
- [x] Test

### Task 2: The recurring generator writes invoiceLines
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/recurring-invoicing/spec.md#requirement-req-rin-009-a-generated-invoice-shall-carry-its-lines-as-invoicelines`
- **files**: `lib/Service/RecurringInvoiceGenerator.php`, `tests/Unit/Service/RecurringInvoiceGeneratorTest.php`
- **acceptance_criteria**:
  - GIVEN a one-line profile WHEN the payload is built THEN invoiceLines carries the expanded line and there is no lines key
- [ ] Implement
- [ ] Test

### Task 3: The PDF reads ARInvoice lines
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#requirement-req-einv-009-the-hybrid-pdf-shall-print-the-arinvoices-own-lines`
- **files**: `lib/Service/InvoicePdfGenerator.php`, `tests/Unit/Service/InvoicePdfGeneratorTest.php`
- **acceptance_criteria**:
  - GIVEN an invoiceLines entry WHEN the hybrid PDF is built THEN the row shows its description, quantity, price, amount and rate; BillableInvoice cases unchanged
- [ ] Implement
- [ ] Test

### Task 4: The customer manifest names declared fields
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md#requirement-the-customer-manifest-names-the-fields-arinvoice-declares-req-sppi-007`
- **files**: `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN every listed manifest field is looked up THEN each is declared
- [ ] Implement
- [ ] Test

### Task 5: PaymentRequest.customerId, the stamping rule and the backfill
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md#requirement-a-request-without-an-invoice-is-listed-and-paid-in-the-portal-req-sppi-008`
- **files**: `lib/Settings/register.d/ar-invoice-payment-links.json`, `lib/Service/PaymentRequestPortalScope.php`, `lib/Integration/PaymentRequestLeafProvider.php`, `lib/Service/LegesIntakeStepService.php`, `lib/Repair/BackfillPaymentRequestCustomer.php`, `appinfo/info.xml`, tests for each
- **acceptance_criteria**:
  - GIVEN a request without an invoice and a debtor customer WHEN raised or back-filled THEN it carries customerId; an invoice-backed one does not; a second backfill saves nothing
- [ ] Implement
- [ ] Test

### Task 6: The portal lists and pays requests without an invoice
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md#requirement-a-request-without-an-invoice-is-listed-and-paid-in-the-portal-req-sppi-008`
- **files**: `lib/Portal/PortalContributionProvider.php`, `lib/Service/Payment/PortalPaymentSessionService.php`, `lib/Controller/PortalPaymentInitiationController.php`, `docs/api/`, tests
- **acceptance_criteria**:
  - GIVEN the subject's own pending request without an invoice WHEN paid THEN a checkout for its amount opens; a foreign, invoice-backed or captured request is forbidden
- [ ] Implement
- [ ] Test

### Task 7: Verify
- [ ] Diff-scoped checks, then `composer check:strict`, `npm run lint`, `npm run format`, `npm run test:l10n`, hydra gates, once
