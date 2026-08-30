# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 03 — validation rules)

## ADDED Requirements

### Requirement: The system SHALL validate BBV programme data at the schema level

The system SHALL enforce, at the OpenRegister schema level:
`programmeName` required and ≤ 255 characters; `programmeCode` matching
`^\d+\.\d+(\.\d+)?$` and unique per (administration, fiscalYear);
`fiscalYear` an integer between 1900 and 2100; and `status` one of
`active | archived`. Invalid writes SHALL be rejected with HTTP 400.

#### Scenario: Programme with an invalid code is rejected

- **GIVEN** an admin entering a programme with code "1-2-3" (hyphens)
- **WHEN** the admin saves
- **THEN** validation SHALL fail with an error indicating the required
  format (e.g. "1.1" or "1.1.1")
- **AND** the record SHALL NOT be persisted.

### Requirement: The system SHALL validate BudgetBBVMapping data at the schema level

The system SHALL enforce: `glAccountNumber` exists in the Chart of
Accounts; `allocationPercentage` is a number 0–100 with 0.01 precision;
`effectiveFrom` is a valid ISO 8601 date; `effectiveTo`, when present,
is ≥ `effectiveFrom`. Invalid writes SHALL be rejected with HTTP 400.

#### Scenario: Mapping with an end date before the start date is rejected

- **GIVEN** a mapping with `effectiveFrom` 2026-06-01 and `effectiveTo`
  2026-01-01
- **WHEN** the admin saves
- **THEN** validation SHALL fail
- **AND** the record SHALL NOT be persisted.

### Requirement: The system SHALL enforce that per-account allocation does not exceed 100%

The system SHALL enforce that the sum of `allocationPercentage` for a
single GL account within a single fiscal year does not exceed 100%,
within a ±0.1% rounding tolerance.

#### Scenario: Over-allocation is rejected

- **GIVEN** GL account 4100 already has mappings totaling 90%
- **WHEN** the admin tries to add a mapping of 15% for GL 4100
- **THEN** validation SHALL fail with a message stating the total would
  be 105% and the maximum is 100%
- **AND** the mapping SHALL NOT be saved.
