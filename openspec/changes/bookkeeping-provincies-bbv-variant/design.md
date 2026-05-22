# Design — BBV Compliance Dashboard & Budget-to-Programme Linker

## Context

Dutch provinces operating under BBV (Begrotings- en Verantwoordingsstelsel)
governance framework must track spending against official programme
budgets (ruimte, mobiliteit, water, milieu, cultuur, economie, bestuur).
The `add-shillinq-provincies-bbv-variant` spec established the BBV
foundation; this feature adds operator-facing pages for compliance
monitoring and budget-to-programme mapping.

The change is **spec + UI pages** (no new PHP services). Implementation
lands later via `opsx-apply` and the standard Hydra pipeline; this doc
explains the architecture and data flow.

## Goals

- Enable provinces to **monitor BBV compliance in real-time** — visual
  dashboard showing budget health by programme with exception flagging.
- Provide **bulk budget-to-programme mapping** — operators map GL accounts
  to BBV programme structure without manual ledger edits.
- Consume **only existing OpenRegister registers** — no new schema needed;
  leverage `Budget`, `GLLine`, `GLTransaction`, `Account` entities.
- Make **budget tracking auditable** — all mappings via `programmaStructure`
  assignment on GL lines, visible in audit trail.

## Non-Goals

- Forecast modelling — dashboard historical/current only.
- RFQ/procurement coupling — budgeting only.
- Multi-year rolling budgets — FY-scoped.
- Custom programme hierarchies — 7 canonical only.
- Automated GL-account-to-programme ML mapping (T4+).

## Decisions

### D1 — Dashboard is read-only; sourced from GL + Budget registers

`BBVComplianceDashboard` queries:
- `Budget` records filtered by `programmaStructure` and fiscal year
- `GLLine` records with matching `programmaStructure`, summed by account type
- `GLTransaction` balance queries (monthly breakdown for trend chart)

No new storage; all data computed from existing registers via standard
`ObjectService` + `IndexService` queries. Dashboard caches results nightly
(refresh cadence TBD in UX review).

### D2 — Bulk budget-to-programme mapping via CnFormDialog

`BudgetToProrammeLinker` page provides:
- `CnIndexPage` listing GL accounts (filtered by budget association)
- Bulk select + "Link to Programme" action → `CnFormDialog` modal
- Dialog form: select target programme + effective date → applies to all selected
- On save: `ObjectService.updateObject()` sets `programmaStructure` field per line

No approval workflow; operator action is terminal (but audit-trail recoverable).

### D3 — ProgrammaStructure values are enum, not configurable

The 7 official BBV programmes are hardcoded in `GLLine.programmaStructure`
enum:
- `ruimte` (space, urban planning)
- `mobiliteit` (mobility, transport)
- `water` (water management)
- `milieu` (environment)
- `cultuur` (culture, heritage)
- `economie` (economic development)
- `bestuur` (administration, governance)

Provinces cannot add custom programmes; they map local budgets to this
taxonomy. (Violating this breaks BBV audit compliance.)

### D4 — Compliance dashboard uses traffic-light status, not percentage threshold

KPI cards show:
- **Total Budget** (absolute EUR)
- **Committed** (purchase orders + contracts in active state)
- **Spent** (GL postings settled)
- **Remaining** (budget - committed - spent)

