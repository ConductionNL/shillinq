---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-04-manifest-routes]
chain:
  - bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed
  - bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance
  - bookkeeping-waterschappen-bbv-variant-03-validation-rules
  - bookkeeping-waterschappen-bbv-variant-04-manifest-routes
  - bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets
  - bookkeeping-waterschappen-bbv-variant-06-mapping-index
  - bookkeeping-waterschappen-bbv-variant-07-mapping-detail
  - bookkeeping-waterschappen-bbv-variant-08-compliance-service
  - bookkeeping-waterschappen-bbv-variant-09-fiscal-audit
  - bookkeeping-waterschappen-bbv-variant-10-i18n
  - bookkeeping-waterschappen-bbv-variant-11-testing
  - bookkeeping-waterschappen-bbv-variant-12-docs-quality
---

# Proposal: bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets

Member 5 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-04-manifest-routes`. Successor:
`bookkeeping-waterschappen-bbv-variant-06-mapping-index`.

This `kind: code` member builds the **BBV Compliance Dashboard** — the
`CnDashboardPage` plus its four widgets (KPI cards, status pie, YTD
trend line, programme utilization table) reading the aggregation from
member 02 through the route from member 04.

## Why

The dashboard is the headline compliance deliverable (giant
REQ-BBVW-003): finance officers need a real-time view of utilization,
status distribution, and the per-programme table. With the aggregation
(02) and route (04) already in place, this member is a focused
view-composition task using platform widgets — no custom dashboard
service (ADR-031).

## What Changes

- Create `BBVComplianceDashboard.vue` (CnDashboardPage layout, 4
  widgets).
- Create `BBVKPICards.vue` (4 CnStatsBlock: Total / On-Track / At-Risk /
  Non-Compliant counts).
- Create `BBVComplianceChart.vue` (CnChartWidget pie — status
  distribution).
- Create `BBVTrendChart.vue` (CnChartWidget line — YTD cumulative spend
  per programme).
- Create `BBVProgrammeTable.vue` (CnDataTable with status badges,
  sortable; row click → programme detail).

## Out of Scope (this member)

Mapping index (06), mapping detail (07), compliance service (08),
fiscal/audit (09), i18n (10), tests (11), docs (12). Translations are
applied in member 10.
