# Tasks — Member 05: dashboard widgets

Sourced from the giant's Phase 2 (Dashboard Implementation) — the
widget components.

## Dashboard page

- [ ] Create `src/components/Dashboard/BBVComplianceDashboard.vue` using CnDashboardPage layout
- [ ] Wire up the 4 widget types: KPI cards, pie chart, table, line chart

## KPI cards

- [ ] Create `src/components/Dashboard/BBVKPICards.vue`
- [ ] Display 4 CnStatsBlock cards: Total, On-Track, At-Risk, Non-Compliant counts
- [ ] Fetch counts from the aggregation route

## Compliance pie chart

- [ ] Create `src/components/Dashboard/BBVComplianceChart.vue`
- [ ] CnChartWidget (pie) — compliance status distribution (green/amber/red/grey)
- [ ] Fetch aggregation status buckets

## YTD trend chart

- [ ] Create `src/components/Dashboard/BBVTrendChart.vue`
- [ ] CnChartWidget (line) — YTD cumulative spend per programme
- [ ] Query GL transactions and compute cumulative spend

## Programme table

- [ ] Create `src/components/Dashboard/BBVProgrammeTable.vue` (CnDataTable)
- [ ] Columns: Code, Name, Budget, YTD, Utilization %, Status
- [ ] Sortable / filterable with inline status badge (🟢 🟡 🔴 ⚪)
- [ ] Row click → navigate to the programme detail page
- [ ] Add at-risk badge tooltip ("Projected to exceed budget — review allocations")
