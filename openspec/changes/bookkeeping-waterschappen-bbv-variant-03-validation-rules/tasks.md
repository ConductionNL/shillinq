# Tasks — Member 03: validation rules

Sourced from the giant's Phase 4 (Validation) and REQ-BBVW-008.

## BBVProgramme validation

- [ ] Add `programmeName` validation: required, non-empty, max 255 characters
- [ ] Add `programmeCode` regex validation `^\d+\.\d+(\.\d+)?$`
- [ ] Add `programmeCode` uniqueness per (administration, fiscalYear)
- [ ] Add `fiscalYear` bounds: integer ≥ 1900, ≤ 2100
- [ ] Add `status` enum validation (active | archived)
- [ ] Return 400 Bad Request with descriptive error if validation fails

## BudgetBBVMapping validation

- [ ] Add `glAccountNumber` FK existence check against Chart of Accounts
- [ ] Add `allocationPercentage` validation: number 0–100, precision 0.01
- [ ] Add `effectiveFrom` required ISO 8601 date validation
- [ ] Add `effectiveTo` validation: optional, must be ≥ effectiveFrom if present
- [ ] Add per-account allocation sum rule: total ≤ 100% per GL account per fiscal year
- [ ] Apply ±0.1% rounding tolerance (99.9–100.1%)
- [ ] Return 400 Bad Request with descriptive error if validation fails
