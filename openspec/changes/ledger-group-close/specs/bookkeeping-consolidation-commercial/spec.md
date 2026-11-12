# Delta: bookkeeping-consolidation-commercial

## ADDED Requirements

### Requirement: A consolidation run sums the subsidiaries and applies the eliminations (REQ-CONS-011)

Running a consolidation for a group and a period SHALL sum the mapped balances and results of every member administration, apply the elimination entries of that period, and store the outcome on the consolidated report.

#### Scenario: A group of two administrations is consolidated

- **GIVEN** a group with a parent holding EUR 500,000 assets and a subsidiary holding EUR 200,000, with EUR 12,000 intercompany receivables
- **WHEN** a controller runs the consolidation for 2026-10
- **THEN** the consolidated report shows EUR 688,000 total assets
