# Tasks: missing-lifecycle-guards

## Implementation Tasks

### Task 1: Shared RegisterRequiresGuardAdapter + cross-app stub/analysis wiring
- **spec_ref**: `openspec/changes/missing-lifecycle-guards/specs/missing-lifecycle-guards/spec.md#requirement-req-lcg-001`
- **files**: `lib/Lifecycle/RegisterRequiresGuardAdapter.php`, `tests/stubs/OpenRegister/Lifecycle/LifecycleGuardInterface.php`, `tests/stubs/OpenRegister/Lifecycle/GuardResult.php`, `phpstan.neon`, `psalm.xml`
- **acceptance_criteria**:
  - GIVEN a guard object with a passing `bool` method WHEN the adapter's `check()` runs THEN it returns `GuardResult::allow()`
  - GIVEN a guard object whose method returns `false` WHEN `check()` runs THEN it returns `GuardResult::deny($message)`
  - GIVEN the wrapped method throws WHEN `check()` runs THEN it logs and returns `GuardResult::deny($message)` (fail-closed)
- [x] Implement
- [x] Test

### Task 2: Implement the 15 new guard classes + PeriodCloseGuard::trialBalanceVerifies
- **spec_ref**: `openspec/changes/missing-lifecycle-guards/specs/missing-lifecycle-guards/spec.md#requirement-req-lcg-002`
- **files**: `lib/Guard/Iv3XmlValidationGuard.php`, `lib/Guard/Iv3SubmissionGuard.php`, `lib/Guard/KorLockoutGuard.php`, `lib/Guard/ProjectActivationGuard.php`, `lib/Guard/ProjectTransitionGuard.php`, `lib/Guard/ProjectCloseGuard.php`, `lib/Lifecycle/FiscalYearGuard.php`, `lib/Lifecycle/GLReversalGuard.php`, `lib/Lifecycle/WriteOffReasonGuard.php`, `lib/Guard/VatSubmissionGuard.php`, `lib/Guard/BcfSubmissionGuard.php`, `lib/Lifecycle/APGuard.php`, `lib/Lifecycle/WBSOExportValidationGuard.php`, `lib/Lifecycle/PeriodCloseGuard.php`
- **acceptance_criteria**:
  - GIVEN each guard's documented bad-path input THEN it returns `false`
  - GIVEN each guard's documented good-path input THEN it returns `true`
  - GIVEN a lookup exception THEN the guard fails closed (returns `false`)
- [x] Implement
- [x] Test

### Task 3: Fix SubsidieRepaymentGuard shape bug and RateScheduleOverlapGuard relocation
- **spec_ref**: `openspec/changes/missing-lifecycle-guards/specs/missing-lifecycle-guards/spec.md#requirement-req-lcg-003`
- **files**: `lib/Guard/SubsidieRepaymentGuard.php`, `lib/Guard/RateScheduleOverlapGuard.php`, `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN Subsidie's `afhandelenVanuitTeruggevorderd` transition THEN its `requires` value is a plain string, not an object
  - GIVEN an outstanding RepaymentInstallment THEN `requireZeroRepaymentBalance()` denies the close
  - GIVEN RateSchedule's `reactivate` transition THEN it carries a real `requires` (relocated off the dead `preconditions.save` key) and `requireNonOverlappingWindow()` denies an overlapping active sibling
- [x] Implement
- [x] Test

### Task 4: Register all 16 fixed tags in Application.php + remove 2 dead declarations
- **spec_ref**: `openspec/changes/missing-lifecycle-guards/specs/missing-lifecycle-guards/spec.md#requirement-req-lcg-004`
- **files**: `lib/AppInfo/Application.php`, `lib/Settings/shillinq_register.json`, `lib/Settings/register.d/add-shillinq-rekenkamer-audit-pack.json`
- **acceptance_criteria**:
  - GIVEN Application.php WHEN inspected THEN it contains a `registerService()` call for each of the 16 fixed tags
  - GIVEN the RateSchedule `resolveRate` aggregation THEN it no longer declares a `fallbackGuard` key
  - GIVEN the rekenkamer-audit-pack `steekproef` aggregation THEN it no longer declares a `guard` key
- [x] Implement
- [x] Test

### Task 5: Regression-guard test across register.d + Application.php
- **spec_ref**: `openspec/changes/missing-lifecycle-guards/specs/missing-lifecycle-guards/spec.md#requirement-req-lcg-001`
- **files**: `tests/Unit/Settings/RegisterLifecycleGuardsResolveTest.php`
- **acceptance_criteria**:
  - GIVEN every `x-openregister-lifecycle` transition across the monolith + register.d fragments THEN every FQCN-shaped `requires` value resolves to an existing class (+ method, when named)
  - GIVEN the 16 tags this change fixes THEN each one appears as a literal `registerService()` registration in Application.php
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — 54 new tests, full suite green (3532 tests, 0 failures)
- New/changed API endpoints covered by Newman/Postman tests — N/A, no new HTTP endpoints
- UI changes covered by Playwright browser tests — N/A, no UI surface (see `@e2e exclude` on every requirement)
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` in the PHP 8.3 container) — confirmed
- Feature documentation updated in `docs/` if user-facing (ADR-010) — N/A, internal lifecycle-guard wiring, no user-facing feature
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — N/A, deny messages are operator-facing backend strings, no new translated UI copy
- `openspec validate` passes — confirmed
