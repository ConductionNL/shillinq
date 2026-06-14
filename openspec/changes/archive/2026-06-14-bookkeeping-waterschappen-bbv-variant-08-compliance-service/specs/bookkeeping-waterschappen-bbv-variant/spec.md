# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 08 — compliance service)

## ADDED Requirements

### Requirement: The system SHALL expose a compliance service that reads the declarative aggregation

The system SHALL provide a `ComplianceService` with
`computeComplianceStatus($programme)` returning utilization, status,
budget, and YTD spend for a programme. The service SHALL read the
declarative aggregation values rather than reimplement the compliance
formulas, and SHALL cache results with a 1-hour TTL, invalidating the
cache when a GL transaction is created or updated.

#### Scenario: Service returns compliance status for a programme

- **GIVEN** programme "2.3.2" with mappings and €85,000 YTD spend
  against a €100,000 budget
- **WHEN** `computeComplianceStatus` is called for "2.3.2"
- **THEN** it SHALL return utilization 85% and status `at-risk`
- **AND** the value SHALL be served from cache on a repeat call until a
  GL transaction write invalidates it.

### Requirement: The dashboard controller SHALL return the widget-data envelope

The system SHALL provide a dashboard widget controller that queries the
`BBVProgramme` and `BudgetBBVMapping` registers and returns the JSON
envelope consumed by the dashboard widgets.

#### Scenario: Dashboard route returns widget data

- **GIVEN** a logged-in finance officer
- **WHEN** the dashboard route is requested
- **THEN** the controller SHALL return per-programme widget data
  (counts, status buckets, table rows)
- **AND** the response SHALL be derived from the registers and the
  aggregation, not a parallel computation.
