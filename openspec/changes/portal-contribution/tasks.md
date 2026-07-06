# Tasks: portal-contribution

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Ship the plain PortalContributionProvider class

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-is-a-plain-dependency-free-class-req-spc-001`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the new class WHEN inspected THEN it is namespace `OCA\Shillinq\Portal`, has NO `use` of any portaliq symbol, NO `implements` clause, NO parent class, NO constructor dependencies, and carries the repo-standard EUPL-1.2/SPDX docblock header plus `@spec` tags
  - GIVEN portaliq is absent WHEN the app runs THEN nothing references the class (no DI registration in `lib/AppInfo/Application.php`, no route) — it is inert
- [x] Implement
- [x] Test

### Task 2: Implement the v2+v1 audience contract and both audience manifests

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-customer-subjects-receive-the-verified-read-only-customer-manifest-req-spc-003`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN `getAudiences()` / `getAudience()` are called THEN they return `['customer', 'supplier']` / `'customer'` (REQ-SPC-002)
  - GIVEN a customer subject WHEN `getContribution()` is called THEN the manifest matches REQ-SPC-003 exactly (five collections with the verified schema slugs + scopeFields, `scopeClaim: 'customerId'`, register `shillinq`, listable, `actions: []`, `notifications: []`)
  - GIVEN a supplier subject WHEN `getContribution()` is called THEN the manifest matches REQ-SPC-004 exactly (`purchaseOrders` + `supplierInvoices`, scopeField `supplierId`, `scopeClaim: 'supplierId'`, `actions: []`, `notifications: []`)
  - GIVEN any other audience (absent, empty, unknown) WHEN `getContribution()` is called THEN it returns `null` (REQ-SPC-005)
  - GIVEN the manifest WHEN inspected THEN it contains NO ARInvoice, PaymentRequest, dunning, GoodsReceipt or GoodsReceiptNote collection and NO cross-audience leakage (design.md Exclusions)
- [x] Implement
- [x] Test

### Task 3: Unit-test the full provider contract

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-declares-both-v2-and-v1-audience-methods-req-spc-002`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the test class WHEN it constructs the provider THEN it does so directly (`new`, no mocks, no container) following existing `tests/Unit/` conventions
  - GIVEN the suite WHEN run via `vendor/bin/phpunit -c phpunit-unit.xml` (php 8.3 container) THEN it asserts the plain-class reflection contract, both audience methods, both manifests' exact collection sets (ids, register, schema, scopeField, scopeClaim, listable), empty actions/notifications, the exclusion of ARInvoice/PaymentRequest/dunning/goods-receipt schemas, and fail-closed null — and passes without breaking any existing test
- [x] Implement
- [x] Test

### Task 4: Register the capability spec and pass the quality gates

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md`
- **files**: `openspec/specs/portal-contribution/spec.md`, `openspec/changes/portal-contribution/*`
- **acceptance_criteria**:
  - GIVEN the declared capability WHEN the change is in flight THEN `openspec/specs/portal-contribution/spec.md` exists with status `in-progress` pointing at this change
  - GIVEN the repo gates WHEN run (`php -l`, phpcs, unit suite via the php:8.3-cli container; `openspec validate portal-contribution`) THEN the new files pass with zero new violations and no register JSON was touched
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- No new API endpoints → no Newman collection needed; no UI change → no Playwright needed (the portal renders in portaliq)
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the php 8.3 container)
- No user-facing strings added inside shillinq (manifest labels are portal-side data; English source per i18n policy)
- `openspec validate portal-contribution` passes
