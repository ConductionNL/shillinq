# Tasks — Member 05: dashboard widgets

Sourced from the giant's Phase 2 (Dashboard Implementation) — the
widget components.

## Dashboard page

- [x] Create `src/components/Dashboard/BBVComplianceDashboard.vue` using CnDashboardPage layout
- [x] Wire up the 4 widget types: KPI cards, pie chart, table, line chart

## KPI cards

- [x] Create `src/components/Dashboard/BBVKPICards.vue`
- [x] Display 4 CnStatsBlock cards: Total, On-Track, At-Risk, Non-Compliant counts
- [x] Fetch counts from the aggregation route

## Compliance pie chart

- [x] Create `src/components/Dashboard/BBVComplianceChart.vue`
- [x] CnChartWidget (pie) — compliance status distribution (green/amber/red/grey)
- [x] Fetch aggregation status buckets

## YTD trend chart

- [x] Create `src/components/Dashboard/BBVTrendChart.vue`
- [x] CnChartWidget (line) — YTD cumulative spend per programme
- [x] Query GL transactions and compute cumulative spend

## Programme table

- [x] Create `src/components/Dashboard/BBVProgrammeTable.vue` (CnDataTable)
- [x] Columns: Code, Name, Budget, YTD, Utilization %, Status
- [x] Sortable / filterable with inline status badge (🟢 🟡 🔴 ⚪)
- [x] Row click → navigate to the programme detail page
- [x] Add at-risk badge tooltip ("Projected to exceed budget — review allocations")
