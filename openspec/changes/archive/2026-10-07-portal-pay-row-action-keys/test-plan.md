# Test Plan: portal-pay-row-action-keys

PHPUnit, `vendor/bin/phpunit -c phpunit-unit.xml --filter <Class>`. Tests fail first.

## Test Cases

### TC-1: The pay action names its row key and payable rows
- **spec_ref**: `openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md#requirement-the-pay-action-names-its-row-key-and-its-payable-rows-req-sppi-009`
- **type**: functional
- **steps**: read the customer and parent manifests
- **expected result**: `pay` has `rowField: invoiceId`, `rowWhen` on `lifecycleState` equal to the receiver's `PAYABLE_STATES`; parent `salesInvoices` has `noticeField: invoiceNote`; `paymentRequests` has no row action in either manifest; the `rowWhen` field is a declared ARInvoice property listed in the collection
- **test command**: `--filter PortalContributionProviderTest`

### TC-2: The return address is stored only when https
- **spec_ref**: `...#requirement-an-operator-sets-where-the-checkout-returns-req-sppi-010`
- **type**: security
- **steps**: `updateSettings()` with an https address, empty, http, `javascript:`, a relative path
- **expected result**: the first two are stored and read back; the others throw and store nothing
- **test command**: `--filter SettingsServiceTest`

### TC-3: The controller answers 400 for an unsafe address
- **spec_ref**: `...#requirement-an-operator-sets-where-the-checkout-returns-req-sppi-010`
- **type**: security
- **steps**: `SettingsController::update()` when the service refuses
- **expected result**: 400 with the reason, no `success: true`
- **test command**: `--filter SettingsControllerWriteTest`

## Coverage Summary

REQ-SPPI-009, REQ-SPPI-010 and the MODIFIED REQ-SPPI-006 are covered. The
settings form field has no rendered test (a label, an input and a message).
