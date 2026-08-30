---
title: Waterschappen BBV variant — design and operations
description: How the waterschappen BBV variant maps the chart of accounts to BBV programmes, computes compliance, and renders the dashboard / mapping UI. Architecture, admin guide, and audit-export usage.
---

# Waterschappen BBV variant

This page is the developer + admin reference for the **waterschappen BBV
variant** capability. It covers the architecture that links the
double-entry general ledger to the Dutch
*Besluit Begroting en Verantwoording* (BBV) programme structure used by
Dutch water boards, the compliance dashboard surface, the budget mapping
UI, and the audit-trail integration.

The capability shipped as the 12-slice
`bookkeeping-waterschappen-bbv-variant` chain (ADR-032). Every component
documented here is owned by exactly one chain slice; the spec deltas
under `openspec/specs/bookkeeping-waterschappen-bbv-variant/` are the
authoritative requirement source.

## 1. Scope

Water boards (*waterschappen*) report against the BBV programme
structure: every euro of expenditure has to be allocated to a numbered
programme (e.g. `2.3.2` — *Watersysteem — Onderhoud waterkeringen*) and
the cumulative spend per programme has to stay within the budgeted
envelope. The variant adds, on top of Shillinq's general ledger:

- A `BBVProgramme` schema carrying programme code, name, fiscal-year
  budget and an `x-openregister-aggregations` block that materialises
  `totalBudget`, `ytdSpend`, `utilization`, `utilizationPercentage`, and
  `complianceStatus` directly on every fetched programme.
- A `BudgetBBVMapping` schema linking general-ledger accounts to BBV
  programmes with an allocation percentage and an effective-date range.
- A compliance dashboard (`/bbv-dashboard`) with KPI cards, a pie chart
  per status bucket, a YTD trend chart, and a per-programme utilization
  table.
- A budget-mapping CRUD surface (`/budget-mappings`, `/budget-mappings/{id}`)
  with allocation-sum validation (>100% blocked client- and server-side)
  and a delete-confirm dialog.
- Fiscal-year scoping inherited from the active Shillinq administration
  and an audit trail on `BBVProgramme` and `BudgetBBVMapping` via the
  OpenRegister audit-trail plugin.

The maths (the threshold buckets, the YTD aggregation, the allocation
sum) is **declarative on the schemas** — the imperative PHP/Vue layer
never reimplements the formulas (ADR-031 / giant D3).

## 2. Component layout

All sources live in `lib/Service/`, `lib/Listener/`, `lib/Controller/`,
`lib/Dashboard/`, `lib/Settings/register.d/`, `src/components/`, and
`src/modals/`.

