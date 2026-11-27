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

### Requirement: REQ-CPA-005 — Evidence-bearing record types SHALL expose the files leaf on their detail pages

Every record type whose schema stores a document/attachment URI SHALL render
OpenRegister's `files` integration widget
(`{"type": "integration", "integrationId": "files"}`) on its detail page, the
same pattern as the existing `invoice-files` widget on `ARInvoiceDetail`.
Concretely this change MUST add the widget to:

- `ReceiptDetail` — the receipt image (`Receipt.photoUri`),
- `ExpenseClaimDetail` — the claim's receipt evidence
  (`ExpenseClaimEntry.receiptIds` → `Receipt.photoUri`),
- `BankConnectionDetail` — imported statement files for the connection
  (`BankStatement.bankConnectionId`, `BankStatement.statementAttachmentUri`),
- `BtwAangifteDetail` — the filed return attachment (`VatReturn.attachmentUri`).

No bespoke file-listing PHP service MUST be written; the widget consumes the
platform leaf.

#### Scenario: a receipt's image is reachable from its detail page

- **GIVEN** a `Receipt` object with `photoUri` set to an uploaded receipt image
- **WHEN** a user opens `ReceiptDetail` for that object
- **THEN** the files integration widget MUST list the receipt image
- **AND** opening it MUST show the stored file, not a broken link

#### Scenario: an expense claim shows all of its receipt evidence

- **GIVEN** an `ExpenseClaimEntry` whose `receiptIds` reference two `Receipt` objects with images
- **WHEN** a user opens `ExpenseClaimDetail`
- **THEN** the files surface MUST expose both receipt images from the claim page

#### Scenario: a bank connection's statements are browsable as files

- **GIVEN** a `BankConnection` with two imported `BankStatement` objects carrying `statementAttachmentUri`
- **WHEN** a user opens `BankConnectionDetail`
- **THEN** the statement files MUST be reachable from the page's files surface

#### Scenario: a filed VAT return exposes its submitted attachment

- **GIVEN** a `VatReturn` in state `submitted` with `attachmentUri` set
- **WHEN** a user opens `BtwAangifteDetail`
- **THEN** the filed attachment MUST be listed by the files widget

### Requirement: REQ-CPA-006 — VAT returns and AR invoices SHALL expose their deadline events through the calendar leaf, without a second event publisher

The `VatReturn` and `ARInvoice` schemas SHALL declare `calendar` in
`configuration.linkedTypes` (via a last-sorting ADR-037 register fragment), so
`BtwAangifteDetail` and `ARInvoiceDetail` render the calendar leaf showing the
object's linked deadline events (filing deadline; `dueDate` payment deadline).
`ComplianceDeadlineCalendarService` MUST remain the only writer of deadline
VEVENTs (REQ-CDC-002/-004 of `compliance-deadline-calendar`): the leaf is a
per-object view/link surface. If OpenRegister's calendar leaf at HEAD cannot
list linked events for an object, this leg MUST be refused and recorded per
REQ-CPA-003 rather than satisfied with a bespoke event query.

#### Scenario: a VAT return's filing deadline is visible on its detail page

- **GIVEN** a `VatReturn` for Q2 whose filing deadline `ComplianceDeadlineCalendarService` has published as a VEVENT
- **WHEN** a user opens `BtwAangifteDetail`
- **THEN** the calendar leaf MUST show the linked deadline event
- **AND** no second VEVENT MUST have been created by rendering the page

#### Scenario: an AR invoice's due date surfaces as a calendar entry

- **GIVEN** an `ARInvoice` with a future `dueDate` and the user's AR due-date category opt-in (REQ-CDC-004) enabled
- **WHEN** the user opens `ARInvoiceDetail`
- **THEN** the calendar leaf MUST surface the due-date event for that invoice

### Requirement: REQ-CPA-007 — Debtor and creditor masters SHALL link to the Nextcloud address book through the contacts leaf

`CustomerMaster` (debtors — `legalName`, `email`, `kvkNumber`, `vatId`) and
`Payee` (creditors — `name`, `email`, `phone`, `address`, `contactRef`) SHALL
declare `contacts` in `configuration.linkedTypes`, so `CustomerDetail` and
`PayeeDetail` render OpenRegister's contacts leaf
(`ContactsController` / `ContactMatchingService`). The leaf links or matches an
address-book contact per object; it MUST NOT bulk-export master records into
the address book.

#### Scenario: a debtor is linked to an address-book contact

- **GIVEN** a `CustomerMaster` with `email` matching an existing address-book contact
- **WHEN** a user opens `CustomerDetail`
- **THEN** the contacts leaf MUST offer/show the matched contact
- **AND** linking it MUST persist so the link survives a reload

#### Scenario: a creditor with no matching contact can create one

- **GIVEN** a `Payee` whose `email` matches no address-book contact
- **WHEN** the user uses the contacts leaf on `PayeeDetail`
- **THEN** it MUST offer creating/linking a contact rather than failing
- **AND** the register's `Payee` object itself MUST NOT be modified beyond the link

### Requirement: REQ-CPA-008 — Dossier-bearing detail pages with curated sidebar tabs SHALL include the talk leaf explicitly

Detail pages that carry a discussable dossier and declare a curated
`sidebarProps.tabs` list SHALL include `talk` in that curated list —
specifically `ARInvoiceDetail` (dispute/dunning discussion),
`ExpenseClaimDetail` (approval discussion), and `ContractDetail` (contract
negotiation) where those pages curate their tabs. Pages without a curated list
already receive the `talk` leaf in registry mode (REQ-CPA-001) and MUST NOT be
touched. The curated-set rule of REQ-CPA-001 holds: the leaf is added by
editing the curated list, never by bypassing curation.

#### Scenario: an expense-claim approval discussion happens on the claim

- **GIVEN** `ExpenseClaimDetail` with a curated tab list that includes `talk`
- **WHEN** an approver opens the claim and starts a conversation in the talk leaf
- **THEN** a Talk conversation bound to that claim object MUST be created
- **AND** reopening the claim MUST show the same conversation, not a new one

#### Scenario: a page without curated tabs is not double-modified

- **GIVEN** a shillinq detail page with no `sidebarProps.tabs` override
- **WHEN** this change is applied
- **THEN** that page's manifest entry MUST be unchanged — its `talk` tab keeps coming from registry mode

## Notes

Follow-ups filed rather than forced through this change:

- shillinq#443 (Codeberg, pre-migration, not migrated to GitHub) — `InventoryStock.status`
  never mirrors `Product.status`; quarantine (`InventoryLot.lotStatus`) has no enforcement
  path at dispatch. Needs a real check in `SalesDispatchStockIssueService`, not a schema edit.
- openconnector#159 (Codeberg, pre-migration, not migrated to GitHub) —
  `Consumer.authorizationType: apiKey` is not enforced; `Consumer` has no declarative
  import handler; the gateway has no self-generated OAS.
