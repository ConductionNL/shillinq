# Tasks: fx-period-end-revaluation

## Implementation Tasks

### Task 1: FxRevaluationPosting register schema (declarative)
- **spec_ref**: `openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md#req-mc-008`
- **files**: `lib/Settings/register.d/fx-period-end-revaluation.json`
- **acceptance_criteria**:
  - GIVEN the register fragment WHEN loaded THEN `FxRevaluationPosting` is declared with the fields listed in REQ-MC-008, an `x-openregister-lifecycle` (posted → reversed) matching the `AutoAccrualPosting` precedent, and `x-openregister-audit-trail: true`
  - GIVEN the fragment WHEN loaded THEN it carries the standard `_meta` block (spdx-license, spdx-copyright, change, adr: ADR-037) so it never collides with `shillinq_register.json`
- [x] Implement
- [x] Test

### Task 2: FxRevaluationService — closing-rate resolution + revaluation math
- **spec_ref**: `openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md#req-mc-006`
- **files**: `lib/Service/Treasury/FxRevaluationService.php`
- **acceptance_criteria**:
  - GIVEN an `FXPosition` with a prior mark WHEN `reval()` runs and a rate resolves THEN `fairValue`/`unrealisedPL`/`spotRate` update per REQ-MC-006 and a posting is created only when the delta is material
  - GIVEN a position with no prior mark WHEN `reval()` runs THEN only a baseline is established and no posting is created (REQ-MC-006 scenario 2)
- [x] Implement
- [x] Test

### Task 3: Closing-rate fallback (live → manual FXPosition.spotRate → skip)
- **spec_ref**: `openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md#req-mc-007`
- **files**: `lib/Service/Treasury/FxRevaluationService.php`
- **acceptance_criteria**:
  - GIVEN a dormant `TreasuryRateService` snapshot and a manually-maintained `FXPosition.spotRate` WHEN `reval()` runs THEN the manual value is used and `rateSource: "manual-fallback"` is recorded (REQ-MC-007 scenario 1)
  - GIVEN no live snapshot and no manual `spotRate` WHEN `reval()` runs THEN the position is skipped without throwing and the remaining positions are still processed (REQ-MC-007 scenario 2)
- [x] Implement
- [x] Test

### Task 4: FxRevaluationPosting persistence + GL account attribution + SoftCloseExecutor delegate contract
- **spec_ref**: `openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md#req-mc-008`
- **files**: `lib/Service/Treasury/FxRevaluationService.php`
- **acceptance_criteria**:
  - GIVEN a material revaluation WHEN posted THEN the `FxRevaluationPosting` carries `targetGLAccount`/`contraGLAccount` resolved from the `fx_revaluation_gain_account`/`fx_revaluation_loss_account`/`fx_revaluation_adjustment_account` `IAppConfig` keys (documented defaults) and `postedBy: FxRevaluationService::SYSTEM_ACTOR`
  - GIVEN `reval(administrationId, periodId): array` WHEN called by `SoftCloseExecutor::delegateFxRevaluation()` THEN the return shape satisfies `array{postingCount: int, ...}` exactly as that existing call-site already reads it (no `SoftCloseExecutor` code change required)
- [x] Implement
- [x] Test

### Task 5: Spec + capability documentation
- **spec_ref**: `openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md`
- **files**: `openspec/specs/bookkeeping-multi-currency/spec.md`
- **acceptance_criteria**:
  - GIVEN the merged spec WHEN read THEN REQ-MC-006/007/008 are present with the `fx-period-end-revaluation` change listed in `OpenSpec changes` and `Status: in-progress` during this change (flipped to `done` at archive)
- [x] Implement
- [x] Test

### Task 6: SoftCloseExecutor correctness proof — fxPostings > 0
- **spec_ref**: `openspec/changes/fx-period-end-revaluation/specs/bookkeeping-multi-currency/spec.md#req-mc-008`
- **files**: `tests/Unit/Service/SoftCloseExecutorTest.php`, `tests/Unit/Service/Treasury/FxRevaluationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a container that resolves `FxRevaluationService` to a delegate with a material posting WHEN `SoftCloseExecutor::execute()` runs THEN the report's `fxPostings` is `> 0` — proving the previously-permanent `return 0` (`container->has() === false`) branch is no longer taken
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off and `openspec validate` passes
- [x] `php -l` clean on every changed PHP file; PHPUnit run against the real NC container (OCP), delta-zero against the documented pre-existing baseline

## Quality checklist

- [x] All new business logic covered by PHPUnit unit tests (`tests/Unit/Service/Treasury/FxRevaluationServiceTest.php`, extended `SoftCloseExecutorTest.php`)
- [x] `phpcs` / `phpstan` clean on changed paths (or pre-existing baseline unchanged)
- [x] No new frontend/manifest surface — no new i18n keys introduced
- [x] `openspec validate fx-period-end-revaluation --type change --strict` passes
