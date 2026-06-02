# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 02 — aggregation + compliance)

## ADDED Requirements

### Requirement: The system SHALL compute BBV compliance status via a declarative aggregation

The system SHALL declare an `x-openregister-aggregations` block on the
`BBVProgramme` register that computes, per programme and fiscal year,
the total budget (GL budget × allocation %), the YTD spend (GL
transactions on mapped accounts × allocation %), the utilization ratio,
and the derived compliance status. The compliance status SHALL be
computed at query time and SHALL NOT be persisted as a stored field.

#### Scenario: Compliance status is derived, not stored

- **GIVEN** a `BBVProgramme` with mapped GL accounts and budget
- **WHEN** the aggregation runs
- **THEN** `Utilization` SHALL equal `YTDSpend / TotalBudget`
- **AND** `ComplianceStatus` SHALL be `unconfigured` when no mappings
  exist, `on-track` when Utilization ≤ 75%, `at-risk` when
  75% < Utilization ≤ 90%, and `non-compliant` when Utilization > 90%
- **AND** no stored `complianceStatus` column SHALL exist.

### Requirement: Compliance status SHALL update as GL transactions are recorded

The compliance aggregation SHALL reflect newly recorded GL transactions
on the next query without any manual recomputation step.

#### Scenario: Status transitions as spend rises

- **GIVEN** programme "2.3.2" with a €100,000 budget and no GL spend
- **WHEN** GL transactions totaling €65,000 are recorded
- **THEN** the aggregation SHALL report Utilization 65%, status
  `on-track`
- **WHEN** spend reaches €85,000
- **THEN** status SHALL be `at-risk`
- **WHEN** spend reaches €96,000
- **THEN** status SHALL be `non-compliant`.
