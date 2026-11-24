# Delta: bookkeeping-lease-disclosures

## ADDED Requirements

### Requirement: Closing a period fills the lease disclosure table (REQ-LD-008)

Closing a period SHALL generate the lease disclosure table for it from the lease register and the payment schedules, and the disclosures page SHALL list it.

#### Scenario: The disclosures page shows the closed year

- **GIVEN** three active leases and the year 2026 closing
- **WHEN** the period closes
- **THEN** the disclosures page lists the 2026 table with the maturity analysis of the three leases
