# portal-contribution Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- `openspec/changes/portal-contribution/`

## Purpose

Shillinq contributes customer and supplier sections to portaliq, the shared
external portal for people without Nextcloud accounts (hydra ADR-046 +
2026-07-06 amendment, contribution contract v2). The contribution is one
plain, dependency-free provider class declaring read-only, UUID+claim-scoped
OpenRegister collections for two audiences. Wave 1 of the ADR-046 fleet
rollout (tracking: Conduction/shillinq#365).

## ADDED Requirements

### Requirement: Provider is a plain, dependency-free class (REQ-SPC-001)

The app MUST ship `OCA\Shillinq\Portal\PortalContributionProvider` as a plain
PHP class: no imports from portaliq, no `implements` clause, no `info.xml`
dependency on portaliq, and no constructor dependencies. Portaliq discovers it
by convention FQCN and duck-types it via `method_exists` (never `instanceof`),
so without portaliq installed the class MUST be inert and MUST NOT change any
app behaviour (ADR-046 amendment A1). It MUST NOT be registered in
`lib/AppInfo/Application.php`.

#### Scenario: Provider constructs standalone

- GIVEN a PHP runtime where portaliq is not installed and no portaliq class is autoloadable
- WHEN `new PortalContributionProvider()` is called
- THEN the class instantiates without error
- AND it declares no `implements` clause, no parent class, and no constructor
- @e2e exclude backend-only contract class with no shillinq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider declares both v2 and v1 audience methods (REQ-SPC-002)

The provider MUST implement `getAudiences(): array` returning
`['customer', 'supplier']` (contract v2, preferred by the registry) AND
`getAudience(): string` returning `'customer'` (v1 fallback — v1 registries
support a single audience and the customer surface is primary), so the
provider works against both registry generations.

#### Scenario: Audience methods agree

- GIVEN a constructed provider
- WHEN `getAudiences()` and `getAudience()` are called
- THEN `getAudiences()` returns exactly `['customer', 'supplier']`
- AND `getAudience()` returns `'customer'`, which is contained in `getAudiences()`
- @e2e exclude backend-only contract methods with no shillinq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Customer subjects receive the verified read-only customer manifest (REQ-SPC-003)

`getContribution(array $subject): ?array` MUST branch on
`$subject['audience']`. For `'customer'` it MUST return a declarative manifest
labelled `'Shillinq'` with exactly these read-only collections, all register
`shillinq`, all `listable`, all carrying `scopeClaim: 'customerId'` (bare
name → `claims.shillinq.customerId`, the UUID of the customer domain record):

- `invoices` — schema `Invoice`, scopeField `customerReference`;
- `projectInvoices` — schema `BillableInvoice`, scopeField `customerId`;
- `quotes` — schema `Quote`, scopeField `customerReference`;
- `salesOrders` — schema `SalesOrder`, scopeField `customerReference`;
- `contracts` — schema `Contract`, scopeField `customerId`;

plus `actions: []` and `notifications: []`. The manifest MUST be pure data —
no callbacks, no I/O — and MUST NOT include any supplier-audience collection,
`ARInvoice`, `PaymentRequest`, or any dunning schema (verified exclusions in
design.md).

#### Scenario: Customer subject receives the customer manifest

- GIVEN a subject array with `audience` `'customer'`, a `subjectRef` UUID, an organisation and a trust level
- WHEN `getContribution($subject)` is called
- THEN it returns a manifest labelled `'Shillinq'` whose collection ids are exactly `invoices`, `projectInvoices`, `quotes`, `salesOrders`, `contracts` with the schema/scopeField pairs above
- AND every collection carries `scopeClaim` `'customerId'` and register `'shillinq'`
- AND `actions` and `notifications` are empty arrays
- @e2e exclude manifest is consumed and rendered by portaliq, not by any shillinq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Supplier subjects receive the verified read-only supplier manifest (REQ-SPC-004)

For `$subject['audience']` `'supplier'`, `getContribution()` MUST return a
manifest labelled `'Shillinq'` with exactly these read-only collections, all
register `shillinq`, all `listable`, all carrying `scopeClaim: 'supplierId'`
(bare name → `claims.shillinq.supplierId`, the UUID of the `Payee` vendor
record):

- `purchaseOrders` — schema `PurchaseOrder`, scopeField `supplierId`;
- `supplierInvoices` — schema `SupplierInvoice`, scopeField `supplierId`;

plus `actions: []` and `notifications: []`. It MUST NOT include
`GoodsReceipt` (no supplier reference exists) or `GoodsReceiptNote` (supplier
linkage only via the `poIds` array — beyond the one-hop scalar `via` join),
nor any customer-audience collection.

#### Scenario: Supplier subject receives the supplier manifest

- GIVEN a subject array with `audience` `'supplier'`, a `subjectRef` UUID, an organisation and a trust level
- WHEN `getContribution($subject)` is called
- THEN it returns a manifest labelled `'Shillinq'` whose collection ids are exactly `purchaseOrders` and `supplierInvoices`, both schema-scoped by `supplierId` with `scopeClaim` `'supplierId'`
- AND `actions` and `notifications` are empty arrays
- @e2e exclude manifest is consumed and rendered by portaliq, not by any shillinq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Unknown audiences are fail-closed (REQ-SPC-005)

`getContribution()` MUST return `null` for any subject whose `audience` is
not exactly `'customer'` or `'supplier'` — including absent, empty, or
unknown values. The provider MUST NOT rely on the registry's own audience
filtering.

#### Scenario: Non-matching subject receives null

- GIVEN subject arrays with `audience` `'client'`, `''`, an unset audience, and an empty subject array
- WHEN `getContribution($subject)` is called for each
- THEN every call returns `null`
- @e2e exclude backend-only filter logic with no shillinq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

## Non-Functional Requirements

- **Performance:** `getContribution()` is pure data assembly — no I/O, no
  container access; sub-millisecond by construction.
- **Accessibility:** N/A in shillinq — the rendering surface is portaliq's
  SPA (ADR-046), which owns WCAG compliance.
- **Internationalization:** manifest labels ship in English source per fleet
  i18n policy; portaliq owns portal-side translation of contributed labels.
- **Trust:** collections ship at default (low) trust; the documented Wave-2
  posture raises financial collections to `minTrust: 'substantial'` once the
  eHerkenning broker lands (design.md).

## Acceptance Criteria

- Unit suite proves: the plain-class contract, both audience methods, both
  audience manifests' exact shape (ids, schemas, scopeFields, scopeClaims,
  register, listable, empty actions/notifications), and fail-closed null for
  non-matching audiences.
- `php -l`, phpcs, and the unit suite pass on the new files (php 8.3
  container); `openspec validate portal-contribution` passes.
- No register JSON, route, controller, service, or frontend file changed.

## Notes

- Scoping map, claim-names contract, administrationId tenancy note, and all
  verified exclusions (ARInvoice, PaymentRequest, dunning, goods receipts,
  union-shape rows) live in `openspec/changes/portal-contribution/design.md`.
- Related: hydra ADR-046 (+ amendment), ADR-022 (apps consume OR
  abstractions), ADR-005 (server-derived scope, fail-closed).
