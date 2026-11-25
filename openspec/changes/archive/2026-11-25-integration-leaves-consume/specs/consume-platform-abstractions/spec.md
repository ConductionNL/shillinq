# Spec: consume-platform-abstractions (delta)

## ADDED Requirements

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
