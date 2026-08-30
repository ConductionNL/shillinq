# Tasks: segregation-control-real-check

## Implementation Tasks

### Task 1: Verify the defect and its real-world semantics against HEAD
- **spec_ref**: `openspec/changes/segregation-control-real-check/specs/segregation-control-real-check/spec.md#requirement-req-scr-001`
- **files**: `lib/Lifecycle/ComplianceValidator.php`, `openspec/specs/bookkeeping-schatkistbankieren/spec.md`, `lib/Settings/register.d/bookkeeping-schatkistbankieren.json`
- **acceptance_criteria**:
  - GIVEN `ComplianceValidator::evaluateRule()` at HEAD WHEN inspected THEN `'segregation' => true` is confirmed hardcoded
  - GIVEN the canonical spec and register.d precondition text THEN `segregation` is confirmed to mean duplicate-IBAN-within-administration, not functiescheiding
- [x] Implement
- [x] Test

### Task 2: Implement evaluateSegregation() with honest pass/fail/indeterminate semantics
- **spec_ref**: `openspec/changes/segregation-control-real-check/specs/segregation-control-real-check/spec.md#requirement-req-scr-002`
- **files**: `lib/Lifecycle/ComplianceValidator.php`
- **acceptance_criteria**:
  - GIVEN another TreasuryAccount in the same administration shares the IBAN THEN the rule returns false and logs the conflicting account id(s)
  - GIVEN no other TreasuryAccount shares the IBAN THEN the rule returns true
  - GIVEN a missing IBAN/administrationId or a failing ObjectService lookup THEN the rule returns false and logs "indeterminate", distinct from a violation log
  - GIVEN `evaluationCriteria.checkDuplicates=false` THEN the rule returns true without querying TreasuryAccount
- [x] Implement
- [x] Test

### Task 3: Prove the bad path fails (duplicate IBAN → violation, not a pass)
- **spec_ref**: `openspec/changes/segregation-control-real-check/specs/segregation-control-real-check/spec.md#requirement-req-scr-002`
- **files**: `tests/Unit/Lifecycle/ComplianceValidatorTest.php`
- **acceptance_criteria**:
  - GIVEN a fixture TreasuryAccount and a second fixture sharing its IBAN in the same administration WHEN `isCompliant()` runs THEN it returns false
  - GIVEN the same administration with no IBAN collision WHEN `isCompliant()` runs THEN it returns true
  - GIVEN a TreasuryAccount lookup that throws WHEN `isCompliant()` runs THEN it returns false (not true)
  - GIVEN a TreasuryAccount with no IBAN WHEN `isCompliant()` runs THEN it returns false (not true)
- [x] Implement
- [x] Test

### Task 4: Sweep sibling validators for other fabricated passes; file issues, do not fix in-change
- **spec_ref**: `openspec/changes/segregation-control-real-check/specs/segregation-control-real-check/spec.md#requirement-req-scr-003`
- **files**: `lib/Standards/`, `lib/Service/*Compliance*.php`, `lib/**/*Validator.php`
- **acceptance_criteria**:
  - GIVEN every `*Validator.php` file and `lib/Standards/`/`lib/Service/*Compliance*` files WHEN grepped for hardcoded `=> true`/"always passes" arms THEN each hit is individually evaluated
  - GIVEN `evaluateRule()`'s `default => true` arm for `transaction-limit`/`reporting-period` THEN it is reported and filed as a Codeberg issue (shillinq#442), not silently fixed here
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests — N/A, no HTTP endpoint changes
- UI changes covered by Playwright browser tests — N/A, no UI surface (backend lifecycle-guard logic only)
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the PHP 8.3 container)
- Feature documentation updated in `docs/` if user-facing (ADR-010) — N/A, internal compliance-control logic, no user-facing feature
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — N/A, log payloads are operator/auditor-facing backend strings, no new translated UI copy
- `openspec validate` passes
