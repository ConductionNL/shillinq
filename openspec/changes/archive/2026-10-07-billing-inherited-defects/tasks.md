# Tasks: billing-inherited-defects

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

Every fix starts with a test that fails first.

### Task 1: The portal payment reads the invoice by uuid, then slug
- **spec_ref**: `specs/portal-payment-initiation/spec.md` (REQ-SPPI-011)
- **files**: `lib/Service/Payment/PortalPaymentSessionService.php`, `tests/Unit/Service/Payment/PortalPaymentSessionServiceTest.php`
- **acceptance_criteria**:
  - the stub matches nothing on an `id` filter, as OpenRegister does; the uuid and slug cases pay
- [x] Implement and test

### Task 2: Declare the dropped fields
- **spec_ref**: REQ-RIN-010, REQ-IQD-007, REQ-SOPR-009
- **files**: `lib/Settings/register.d/billing-inherited-defects.json`, `lib/Settings/register.d/school-contributions.json`, `tests/Unit/Register/BillingPayloadDeclaredFieldsTest.php`
- **acceptance_criteria**:
  - ARInvoice 0.16.0, PaymentRequest 0.6.0; schema-l10n keys present
- [x] Implement and test

### Task 3: The recurring payload carries invoiceNumber, periodId and the line account
- **spec_ref**: REQ-RIN-010
- **files**: `lib/Service/RecurringInvoiceGenerator.php`, `tests/Unit/Service/RecurringInvoiceGeneratorTest.php`
- [x] Implement and test

### Task 4: The quick draft writes the line account
- **spec_ref**: REQ-IQD-007
- **files**: `src/modals/invoiceQuickDraft.js`, `tests/vitest/invoiceQuickDraft.spec.js`
- [x] Implement and test

### Task 5: The leaf API's requestedBy is declared
- **spec_ref**: REQ-SOPR-009
- **files**: `tests/Unit/Integration/PaymentRequestLeafProviderTest.php`
- [x] Test

### Task 6: Wire DunningTemplateRegistry into executeStage
- **spec_ref**: REQ-CCD-016
- **files**: `lib/Service/DunningRunService.php`, `tests/Unit/Service/DunningRunServiceTest.php`
- [x] Implement and test
