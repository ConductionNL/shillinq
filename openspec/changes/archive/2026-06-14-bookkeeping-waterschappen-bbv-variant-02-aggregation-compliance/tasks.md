# Tasks — Member 02: aggregation + compliance

Sourced from the giant's Phase 4 (Compliance Status Aggregation).

## Aggregation definition (declarative)

- [x] Add `x-openregister-aggregations` block to the `BBVProgramme` schema (per ADR-031)
- [x] Define aggregation query: sum GL spend by mapped account, applying allocation percentage per mapping
- [x] Define aggregation query: sum total budget per programme (GL budget × allocation %)
- [x] Compute `Utilization` = YTDSpend / TotalBudget
- [x] Derive `ComplianceStatus` enum (unconfigured / on-track ≤75% / at-risk 75–90% / non-compliant >90%) per REQ-BBVW-005

## Integration test

- [x] Add integration test asserting materialised `TotalBudget` for seeded fixtures
- [x] Assert `YTDSpend` and `Utilization` for seeded GL transactions
- [x] Assert `ComplianceStatus` transitions (on-track → at-risk → non-compliant) as GL spend rises
