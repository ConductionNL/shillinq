# Change: accountant-portal

## Why

The external-accountant / boekhouder channel is one of the two dominant
go-to-market models for Dutch bookkeeping software (Yuki, Twinfield, Minox,
Exact all sell through accountants with per-administration pricing), and an
**accountant portal** is the single most-repeated differentiator across the
entire 810-feature competitor research set — **14 competitors ship it, the
highest competitor count of any feature found.** Shillinq already has real
multi-administratie support (`AdministrationContextService`,
`AdministratieSwitcher`) but no accountant-facing surface built on top of it:
an accountant with access to several client administraties has no place to
see them all at a glance, and no one-click way to hand a client's books to
their bookkeeper.

This is **not** the same gap as the already-archived
`2026-07-07-shillinq-accountant-portal-audience` change. That change added a
third **portaliq** audience (`accountant`) for an **external, no-Nextcloud-
account** bookkeeper reached through the shared external portal — read-only,
ADR-046. This change is for the **internal** `accountant_extern` role: a
Nextcloud-account holder (already modelled on `AdministrationMembership`
today, see `bookkeeping-multi-administratie` REQ-MA-003 "Bulk user assignment
for accounting firm") who needs the full in-app surface across every client
they are granted, not a thin read-only portal manifest. See design.md D1 for
the full reasoning and why the two do not overlap or contradict each other.

## What Changes

- **ADDED** `REQ-ACP-001` — an in-app accountant dashboard lists every
  administration the authenticated user has a valid `AdministrationMembership`
  for (reusing `AdministrationContextService::buildContext()`), with no
  administration the user is not a member of ever appearing.
- **ADDED** `REQ-ACP-002` — each client card on the dashboard shows: the most
  recent fiscal period's close state, the most recent BTW return's filing
  status + statutory deadline, a missing-source-document count, and an
  open-items / needs-attention count+list (reusing
  `PeriodCloseAssistantService::analyse()` — no new detection logic).
- **ADDED** `REQ-ACP-003` — **security headline**: a request scoped to an
  administration the authenticated user has no membership for MUST be masked
  as a 404 (never a 403), for both the dashboard's implicit scoping and the
  handover-pack export — mirrors `REQ-MA-001`. Proven with a test that an
  accountant granted only client A cannot reach client B's card or pack.
- **ADDED** `REQ-ACP-004` — a one-click "handover pack" ZIP per client
  administration bundling the XAF 3.2 auditfile, the trial balance, the
  general ledger (journal export) and the BTW-overzicht, each rendered by the
  **existing** report generators (`XafAuditfileGenerator`,
  `TrialBalanceReportGenerator`, `GeneralLedgerReportGenerator`,
  `VatReturnReportGenerator`) via the existing `ReportGenerationService` — no
  new document renderer is added.
- `lib/Service/AccountantDashboardService.php` (new) — composes the per-client
  status card from the signals above.
- `lib/Controller/AccountantPortalController.php` (new) — `GET
  /api/accountant/dashboard`, `GET
  /api/accountant/administrations/{id}/handover-pack`.
- `src/views/AccountantPortalDashboard.vue` + `src/api/accountantApi.js` (new)
  — the dashboard page; registered as a `kind:"page"` custom component
  (registry.js) because the built-in declarative `dashboard` page type's
  widgets bind to one register+schema, not a four-signal composite card with a
  per-row file-download action (see design.md D3).
- `src/manifest.d/accountant-portal.json` (new) — one top-level "Accountant
  portal" menu entry + route.
- Incidental fix: `tests/check-manifest-budget.js`'s `DEFAULT_BUDGET_BYTES`
  was already exceeded by organic fragment growth before this change's own
  ~1KB fragment (measured 1,066,101B vs. a 1,050,000B budget); bumped with
  headroom rather than trimmed — the real structural fix (code-splitting
  `manifest.d/`) is the explicit, separate, still-undecided scope of the
  active `shillinq-manifest-boot-payload-reduction` change.

## Impact

- Affected specs: new `accountant-portal` (this change); no change to
  `bookkeeping-multi-administratie` or the archived `portal-contribution`
  accountant-audience spec — this is additive, consuming both without
  modifying either.
- Affected code: two new PHP classes (service + controller), two new routes,
  one new Vue page + API client + manifest fragment, plus the manifest-budget
  constant noted above.
- No schema changes: `FiscalPeriod`, `VATReturn` and `SupplierInvoice` already
  carry `administrationId` and the fields this change reads.
- **Deferred (named follow-up, not in this change's scope):** a dedicated
  document-completeness/checklist model (today's "missing documents" signal is
  a best-effort `SupplierInvoice.ublSourceUri` count, not a purpose-built
  document register); write/collaboration actions for the accountant (posting
  adjustments, requesting corrections) — the same write-side deferral already
  recorded in the archived portaliq audience change; Excel (`.xlsx`) handover
  formats (today's pack uses each report's existing catalogue formats —
  xml/csv — since `ReportCatalogue` does not offer `.xlsx` for these report
  types and this change does not add a new renderer).
