# Proposal: bookkeeping-waterschappen-bbv-variant

## Summary

Introduce **Waterschappen BBV Variant (BBVW)** support for Shillinq, enabling Dutch water boards (waterschappen) to track and report compliance with the BBV (Budgettering en Beleidsvormingsvoorbereiding) requirements under the municipal governance framework. This capability extends Shillinq's bookkeeping foundation with mandatory dashboard views and budget-to-programme linkage for public-sector compliance.

**Depends on:** 
- [`add-shillinq-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md) — foundational account structure
- [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md) — GL transaction tracking

## Motivation

Dutch water boards (waterschappen) operate under strict municipal governance rules requiring annual budgeting aligned with policy programmes (BBV framework). Shillinq's core bookkeeping serves as the financial ledger; the BBV variant adds the **governance layer** that maps budget allocations to programme codes and surfaces compliance metrics on a dashboard.

Demand signal: **165** tender mentions seeking BBV compliance capability (governance category). Secondary demand: **57** mentions for budget-line-to-programme mapping functionality.

This enables Shillinq to serve the Dutch public-sector waterboard segment with mandatory regulatory compliance reporting.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-waterschappen-bbv-variant`); declares 2 new registers (`BBVProgramme`, `BudgetBBVMapping`); adds 2 manifest navigation entries (BBV Compliance Dashboard, Budget Mapping).
- [ ] Project: openregister — no source changes; consumes existing schema validation and lifecycle abstractions.
- [ ] Project: nldesign — no changes; leverages existing NL Design tokens for compliance dashboard styling.

## Scope

### In Scope

- One new capability spec (`bookkeeping-waterschappen-bbv-variant`) — see the `specs/` folder.
- The `BBVProgramme` register with programme code, name, description, fiscal-year scope, and compliance metadata (per Dutch BBV standard).
- The `BudgetBBVMapping` register linking budget lines (from General Ledger account hierarchy) to BBV programme codes, with allocation percentage and effective period.
- The **BBV compliance dashboard** — a CnDashboardPage displaying:
  - Compliance status by programme (on-track / at-risk / non-compliant)
  - Budget utilization rate per programme (planned vs. actual spend)
  - Year-to-date BBV adherence metrics
  - Linked budget-vs-programme reconciliation report
- The budget-to-programme **mapping UI** — CnIndexPage + CnDetailPage for viewing and editing BudgetBBVMapping records with inline budget-line picker and programme selector.
- Schema-driven filtering and search on both registers via Shillinq's core platform.

### Out of Scope

- **External compliance reporting** — BBV submission to municipalities. T3 or later.
- **Multi-year programme rollover** — deferred to phase 2.
- **Audit trail export for regulatory submission** — handled by general audit infrastructure.
- **Budget forecast variance analysis** — pure analytics, outside BBV variant scope.

## Approach

Two new registers declared in the Shillinq register file:

1. **`bookkeeping-waterschappen-bbv-variant`** — defines the two registers, their lifecycles, the dashboard widget configuration, and the mapping UI entry points.
2. Related **dashboard widget** and **detail page components** — leverage platform infrastructure (CnDashboardPage, CnDetailPage, CnDataTable) for zero custom component construction.

The spec follows the Conduction schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-BBVW-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and @conduction/nextcloud-vue components.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 new schemas (`BBVProgramme`, `BudgetBBVMapping`).
- `src/manifest.json` — adds 2 navigation entries + their linked pages (dashboard, detail pages).
- `lib/Dashboard/BBVComplianceWidget.vue` — renders compliance metrics; uses `CnStatsBlock` + `CnChartWidget` from platform.
- `src/components/BudgetBBVMappingIndex.vue` + `BudgetBBVMappingDetail.vue` — edit/view pages; uses `CnIndexPage` + `CnDetailPage` + `CnFormDialog` from platform.
- No custom services (compliance logic is pure aggregation via schema queries).

## Cross-Project Dependencies

- **Shillinq T1 General Ledger** — depends on chart of accounts and GL transaction tracking for budget-line context.
- **OpenRegister** — consumes schema validation, relations, and audit-trail abstractions per ADR-022.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| BBV programme taxonomy varies by waterboard | Schema allows custom programme codes; admin UI permits per-administration configuration |
| Budget-to-programme mapping maintenance overhead | CnIndexPage + bulk-edit dialog (platform feature) reduces manual work |
| Compliance dashboard latency on large datasets | Platform aggregation caching via OR's schema extensions |

## Deliverables

1. **proposal.md** (this file)
2. **design.md** — Goals, decisions, reuse analysis, seed data, risks, migration plan
3. **specs.md** — Detailed functional requirements with GIVEN/WHEN/THEN acceptance criteria
4. **tasks.md** — Implementation task checklist
