# Tasks: portal-pay-row-action-keys

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

Stacked on `arinvoice-lines-and-portal-amounts` (#1728). No migration.

## Implementation Tasks

### Task 1: The pay action keys, the notice field, no row action on paymentRequests
- **spec_ref**: `openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md#requirement-the-pay-action-names-its-row-key-and-its-payable-rows-req-sppi-009`
- **files**: `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the manifests WHEN read THEN pay carries rowField invoiceId and rowWhen on lifecycleState with the receiver's payable states, parent salesInvoices carries noticeField invoiceNote, paymentRequests names no row action
- [x] Implement
- [x] Test

### Task 2: The return address setting
- **spec_ref**: `openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md#requirement-an-operator-sets-where-the-checkout-returns-req-sppi-010`
- **files**: `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `src/views/settings/Settings.vue`, `l10n/*`, `docs/api/portal-payments.md`, `tests/Unit/Service/SettingsServiceTest.php`, `tests/Unit/Controller/SettingsControllerWriteTest.php`
- **acceptance_criteria**:
  - GIVEN an https address or empty WHEN saved THEN it is stored; GIVEN anything else WHEN saved THEN 400 and nothing stored
- [ ] Implement
- [ ] Test

### Task 3: Docs
- [ ] `docs/api/portal-payments.md` names the row keys and the setting

### Task 4: Verify
- [ ] Diff-scoped checks, then `composer check:strict`, `npm run lint`, `npm run format`, `npm run test:l10n`, hydra gates, once
