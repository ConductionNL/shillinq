# Change: integration-leaves-consume

## Why

OpenRegister ships app-agnostic integration leaves (files, mail, contacts,
calendar, talk, deck, notes, todos — `LinkedEntityService::legacyLinkedTypeIds()`
— plus registry-provided leaves such as `decidesk-decisions` via
`IntegrationRegistry`). Shillinq consumes almost none of them. The current,
repo-verified integration state is:

- **mail**: the `Invoice` schema declares `configuration.linkedTypes: ["mail"]`
  plus a `mailObjectTemplate` (create-invoice-from-email) —
  `lib/Settings/register.d/bookkeeping-quote-order-invoice.json`.
- **files**: exactly four manifest integration widgets —
  `invoice-files` on `ARInvoiceDetail` (manifest.json + the peppol overlay),
  `tender-files` on `TenderNedAanbestedingDetail` and `verplichting-files` on
  `VerplichtingDetail` (`src/manifest.d/20-tenderned-integratie.json`).
- **decidesk-decisions**: `contract-decisions` on `ContractDetail` +
  `Contract.configuration.linkedTypes: ["decidesk-decisions"]`
  (`contract-lifecycle-management.json`).

Meanwhile the app's own manifest `_note` prose already *promises* file surfaces
that don't exist as widgets: `ReceiptDetail` ("The receipt image lives under
Files"), `ExpenseClaimDetail` ("source receipt images live under Files"),
`BtwAangifteDetail` ("The filed attachment lives under Files"), and
`BankConnectionDetail` lists imported statements whose `Receipt.photoUri` /
`VatReturn.attachmentUri` / `BankStatement.statementAttachmentUri` properties
hold the actual URIs. Debtors (`CustomerMaster`: `legalName`, `email`,
`kvkNumber`, `vatId`) and creditors (`Payee`: `name`, `email`, `phone`,
`address`, `contactRef`) carry full contact data but never reach the Nextcloud
address book. A finance dossier (an invoice in dispute, an expense claim under
approval) has no discussion surface on pages that curate their sidebar tabs.

This change closes those gaps by *consuming* the platform leaves per ADR-022 —
zero bespoke PHP.

## What changes

1. **Files leaf on more record types** (manifest integration widgets, same
   pattern as the existing `invoice-files` widget):
   - `ReceiptDetail` — receipt image (`Receipt.photoUri`).
   - `ExpenseClaimDetail` — all receipt evidence for the claim
     (`ExpenseClaimEntry.receiptIds` → `Receipt.photoUri`).
   - `BankConnectionDetail` — imported statement files for the connection
     (`BankStatement.bankConnectionId` / `statementAttachmentUri`).
   - `BtwAangifteDetail` — the filed VAT-return attachment
     (`VatReturn.attachmentUri`).
2. **Calendar leaf**: `calendar` linked-type on `VatReturn` (filing deadlines)
   and `ARInvoice` (payment due dates), rendering the per-object deadline
   events on `BtwAangifteDetail` / `ARInvoiceDetail`. The events themselves
   are ALREADY published to CalDAV by `ComplianceDeadlineCalendarService`
   (REQ-CDC-002/-004, spec `compliance-deadline-calendar`); this change adds
   the per-object *view* surface only and MUST NOT add a second event
   publisher.
3. **Contacts leaf**: `contacts` linked-type on `CustomerMaster` (debtors) and
   `Payee` (creditors), surfacing address-book links on `CustomerDetail` /
   `PayeeDetail` via OpenRegister's contacts integration
   (`ContactsController` / `ContactMatchingService`).
4. **Talk leaf for dossier discussion**: registry-mode already renders a
   `talk` tab on every detail page *without* a curated `sidebarProps.tabs`
   list (REQ-CPA-001). The delta is confined to dossier-bearing pages that
   curated their tabs: those curated lists gain `talk` explicitly where a
   dispute/approval conversation belongs (`ARInvoiceDetail`,
   `ExpenseClaimDetail`, `ContractDetail`), honouring REQ-CPA-001's
   "curated set is not silently changed" scenario by editing the curated list,
   not bypassing it.

All schema-side declarations land as one ADR-037 register fragment
(`lib/Settings/register.d/`), merged by `SettingsService::deepMergeConfig()`.
Because `deepMergeConfig()` replaces arrays wholesale (only objects merge
key-wise), any fragment touching an existing `linkedTypes` array MUST restate
the full array (e.g. `Invoice` stays `["mail"]` untouched; this change touches
only schemas with no current `linkedTypes`).

## Deck: deliberately excluded

A deck leaf is NOT added. Shillinq's task-shaped state is already first-class
register state (`ExpenseClaimEntry.approvalState`, PO approval via
`PurchaseOrderApprovalService`, dunning stages via `DunningRunService`); a
deck board would be a second, unsynchronised source of truth for the same
approval state. Revisit only with a concrete workflow that is demonstrably not
lifecycle-modellable.

## Out of scope / dependencies

- No new event publisher: `ComplianceDeadlineCalendarService` remains the only
  writer of deadline VEVENTs; `IcsService` (booking-confirmation ICS) is
  untouched.
- The `mail` leaf and `mailObjectTemplate` on `Invoice` are untouched.
- No PHP: this change is register-fragment + manifest-overlay only, per the
  `consume-platform-abstractions` spec's "consume, don't reimplement" premise.
- Depends on OpenRegister's leaf implementations already present at HEAD
  (`LinkedEntityService`, `ContactsController`, mail-sidebar,
  `IntegrationRegistry`) — verified present; no OpenRegister-side change is
  required. Per REQ-CPA-003, each leg re-verifies its platform claim against
  HEAD before code is written, and is refused (not forced) if the claim fails.
