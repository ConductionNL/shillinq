# Delta: bookkeeping-intercompany-elimination

## ADDED Requirements

### Requirement: The nightly soft close writes the elimination entries of each intercompany relation (REQ-ICE-011)

The nightly soft close SHALL run the intercompany matching for every relation of the administration and the period, and SHALL write an elimination journal entry for each matched pair. A page SHALL list the elimination entries per period.

#### Scenario: Matched intercompany sales are eliminated overnight

- **GIVEN** two administrations booked an intercompany sale of EUR 12,000 in period 2026-10, matched both ways
- **WHEN** the nightly soft close runs for period 2026-10
- **THEN** an elimination entry of EUR 12,000 is written for the pair and shows on the eliminations page
