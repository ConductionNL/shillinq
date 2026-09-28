# Test Plan: arinvoice-lines-and-portal-amounts

PHPUnit (`vendor/bin/phpunit -c phpunit-unit.xml --filter <Class>`) and vitest
(`npx vitest run tests/vitest/invoiceQuickDraft.spec.js`). Every fix starts with a
test that fails first. No Playwright case: shillinq ships no new screen, and the
portal rendering is portaliq's.

## Test Cases

### TC-1: The quick draft writes invoiceLines
- **spec_ref**: `openspec/changes/arinvoice-lines-and-portal-amounts/specs/shillinq-invoice-quick-draft/spec.md#requirement-the-quick-draft-saves-its-lines-as-invoicelines-req-iqd-006`
- **type**: functional
- **steps**: `buildInvoicePayload()` with one real and one empty line
- **expected result**: one `invoiceLines` entry in the EN 16931 shape, no `lines` key, totals unchanged
- **test command**: `npx vitest run tests/vitest/invoiceQuickDraft.spec.js`

### TC-2: The recurring generator writes invoiceLines
- **spec_ref**: `...specs/recurring-invoicing/spec.md#requirement-req-rin-009-a-generated-invoice-shall-carry-its-lines-as-invoicelines`
- **type**: functional
- **steps**: `buildArInvoicePayload()` for a one-line profile
- **expected result**: `invoiceLines[0]` with the expanded description as `itemName`, no `lines`
- **test command**: `--filter RecurringInvoiceGeneratorTest`

### TC-3: The hybrid PDF prints ARInvoice lines; the BillableInvoice PDF is unchanged
- **spec_ref**: `...specs/bookkeeping-einvoicing-ubl-peppol/spec.md#requirement-req-einv-009-the-hybrid-pdf-shall-print-the-arinvoices-own-lines`
- **type**: regression
- **steps**: `generateHybridPdf()` with an `invoiceLines` entry; the existing BillableInvoice cases
- **expected result**: the hybrid page prints each line (number, description, quantity x price = amount EUR, rate); the HTML row reads the invoiceLines keys; existing BillableInvoice cases green
- **test command**: `--filter InvoicePdfGeneratorTest`

### TC-4: Every manifest field is declared
- **spec_ref**: `...specs/portal-payment-initiation/spec.md#requirement-the-customer-manifest-names-the-fields-arinvoice-declares-req-sppi-007`
- **type**: regression
- **steps**: merge the register; walk every collection of the customer and parent manifests
- **expected result**: every listed field is a declared property; salesInvoices lists grossAmount, vatAmount, invoiceLines, lifecycleState
- **test command**: `--filter PortalContributionProviderTest`

### TC-5: The stamping rule
- **spec_ref**: `...specs/portal-payment-initiation/spec.md#requirement-a-request-without-an-invoice-is-listed-and-paid-in-the-portal-req-sppi-008`
- **type**: functional
- **steps**: `PaymentRequestPortalScope::stamp()` on a leges request, an invoice-backed request, a name-and-email debtor, an already-stamped request; the leaf API and leges intake save paths
- **expected result**: only the leges request with a customer debtor gets `customerId`; both writers save it
- **test command**: `--filter 'PaymentRequestPortalScopeTest|LegesIntakeStepServiceTest|PaymentRequestLeafProviderTest'`

### TC-6: The backfill
- **spec_ref**: `...#requirement-a-request-without-an-invoice-is-listed-and-paid-in-the-portal-req-sppi-008`
- **type**: functional
- **steps**: run the repair step twice over a mixed set
- **expected result**: the request-only row is stamped once; the invoice-backed row untouched; the second run saves nothing
- **test command**: `--filter BackfillPaymentRequestCustomerTest`

### TC-7: The portal lists requests without an invoice
- **spec_ref**: `...#requirement-a-request-without-an-invoice-is-listed-and-paid-in-the-portal-req-sppi-008`
- **type**: functional
- **steps**: the customer manifest
- **expected result**: `requestPayments` over PaymentRequest, scopeField `customerId`, claim `customerMasterId`, rowAction `pay`; `customerId` is a declared uuid `$ref: CustomerMaster`
- **test command**: `--filter PortalContributionProviderTest`

### TC-8: A citizen pays a request without an invoice; nothing else is payable
- **spec_ref**: `...#requirement-a-request-without-an-invoice-is-listed-and-paid-in-the-portal-req-sppi-008`
- **type**: security
- **steps**: `initiateForRequest()` for the own pending request; a foreign one, an invoice-backed one, a captured one, a zero amount; the controller with `paymentRequestId`
- **expected result**: a checkout for the request amount and the intent id saved; forbidden for the others with no provider call; downstream error for the zero amount; the controller routes `paymentRequestId` to the new path and `invoiceId` wins when both are sent
- **test command**: `--filter 'PortalPaymentSessionServiceTest|PortalPaymentInitiationControllerTest'`

## Coverage Summary

REQ-IQD-006, REQ-RIN-009, REQ-EINV-009, REQ-SPPI-007 and REQ-SPPI-008 are covered
by TC-1 to TC-8. Not covered: portaliq's rendering of the new collection, and a
live provider round trip.
