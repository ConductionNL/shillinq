# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 05 — dashboard widgets)

## ADDED Requirements

### Requirement: The system SHALL render the BBV Compliance Dashboard with four widgets

The system SHALL render a BBV Compliance Dashboard using
`CnDashboardPage` composed of: KPI cards (Total / On-Track / At-Risk /
Non-Compliant counts via `CnStatsBlock`), a compliance status pie chart
(`CnChartWidget`), a YTD cumulative spend trend line chart, and a
sortable programme utilization table (`CnDataTable`) with inline status
badges. The dashboard SHALL read the aggregation from the compliance
member; it SHALL NOT compute compliance in a custom dashboard service.

#### Scenario: Finance officer views compliance status

- **GIVEN** a logged-in finance officer with fiscal 2026 programmes,
  allocations, and GL spend through January–May
- **WHEN** the officer opens the BBV Compliance Dashboard
- **THEN** the KPI cards SHALL show the on-track / at-risk /
  non-compliant counts
- **AND** the pie chart SHALL show the status distribution
- **AND** the table SHALL list each programme with budget, YTD spend,
  utilization %, and a status badge
- **AND** the trend chart SHALL show cumulative spend over the fiscal
  year.

### Requirement: The programme table SHALL link rows to programme detail

The programme utilization table SHALL allow clicking a row to navigate
to that programme's detail view, and SHALL surface an at-risk tooltip
on the status badge.

#### Scenario: Officer drills into an at-risk programme

- **GIVEN** programme "2.3.2" shown at 82% utilization (at-risk)
- **WHEN** the officer hovers the at-risk badge
- **THEN** a tooltip SHALL advise reviewing allocations
- **WHEN** the officer clicks the row
- **THEN** the programme detail view SHALL open.
