---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — Wave-1 ADR-046 provider class (customer + supplier audiences) + unit tests (kind: code)
- [customer-invoice-portal-wave2](../../changes/archive/2026-07-14-customer-invoice-portal-wave2/) _(done)_ — Wave-2: lifts the customer-side ARInvoice/PaymentRequest exclusion (REQ-SPC-020…022); debtors see + pay their own AR invoices, UUID-scoped by the CustomerMaster object UUID (kind: code)

## Purpose

Shillinq contributes customer and supplier sections to portaliq, the shared
external portal for people without Nextcloud accounts (hydra ADR-046 +
2026-07-06 amendment, contribution contract v2). The contribution is one
plain, dependency-free provider class
(`OCA\Shillinq\Portal\PortalContributionProvider`, duck-typed by FQCN — inert
without portaliq) declaring read-only OpenRegister collections scoped by
verified UUID domain references and matched against per-app claims
(`claims.shillinq.customerId` / `claims.shillinq.supplierId`). Wave 1 of the
ADR-046 fleet rollout (tracking: Conduction/shillinq#365).
## Requirements

Detailed requirements (REQ-SPC-001 … REQ-SPC-005) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
verified scoping map, claim-names contract, administrationId tenancy note,
and all exclusions (ARInvoice, PaymentRequest, dunning, goods receipts) live
in the change's
[`design.md`](../../changes/portal-contribution/design.md).

### Requirement: Shillinq contributes to portaliq via one plain duck-typed provider (REQ-SPC-000)

Shillinq MUST expose its entire portal contribution through the single plain
class `OCA\Shillinq\Portal\PortalContributionProvider` — duck-typed by
portaliq, dependency-free, inert without portaliq — declaring read-only,
UUID+claim-scoped collections for the `customer` and `supplier` audiences and
nothing else (no portal UI, routes, or endpoints inside shillinq). Normative
detail: REQ-SPC-001 … REQ-SPC-005 in the active change's delta spec.

#### Scenario: Provider is the only portal surface

- GIVEN the shillinq codebase at this capability's HEAD
- WHEN the portal contribution is inspected
- THEN it consists solely of `lib/Portal/PortalContributionProvider.php` (plus its unit tests) with no portaliq import, no info.xml dependency, and no shillinq-side portal route or UI
- @e2e exclude backend-only contract class; the external portal surface is rendered and e2e-tested in portaliq, not in shillinq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider declares the accountant audience (REQ-SPC-010)

The provider MUST declare `accountant` as a third audience, so
`getAudiences()` returns `['customer', 'supplier', 'accountant']`. The v1
`getAudience()` fallback MUST remain `'customer'` (v1 registries serve a single
audience; the primary customer surface is unchanged). Adding the accountant
audience MUST NOT alter the customer or supplier manifests, and the class MUST
stay plain and dependency-free (no imports from portaliq, no `implements`, inert
without portaliq — REQ-SPC-000 is preserved).

#### Scenario: getAudiences includes accountant

- **WHEN** `getAudiences()` is called on a constructed provider
- **THEN** it returns exactly `['customer', 'supplier', 'accountant']`
- **AND** `getAudience()` still returns `'customer'`, which is contained in that list
- @e2e exclude backend-only contract method with no shillinq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Adding the audience does not change existing manifests

- **WHEN** `getContribution()` is called for the `customer` audience and for the `supplier` audience
- **THEN** both return the same manifests as before this change (same collections, scopeFields, and scopeClaims)
- @e2e exclude backend-only contract behaviour — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Accountant subjects receive the read-only administration-review manifest (REQ-SPC-011)

`getContribution(array $subject)` MUST, for `$subject['audience'] === 'accountant'`,
return a declarative manifest labelled `'Shillinq'` whose collections are the
financial-review surfaces an external bookkeeper needs, all register `shillinq`,
all `listable`, all scoped by `scopeField: 'administrationId'` (the row's
tenancy key) against `scopeClaim: 'accountantAdministrationId'` (the UUID of an
administration the accountant is authorised for). The manifest MUST be pure data
(no callbacks, no I/O). Every collection's schema MUST actually declare an
`administrationId` property, so the scope resolves to a real field. The manifest
MUST include at minimum:

- `salesInvoices` — schema `ARInvoice`;
- `purchaseInvoices` — schema `SupplierInvoice` (or `APTransaction`);
- `journalEntries` — schema `JournalEntry`;
- `generalLedger` — schema `GLTransaction`;
- `trialBalance` — schema `TrialBalance`;
- `vatReturns` — schema `VatReturn`;
- `financialStatements` — schema `FinancialStatement`.

It MUST NOT include any collection that lacks an `administrationId` scope field
(no cross-administration leakage), and MUST NOT include a customer- or supplier-
scoped collection whose only scope is `customerReference` / `supplierId`.

#### Scenario: Accountant subject receives the administration-scoped manifest

- **WHEN** `getContribution($subject)` is called with `$subject['audience']` `'accountant'`, a `subjectRef` UUID, an organisation, and a trust level
- **THEN** it returns a manifest labelled `'Shillinq'` whose collections include `salesInvoices` (`ARInvoice`), `purchaseInvoices` (`SupplierInvoice`), `journalEntries` (`JournalEntry`), `generalLedger` (`GLTransaction`), `trialBalance` (`TrialBalance`), `vatReturns` (`VatReturn`), and `financialStatements` (`FinancialStatement`)
- **AND** every collection carries `register` `'shillinq'`, `scopeField` `'administrationId'`, and `scopeClaim` `'accountantAdministrationId'`
- @e2e exclude manifest is consumed and rendered by portaliq, not by any shillinq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: An accountant only sees administrations they are authorised for

- **WHEN** portaliq resolves the accountant manifest for a subject whose `claims.shillinq.accountantAdministrationId` lists administration `A` but not administration `B`
- **THEN** only rows whose `administrationId` equals `A` are returned, and no row belonging to administration `B` is visible
- @e2e exclude scope enforcement is portaliq/OpenRegister claim-matching, not a shillinq UI surface — covered by PHPUnit at the manifest-declaration level (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: The accountant surface is read-only this wave (REQ-SPC-012)

The accountant manifest MUST declare `actions: []` and `notifications: []` — no
write, adjustment, correction-request, or posting capability is contributed in
this ADR-046 Wave. Any accountant collaboration that requires write access
(posting adjustments, requesting corrections) is out of scope and MUST NOT be
introduced through the portaliq read manifest. An unknown audience (anything
other than `customer`, `supplier`, `accountant`) MUST still fail closed
(`getContribution()` returns `null`).

#### Scenario: Accountant manifest exposes no write actions

- **WHEN** the accountant manifest is returned
- **THEN** its `actions` array is empty and its `notifications` array is empty
- @e2e exclude backend-only manifest data — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Unknown audience still fails closed

- **WHEN** `getContribution()` is called with an audience that is not `customer`, `supplier`, or `accountant`
- **THEN** it returns `null` (no manifest, no leakage)
- @e2e exclude fail-closed backend contract — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Customer subjects can see their own AR invoices (REQ-SPC-020)

For `$subject['audience'] === 'customer'`, `getContribution()` MUST append to
the existing customer manifest a read-only `salesInvoices` collection: register
`shillinq`, schema `ARInvoice`, `scopeField: 'customerId'` (the CustomerMaster
**object UUID** the base schema declares via `format: uuid` / `$ref:
CustomerMaster` / `inversedBy: invoices`), `scopeClaim: 'customerMasterId'`
(bare name → `claims.shillinq.customerMasterId`), `listable: true`. It MUST
carry a `fields` whitelist that projects the row to the customer-safe subset —
including `invoiceNumber`, `lines`, the artefact URIs (`sourceDocumentUri`,
`ublXml`) and the read-only `dunning` summary group — and MUST NOT include the
internal accounting fields `glTransactionId`, `matchedBankLineId`,
`settlementReference`, the `writeOff` group, or `administrationId`. The manifest
MUST stay pure data and the five Wave-1 customer collections MUST be unchanged
and MUST remain first. `actions` and `notifications` MUST stay `[]`.

#### Scenario: The customer manifest surfaces AR invoices, UUID-scoped

- GIVEN a server-derived customer subject
- WHEN `getContribution()` is called
- THEN the manifest's collections end with `salesInvoices` (schema `ARInvoice`, register `shillinq`, scopeField `customerId`, scopeClaim `customerMasterId`, listable)
- AND its `fields` whitelist includes `invoiceNumber`, `lines`, `sourceDocumentUri`, `ublXml`, and `dunning` and excludes `glTransactionId`, `matchedBankLineId`, `writeOff`, `administrationId`
- AND the five Wave-1 collections are unchanged and remain first, with `actions: []` and `notifications: []`
- @e2e exclude backend-only contract data; the portal list/detail is rendered and e2e-tested in portaliq, not in shillinq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Customer subjects can pay their own invoices, and another debtor's invoice is unreachable (REQ-SPC-021)

The customer manifest MUST append a read-only `paymentRequests` collection:
register `shillinq`, schema `PaymentRequest`, `scopeField: 'invoiceReference'`,
`scopeClaim: 'customerMasterId'`, `listable: true`, and a one-hop reverse `via`
join `{register: 'shillinq', schema: 'ARInvoice', scopeField: 'customerId',
targetField: 'id', match: 'scopeField'}`, so a payment request is visible only
when its linked `ARInvoice` belongs to the subject's CustomerMaster. Its
`fields` MUST include the computed `paymentLink` (pay-now surface). No customer
collection may scope by `administrationId` or by a client-supplied id, and every
customer collection MUST carry a (server-issued) `scopeClaim` — so another
debtor's invoice or payment request is unreachable (IDOR). Scope enforcement is
portaliq's per-row `verifyScope` + reverse-`via` membership; this app only
declares the manifest that feeds it.

#### Scenario: PaymentRequest is reachable only through the invoice's customer scope

- GIVEN a server-derived customer subject
- WHEN `getContribution()` is called
- THEN the manifest contains `paymentRequests` (schema `PaymentRequest`, scopeField `invoiceReference`, scopeClaim `customerMasterId`) whose `via` is exactly `{register: shillinq, schema: ARInvoice, scopeField: customerId, targetField: id, match: scopeField}`
- AND its `fields` include `paymentLink`
- @e2e exclude backend-only contract data; the reverse-`via` scope is enforced and e2e-tested in portaliq's PortalObjectReader, not in a shillinq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: No customer collection is scoped by administration or a client id

- WHEN the customer manifest is inspected
- THEN no collection declares `scopeField: 'administrationId'`
- AND every collection declares a non-empty `scopeClaim`
- AND `salesInvoices` scopes by the CustomerMaster object UUID (`customerId` / `customerMasterId`), never the per-administration customer code
- @e2e exclude fail-closed backend contract; runtime IDOR enforcement is portaliq-side — covered by PHPUnit at the manifest-declaration level (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: The Wave-2 AR surface is read-only and dunning is summary-only (REQ-SPC-022)

The Wave-2 additions MUST NOT introduce any write, create, or endpoint action:
`actions` and `notifications` stay `[]`. Dunning MUST be surfaced only as the
read-only `ARInvoice.dunning` summary group projected on `salesInvoices`; the
`DunningRun` / `DunningRecord` / `DunningNotice` schemas MUST NOT be added as
collections (recipient PII, rendered letters, AP-side data). An unknown audience
MUST still fail closed (`getContribution()` returns `null`).

#### Scenario: The AR surface adds no write capability and no dunning-run collection

- WHEN the customer manifest is returned
- THEN `actions` is `[]` and `notifications` is `[]`
- AND no collection has schema `DunningRun`, `DunningRecord`, or `DunningNotice`
- AND `salesInvoices.fields` includes the `dunning` summary group
- @e2e exclude backend-only manifest data — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

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