Status indicator: ✅ Green (<85%), 🟡 Yellow (85-100%), 🔴 Red (>100%).
Rationale: provincial budgets have strict overspend rules; yellow/red
alert controllers to governance (no automatic freeze — that's out of scope).

### D5 — Budget-to-programme mapping is per-GL-line, not per-GL-account

A single GL account (e.g., 4100 "Personnel") may carry entries posted
to different programmes (e.g., personnel for water dept vs. culture dept).
Mapping occurs at line level: each posting rule gets a `programmaStructure`
assignment. This enables full GL drilling down to programme without
ambiguity.

### D6 — Dashboard filters: programme, year, status; no ad-hoc date ranges

Pre-built filters:
- **Programme** (7-value enum)
- **Fiscal Year** (list of available years with data)
- **Budget Status** (approved, provisional, amended)
- **Compliance Status** (green, yellow, red)

Ad-hoc date ranges deferred to T4; standard dashboard uses fiscal-year
boundaries (aligns with BBV audit cycles).

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Dashboard layout | `CnDashboardPage` (GridStack) | Dashboard page renders 4 KPI cards + 2 charts (budget vs. actuals, trend) as widgets |
| KPI cards | `CnStatsBlock` + `CnKpiGrid` | 4 cards (total budget, committed, spent, remaining) |
| Charts | `CnChartWidget` (ApexCharts) | Budget vs. actuals bar chart by programme; trend line chart (monthly) |
| Data table | `CnDataTable` | Linker page lists GL lines with budget association; sortable, paginated |
| Bulk actions | `CnMassActionBar` + `CnFormDialog` | Select GL lines → "Link to Programme" → modal form |
| Filtering | `CnFilterBar` | Dashboard filter sidebar; programme + year + status filters |
| Form generation | Schema-driven `CnFormDialog` | Linker modal: programme dropdown (enum), effective date picker, submit |
| Queries | `ObjectService` + `IndexService` | All data sourced via standard OR methods; no custom controllers |
| Audit trail | Automatic on `ObjectService.updateObject()` | All budget-to-programme assignments logged via OR audit system |
| Manifest entries | Standard pattern | 2 pages registered per app structure; feature-flag guard |

## Seed Data

Example data for testing (Dutch context):

### Budget (example FY 2026)

| budgetName | totalAmount | programmaStructure | status | fiscalYear |
|---|---|---|---|---|
| Mobiliteit operationeel | €500,000 | `mobiliteit` | approved | 2026 |
| Water beheer | €300,000 | `water` | approved | 2026 |
| Cultuur programmering | €150,000 | `cultuur` | approved | 2026 |
| Economie ontwikkeling | €200,000 | `economie` | provisional | 2026 |

### GLLine (example with programmaStructure assignments)

| accountNumber | description | programmaStructure | amount | transactionDate |
|---|---|---|---|---|
| 4100 | Personeel Mobiliteit | `mobiliteit` | €45,000 | 2026-05-15 |
| 4100 | Personeel Water | `water` | €28,000 | 2026-05-15 |
| 6200 | Materialen Cultuur | `cultuur` | €12,000 | 2026-04-20 |
| 6300 | Contracten Economie | `economie` | €35,000 | 2026-05-10 |

## Architectural Alignment

- **ADR-004 (Frontend)**: Pages use `@conduction/nextcloud-vue` components;
  Vue 2 Options API; Pinia stores for dashboard widget state.
- **ADR-010 (NL Design)**: Dashboard styled with NL Design tokens; responsive
  320–1920px; WCAG AA compliant (keyboard nav, color not sole method).
- **ADR-022 (Consume OR Abstractions)**: No custom querying; leverage `CnDashboardPage`,
  `CnDataTable`, form dialogs from shared library.
- **ADR-031 (Declarative Business Logic)**: Budget-to-programme mapping is
  pure data assignment (no PHP rules engine).

## Migration Path

For existing Shillinq deployments with GL lines but no `programmaStructure`:

1. Dashboard shows empty/warning state until GL lines are tagged.
2. Bulk linker enables batch assignment (filter by account, map to programme).
3. Progressive mapping: operators link high-value accounts first, remainder
   via dashboard exception alerts.
4. No destructive migration; `programmaStructure` field is nullable.

## Rollback Path

If BBV requirements change (rare; legislation-driven):

1. Revert manifest entries; pages are unreachable.
2. Existing `programmaStructure` assignments persist (audit-trail visible).
3. Re-export historical GL data under new rules via ETL if needed.
