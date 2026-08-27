# consume-platform-abstractions Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- consume-platform-abstractions (2026-07-14, archived)

## Purpose

Ensures shillinq surfaces platform-provided capabilities (OpenRegister leaf
integrations, declarative filter operators, the openconnector endpoint gateway)
instead of reimplementing them, per ADR-022. This spec also records — as a first-
class outcome, not a gap — the two items whose "zero PHP" premise did not survive
verification against HEAD and were refused rather than forced through. See
`openspec/changes/archive/2026-07-14-consume-platform-abstractions/design.md`
for the full evidence trail and design rationale.

## Requirements

### Requirement: REQ-CPA-001 — shillinq's JS bundle SHALL populate the shared integration registry

`src/main.js` MUST call `installIntegrationRegistry()`, `registerBuiltinIntegrations()`,
and `registerLeafIntegrations()` from `@conduction/nextcloud-vue` at bootstrap, so the
`window.OCA.OpenRegister.integrations` singleton is populated from shillinq's own
bundle (OpenRegister's own bootstrap only runs on `/apps/openregister/*` routes and
never reaches `/apps/shillinq/*`).

#### Scenario: Flow and Talk leaves appear on an object detail page

- **GIVEN** the `workflowengine` and `spreed` Nextcloud apps are installed
- **WHEN** a user opens a shillinq object detail page whose manifest config does not declare a custom `sidebarProps.tabs` override
- **THEN** the sidebar MUST include a `flow` tab and a `talk` tab (registry mode, `CnObjectSidebar`'s default `useRegistry: true`)

#### Scenario: A page with a curated custom tab set is not silently changed

- **GIVEN** a detail page's manifest config declares `sidebarProps.tabs` (e.g. `PurchaseOrderDetail`'s `["audit"]`)
- **WHEN** the page renders
- **THEN** only the declared custom tabs MUST appear — registry-mode leaves (including `flow`/`talk`) MUST NOT be silently added to a page that has opted into a curated tab list

### Requirement: REQ-CPA-002 — Missing-document worklists SHALL use OpenRegister's declarative `IS NULL` filter, not a bespoke service

shillinq MUST surface "documents lacking a source-document reference" via a
`type:"index"` manifest page with `config.filter: {"sourceDocumentUri": null}`
(OpenRegister's `_isnull` REST operator). No PHP worklist service MUST be written.

#### Scenario: Supplier invoices missing a source document surface in a dedicated worklist

- **GIVEN** a `SupplierInvoice` object with `sourceDocumentUri` unset
- **WHEN** the `SupplierInvoicesMissingDocument` index page loads
- **THEN** that object MUST appear in the list, sorted with the oldest `invoiceDate` first
- **AND** a `SupplierInvoice` object WITH a `sourceDocumentUri` set MUST NOT appear

#### Scenario: Receipts missing a source document surface in a dedicated worklist

- **GIVEN** a `Receipt` object with `sourceDocumentUri` unset
- **WHEN** the `ReceiptsMissingDocument` index page loads
- **THEN** that object MUST appear in the list, sorted with the oldest `receiptDate` first

### Requirement: REQ-CPA-003 — A platform-capability claim MUST be verified against HEAD before code is written, and refused rather than forced when false

A platform-capability claim MUST be verified against the actual HEAD state
(schema content, consuming code paths, and the platform engine's actually-read
keys) before implementation — whenever an audit/gap-sweep asserts a "zero PHP"
or declarative fix already exists. When verification shows the claim false — the described
mechanism does not exist, targets the wrong schema, or is not wired to any
enforcement point — the item MUST be refused rather than forced through with a
declarative-looking edit that has no runtime effect, and the refusal MUST be
recorded with the concrete evidence (file:line citations) that falsified the claim.

#### Scenario: Inventory quarantine status claim is refused after verification

- **GIVEN** an audit claims `InventoryStock.status` mirrors `Product.status` and that a declarative `x-openregister-lifecycle` edit alone would make quarantined stock non-sellable
- **WHEN** the claim is checked against HEAD
- **THEN** the check MUST find that no code mirrors `Product.status` onto `InventoryStock.status`, that the quarantine concept lives on the sibling `InventoryLot.lotStatus` schema instead, and that `SalesDispatchStockIssueService` never reads either status field at dispatch time
- **AND** the item MUST NOT be implemented as a schema-only edit; it MUST be filed as a follow-up issue describing the real (PHP-inclusive) fix

### Requirement: REQ-CPA-004 — A cross-repo config dependency MUST NOT be authored inside the wrong repository

A leaf app change MUST NOT author, inside its own repository, config objects
(e.g. an openconnector `Endpoint` + `Consumer`) that are only declaratively
authorable from within the gateway app's own repository/folder structure — nor a
facsimile of that config — when exposing the leaf's data through a platform
gateway requires them. It MUST document the real, verified path/auth
shape for the sanctioned gateway and file the cross-repo gap as a follow-up issue
in the owning repository.

#### Scenario: Public REST API exposure config is refused as a boundary crossing

- **GIVEN** exposing shillinq's registers via openconnector's endpoint gateway requires an `Endpoint` (only importable from openconnector's own `configurations/` folder) and a `Consumer` (no declarative import path at all, and its `apiKey` authorizationType is not wired to any enforcement code)
- **WHEN** the change is scoped
- **THEN** shillinq MUST NOT commit an Endpoint/Consumer config fragment inside its own repository
- **AND** shillinq MUST document the real path/auth shape (`docs/api/third-party-rest-access.md`) so a third-party integrator is not misled by OpenRegister's own self-generated OAS
- **AND** the enforcement/import gaps MUST be filed as a follow-up issue in the openconnector repository

## Notes

Follow-ups filed rather than forced through this change:

- shillinq#443 (Codeberg, pre-migration, not migrated to GitHub) — `InventoryStock.status`
  never mirrors `Product.status`; quarantine (`InventoryLot.lotStatus`) has no enforcement
  path at dispatch. Needs a real check in `SalesDispatchStockIssueService`, not a schema edit.
- openconnector#159 (Codeberg, pre-migration, not migrated to GitHub) —
  `Consumer.authorizationType: apiKey` is not enforced; `Consumer` has no declarative
  import handler; the gateway has no self-generated OAS.