| Component | Slice | Responsibility |
| --- | --- | --- |
| `BBVProgramme` schema | 01 | Programme code, name, fiscal-year, budget; UI ordering and form layout. |
| `BudgetBBVMapping` schema | 01 | GL-account ↔ BBV-programme link with allocation %, effective range, status. |
| Demo seed `bbv-waterschappen-programmes-2026-demo.json` | 01 | Five active programmes for the 2026 fiscal year. |
| `x-openregister-aggregations` on `BBVProgramme` | 02 | Declarative `totalBudget`, `ytdSpend`, `utilization`, `utilizationPercentage`, `complianceStatus` — runs server-side, materialised on every GET. |
| Validation rules on `BudgetBBVMapping` | 03 | Declarative `allocationPercentage ≤ 100 - sum(other mappings)`, `effectiveTo ≥ effectiveFrom`, programme/account existence checks (±0.1% rounding tolerance). |
| `BBVDashboardController` (`/bbv-dashboard`) | 04, 08 | Thin page controller returning the widget envelope; delegates to `BBVComplianceWidget`. |
| `BudgetBBVMappingController` (`/budget-mappings`, `/budget-mappings/{id}`) | 04 | Thin page controller; OR object endpoints carry the CRUD. |
| `BBVKPICards.vue`, `BBVComplianceChart.vue`, `BBVTrendChart.vue`, `BBVProgrammeTable.vue` | 05 | Dashboard widgets — render only, no client-side maths. |
| `BBVComplianceDashboard.vue` | 05 | Page composition + data fetch. |
| `BudgetBBVMappingIndex.vue` | 06 | `CnIndexPage` with search / fiscal-year / allocation / date facets. |
| `BudgetBBVMappingDetail.vue` | 07 | `CnDetailPage` form; allocation projection + >100% guard. |
| `GlAccountPicker.vue`, `BBVProgrammePicker.vue` | 07 | `NcSelect` pickers (`input-label`, no manual `<label>` — keeps NcSelect's a11y wiring intact). |
| `DeleteBudgetMappingDialog.vue` | 07 | Confirm-gated delete modal (in `src/modals/` per modal-isolation gate). |
| `ComplianceService` | 08 | Reads materialised aggregation, caches per-programme envelope for 1h, hands the dashboard widget its JSON. |
| `BBVComplianceWidget` (envelope builder) | 08 | Wraps `ComplianceService` for the dashboard route. |
| `GLTransactionComplianceCacheListener` | 08 | Drops the `shillinq-bbv-compliance` cache namespace on any `GLTransaction` / `GLLine` / `GLTransactionLine` create or update. |
| Fiscal-year + audit-trail integration | 09 | Inherits fiscal year from the active administration; `BBVProgramme` and `BudgetBBVMapping` carry the OR audit-trail plugin so create/update/delete are logged with before/after state. |
| `en.json`, `nl.json` translation keys | 10 | Dashboard, mapping, and validation strings in English and Dutch (ADR-007). |
| Unit, integration, and browser tests | 11 | Compliance service coverage, dashboard rendering, mapping CRUD, fiscal-year scoping, validation error paths. |

## 3. Data flow

```
┌──────────────────┐                       ┌────────────────────┐
│  GL transaction  │ — ObjectCreated/      │ GLTransaction…     │
│  write (slice)   │   Updated event ────▶ │ ComplianceCache    │
└──────────────────┘                       │ Listener (slice 8) │
                                           └─────────┬──────────┘
                                                     │ ICache->clear()
                                                     ▼
                                           ┌────────────────────┐
                                           │ shillinq-bbv-      │
                                           │ compliance cache   │
                                           └─────────┬──────────┘
                                                     │ next dashboard hit
                                                     ▼
┌────────────────────┐  read materialised   ┌────────────────────┐
│ BBVProgramme       │ ◀───── values ────── │ ComplianceService  │
│  (with x-or-       │                      │  (slice 8)         │
│  aggregations)     │                      └─────────┬──────────┘
└────────────────────┘                                │ envelope
                                                      ▼
                                            ┌────────────────────┐
                                            │ BBVComplianceWidget│
                                            │  / dashboard JSON  │
                                            └─────────┬──────────┘
                                                      │
                                                      ▼
                                            ┌────────────────────┐
                                            │ BBVComplianceDash- │
                                            │ board.vue (slice 5)│
                                            └────────────────────┘
```

Key invariants:

- **The aggregation runs on the server**, on every BBVProgramme GET — the
  cache is purely a re-fetch dampener (ADR-031).
- **The cache is dropped, not surgically invalidated**, because a single
  posted line can touch any number of mapped programmes — the cheapest
  correct response is to clear the whole namespace and let the next
  dashboard render repopulate from the engine.
- **`unconfigured` is the only client-safe fallback status**; the
  threshold buckets (`on-track` ≤ 75% < `at-risk` ≤ 90% < `non-compliant`)
  are declarative on the schema and are never re-encoded in PHP or Vue.

## 4. Admin guide

### Enabling the variant

The variant is part of the Shillinq app — no separate install. Once the
app is enabled:

1. The registers + schemas are imported via the
   `lib/Repair/InitializeRegister` repair step on app enable (see
   `reference_or-register-import-via-repair-step`).
2. The five demo programmes (`bbv-waterschappen-programmes-2026-demo.json`)
   are seeded under the `2026` fiscal year so an officer can validate the
   dashboard before configuring real programmes.
3. The dashboard is reachable at `/apps/shillinq/bbv-dashboard`; the
   mapping CRUD is reachable at `/apps/shillinq/budget-mappings`.

### Configuring programmes

1. Open *OpenRegister → shillinq → BBVProgramme* and import or create a
   programme per BBV programmacode for the current fiscal year. Mark old
   programmes `status = archived` so they are excluded from new
   mappings (slice 01 D1).
2. Update the `totalBudget` field (in cents) when the council adopts the
   budget for the year; the dashboard reflects the change on the next
   fetch (cache invalidates on any GL write).

### Linking accounts to programmes

1. Open *Shillinq → Budget Mappings* (`/budget-mappings`).
2. Press **+ Add** and pick the GL account and BBV programme. Set the
   allocation percentage (cumulative per account per fiscal year must be
   ≤ 100% within ±0.1% rounding).
3. Set `Effective From`. Leave `Effective To` empty for an open-ended
   mapping; backfill it when the link sunsets.
4. The detail page shows a live allocation-sum readout — Save is blocked
   while the projected total exceeds 100% (client guard); the
   declarative validation on the schema rejects the same write
   server-side (defence in depth — ADR-022).

### Reading the dashboard

The dashboard at `/bbv-dashboard` renders four widgets:

- **KPI cards** — total active programmes, on-track count, at-risk
  count, non-compliant count.
- **Compliance pie chart** — share of programmes per status bucket
  (`on-track` 🟢 / `at-risk` 🟡 / `non-compliant` 🔴 /
  `unconfigured` ⚪).
- **YTD trend chart** — cumulative spend per programme across the 12
  fiscal months.
- **Programme table** — sortable per-programme table (code, name,
  budget, YTD, utilization %, status badge). Row click navigates to the
  budget-mapping detail page so the officer can drill into the GL ↔
  programme split.

All four widgets render from the same materialised aggregation, so
their numbers always agree.

### Audit-export usage

Both `BBVProgramme` and `BudgetBBVMapping` carry the OpenRegister
audit-trail plugin (slice 09). Every create, update, or delete is
recorded with timestamp, user id, action, and before/after state. To
export an audit pack for a programme or mapping:

- Open the programme/mapping detail page; the **Audit trail** sidebar
  shows the chronological event log.
- Use the OpenRegister `GET /api/audit-trails?objectId=…` endpoint to
  pull the same trail as JSON for archival, or wire the Shillinq
  Rekenkamer audit pack (Tier-3) to bundle the BBV variant into the
  annual audit submission.

## 5. Translations

UI strings live in `l10n/en.json` and `l10n/nl.json` under the
`shillinq` translation domain. The slice-10 translation pass added the
dashboard, mapping, and validation strings. When adding a new BBV
string, prefer reusing an existing key (e.g. `"Allocation %"`) before
creating a new one — `npm run lint` enforces JSON validity and the
review checklist verifies key consistency.

## 6. Extension recipes

- **Add a new validation rule.** Update
  `bookkeeping-waterschappen-bbv-variant-03-validation-rules.json` (the
  validation block on `BudgetBBVMapping`). Add a Vue side-by-side guard
  in `BudgetBBVMappingDetail.vue` only if the rule needs interactive
  feedback before save — the declarative rule remains the
  authoritative gate (ADR-022).
- **Add a new dashboard widget.** Drop a Vue file under
  `src/components/Dashboard/`, register it in
  `BBVComplianceDashboard.vue`, and have it consume the materialised
  fields on `BBVProgramme` — do **not** introduce a new aggregation
  formula in PHP/Vue (ADR-031).
- **Add a new compliance bucket.** Extend the `complianceStatus`
  computed field on the slice-02 aggregation block. Update the badge
  emoji map in `BBVProgrammeTable.vue` and the palette in
  `BBVComplianceChart.vue`. The PHP fallback in `ComplianceService`
  should keep `unconfigured` as the only imperative default.
- **Hook a new event into the cache listener.** Append the event class
  to the `Application::registerListener(...)` block in
  `lib/AppInfo/Application.php`; `GLTransactionComplianceCacheListener`
  already drops the whole namespace, so the new event only needs to be
  routed to it.

## 7. Related ADRs and specs

- **ADR-031** — declarative-over-imperative for derived data.
- **ADR-032** — chain decomposition; this capability is the canonical
  12-slice example.
- **ADR-022** — declarative-first validation; the >100% allocation
  guard is enforced on the schema, not the controller.
- **ADR-007** — i18n via `IL10N::t()` and `t('shillinq', …)`.
- **ADR-036** — manifest dispatch + custom-component registry; the
  dashboard + mapping pages are wired through the manifest.
- **REQ-BBVW-001..009** — capability requirements; see the spec deltas
  under each `bookkeeping-waterschappen-bbv-variant-NN-…/specs/` for
  the per-slice authoritative source.
