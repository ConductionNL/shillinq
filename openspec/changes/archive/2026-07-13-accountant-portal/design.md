# Design: accountant-portal

## Context

Shillinq already models multi-administratie access as
`AdministrationMembership` records (`administrationId` + `userId` + `role` +
optional validity window), read through `AdministrationContextService`, and
already lists `accountant_extern` as one of the roles that RBAC recognises
(`AdministrationContextService::POSTING_ROLES` deliberately excludes it — a
read-only role). `AdministratieSwitcher` lets a multi-membership user switch
their active administration in-session. What is missing is a surface an
accountant actually opens: a multi-client overview and a way to hand a
client's books over, both scoped by the same membership records.

## Decisions

### D1 — In-app scoped view, not a portaliq contribution

**Decision: in-app.** An accountant with a Nextcloud account and
`accountant_extern` memberships across several administraties needs:

1. The **full app surface** for each client when they switch into it — every
   existing bookkeeping page, not a curated read-only manifest. Portaliq
   (ADR-046) is explicitly read-only this wave; an in-house accountant
   routinely needs to open a transaction, review a document, or (with
   `mayPostJournalEntries`) post an entry.
2. A **cross-client aggregation** (the dashboard) that reads FOUR different
   schemas per client (`FiscalPeriod`, `VATReturn`, `SupplierInvoice`, plus
   `PeriodCloseAssistantService`'s own multi-schema detection) and a
   **streaming ZIP** action (the handover pack). Portaliq's ADR-046 contract
   is a static, claim-scoped **manifest** of collections — it has no
   aggregation or file-generation seam; forcing this through it would mean
   inventing exactly the kind of write/compute capability ADR-046 defers.

This does **not** overlap with the archived
`2026-07-07-shillinq-accountant-portal-audience` change: that change is the
external, no-Nextcloud-account bookkeeper reached via the shared portal
(read-only manifest, `claims.shillinq.accountantAdministrationId`, three
audiences: customer/supplier/accountant). This change is the internal,
Nextcloud-account `accountant_extern` user reached via the app itself,
scoped by `AdministrationMembership` (not a portaliq claim). An accounting
firm could plausibly use both: junior staff with full Nextcloud accounts use
this in-app portal; a client's own external reviewer without an account uses
the portaliq surface. Neither change needs to know about the other's
existence for either to work correctly — REQ-ACP-* additions here do not
touch `REQ-SPC-010/011/012` or `PortalContributionProvider`.

### D2 — Reuse `AdministrationContextService`; no parallel auth

Every accessible administration on the dashboard, and every access check on
the handover-pack export, goes through
`AdministrationContextService::buildContext()` /
`AdministrationContextService::canAccess()` — the exact same calls
`AdministrationController` and `AdministrationExportController` already make.
No new grant model, no new membership schema, no new role. This is also why
the security proof (REQ-ACP-003) is cheap to make airtight: the masking
behaviour (404, never 403) is inherited from code already exercised by
`AdministrationContextServiceTest` / `AdministrationExportControllerTest`; the
new test only has to prove the new controller calls `canAccess()` before
touching data, the same shape as `AdministrationExportControllerTest
::testForbiddenAdministrationMasked404()`.

### D3 — Custom Vue page, not the declarative `dashboard` manifest type

Shillinq's manifest v2 has a built-in `type: "dashboard"` page (see
`bookkeeping-vat-btw-filing.json`'s "BTW report" page): summary/table/
lineChart/pieChart widgets bound to **one** register + schema, plus a static
`exports` list. The accountant dashboard needs a card per **administration**
(not per row of one schema) composed from **four independently-queried
signals** (period-close, BTW filing, missing documents, open items across
several schemas via `PeriodCloseAssistantService`), plus a per-card imperative
action (stream a ZIP file). None of that is expressible as
summary/table/chart-on-one-schema. Per registry.js policy this is registered
as a `kind:"page"` custom component with this justification recorded both
here and inline in registry.js's import docblock — the same precedent as
`ReportingComplianceOverview` (a per-card generate-dialog + format picker) and
`PurchaseOrderDetail` (server-computed approval-chain preview).

### D4 — Handover pack composes existing generators; adds no renderer

`AccountantPortalController::handoverPack()` calls the existing
`ReportGenerationService::generate()` once per report type (`xaf`,
`trial-balance`, `general-ledger`, `vat-return`), exactly as an operator
generating those reports individually from "Reporting & Compliance" would,
then zips the resulting stored files — the same
`ZipArchive`-bundling shape `AdministrationExportController::bundleZip()`
already uses for the XAF-plus-attachments export. A single report's failure
(e.g. a client with no VAT return on file yet) is logged and skipped, not
fatal to the whole pack (mirrors `ReportGenerationService::generate()`'s own
fail-soft envelope). The pack therefore also benefits automatically from any
future generator improvement (e.g. an `.xlsx` trial balance) without this
controller changing.

### D5 — "Missing documents" and "open items" signals

- **Open items / needs attention**: delegated wholesale to
  `PeriodCloseAssistantService::analyse($administrationId, $periodId)` — the
  existing Tier-2 AI close-assistant flag detector (open AP/AR, unreconciled
  bank receipts, outstanding expense claims). This is a direct reuse, not a
  re-implementation; the dashboard's `openItemsCount` / `attentionItems` are
  the count and (truncated) list of that call's return value.
- **Missing documents**: there is no dedicated document-completeness schema
  in Shillinq today (`AdministrationExportController`'s own `Document` schema
  lookup is already documented as best-effort / may not exist). Rather than
  invent a new schema for this change, the signal is a direct count of
  `SupplierInvoice` rows for the administration with an empty
  `ublSourceUri` — the source-document reference field the purchase-order/AP
  pipeline already writes. This is an honest, narrower proxy (not every
  "missing document" concept a real bookkeeping close needs), flagged as a
  named follow-up in proposal.md rather than papered over.

## Non-goals

- No write/adjustment capability for the accountant beyond what their
  existing `AdministrationMembership` role already grants (e.g.
  `mayPostJournalEntries`) — this change adds read/export surfaces only.
- No new schema, no new role, no new claim model.
- No change to the portaliq `accountant` audience or its provider.
- No manifest code-splitting (see proposal.md's incidental-fix note) —
  that is the separate, already-active
  `shillinq-manifest-boot-payload-reduction` change.
