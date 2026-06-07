# Tasks — Member 03: validation rules

Sourced from the giant's Phase 4 (Validation) and REQ-BBVW-008.

## BBVProgramme validation

- [x] Add `programmeName` validation: required, non-empty, max 255 characters
- [x] Add `programmeCode` regex validation `^\d+\.\d+(\.\d+)?$`
- [x] Add `programmeCode` uniqueness per (administration, fiscalYear)
- [x] Add `fiscalYear` bounds: integer ≥ 1900, ≤ 2100
- [x] Add `status` enum validation (active | archived)
- [x] Return 400 Bad Request with descriptive error if validation fails

## BudgetBBVMapping validation

- [x] Add `glAccountNumber` FK existence check against Chart of Accounts
- [x] Add `allocationPercentage` validation: number 0–100, precision 0.01
- [x] Add `effectiveFrom` required ISO 8601 date validation
- [x] Add `effectiveTo` validation: optional, must be ≥ effectiveFrom if present
- [x] Add per-account allocation sum rule: total ≤ 100% per GL account per fiscal year
- [x] Apply ±0.1% rounding tolerance (99.9–100.1%)
- [x] Return 400 Bad Request with descriptive error if validation fails
