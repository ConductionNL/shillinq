# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 09 — fiscal scoping + audit)

## ADDED Requirements

### Requirement: BBV queries and views SHALL be scoped to the active fiscal year

The system SHALL scope all BBV programme, mapping, dashboard, and
compliance queries to the active administration's current fiscal year,
inherited from the Shillinq Administration context. Prior-fiscal-year GL
transactions SHALL be excluded, the active fiscal year SHALL be
surfaced in the UI, and the scope filter SHALL be derived server-side
so one administration cannot read another's data.

#### Scenario: Fiscal year context is implicit

- **GIVEN** a user viewing the BBV dashboard for waterboard "Rijn &
  IJssel" with current fiscal year 2026
- **WHEN** the dashboard loads
- **THEN** all widgets SHALL display fiscal-2026 data only
- **AND** GL transactions from 2024 and 2025 SHALL be excluded
- **AND** a label SHALL indicate "FY 2026"
- **WHEN** the user switches administration
- **THEN** the data SHALL refresh to the new administration's scope.

### Requirement: BBV changes SHALL be captured in the immutable audit trail

The system SHALL ensure every create, update, and delete on
`BBVProgramme` and `BudgetBBVMapping` is captured by OpenRegister's
immutable audit trail, with no app-local audit service.

#### Scenario: Programme changes are audited

- **GIVEN** an admin creates programme "4.2.0"
- **WHEN** the record is saved
- **THEN** the OR audit trail SHALL record the timestamp, user id,
  action "create", object, and after-state
- **WHEN** the admin later renames the programme
- **THEN** the audit trail SHALL record an "update" with the before and
  after state.
