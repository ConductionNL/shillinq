# Tasks: btw-suppletie-detection

## Implementation Tasks

### Task 1: Additive `VatCorrection` register fields + seed
- **spec_ref**: `openspec/changes/btw-suppletie-detection/specs/bookkeeping-vat-btw-filing/spec.md#req-vbtw-014`
- **files**: `lib/Settings/register.d/btw-suppletie-detection.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded THEN `VatCorrection` gains `filedSnapshot`, `currentSnapshot`, `rubriekDeltas`, `detectedAt`, `preparedAt`, `thresholdExceeded`, `filingDeadline`, `glCorrectionTransactionId` (all additive, nullable)
  - GIVEN REQ-VBTW-012 audit coverage is already satisfied by `add-shillinq-audit-trail.json`'s `x-openregister-audit-trail.enabled` flag THEN this fragment does not redeclare it
  - GIVEN install seed THEN a `thresholdExceeded: true` and a `thresholdExceeded: false` example `VatCorrection` exist
- [x] Implement
- [x] Test

### Task 2: Non-mutating GL recompute on `VATReturnService`
- **spec_ref**: `openspec/changes/btw-suppletie-detection/specs/bookkeeping-vat-btw-filing/spec.md#req-vbtw-013`
- **files**: `lib/Service/VATReturnService.php`
- **acceptance_criteria**:
  - GIVEN a filed `VATReturn` id WHEN `computeCurrentDeclarations()` runs THEN it returns the same `type:taxRate` grouped totals `deriveVATLines()` would persist, without writing any `VATLine`/`VATDeclaration`/`VATReturn` record
- [x] Implement
- [x] Test

### Task 3: `VatSuppletieDetectionService::detect()` — drift detection
- **spec_ref**: `openspec/changes/btw-suppletie-detection/specs/bookkeeping-vat-btw-filing/spec.md#req-vbtw-013`
- **files**: `lib/Service/VatSuppletieDetectionService.php`
- **acceptance_criteria**:
  - GIVEN a filed `VATReturn` whose GL has drifted WHEN `detect()` runs THEN a `draft` `VatCorrection` is created with `filedSnapshot` + `currentSnapshot` populated and `preparedAt` null
  - GIVEN a filed `VATReturn` with no drift WHEN `detect()` runs THEN no `VatCorrection` is created
- [x] Implement
- [x] Test

### Task 4: `VatSuppletieDetectionService::prepare()` — deltas, €1.000 grens, deadline, and GL correction posting
- **spec_ref**: `openspec/changes/btw-suppletie-detection/specs/bookkeeping-vat-btw-filing/spec.md#req-vbtw-014`
- **files**: `lib/Service/VatSuppletieDetectionService.php`
- **acceptance_criteria**:
  - GIVEN a `detected` `VatCorrection` WHEN `prepare()` runs THEN `rubriekDeltas`, net `correctionAmount`/`adjustmentAmount` (both field-name variants), `thresholdExceeded` (true when `abs(amount) >= 1000`), `filingDeadline` (`preparedAt + P8W`) are set
  - GIVEN a below-grens delta WHEN `prepare()` runs THEN `thresholdExceeded` is false but the correction is still fully compiled
  - GIVEN `prepare()` completes THEN a `draft` `GLTransaction` with balanced `GLLine`s (one per non-zero rubriek delta, against the rubriek's original account, offset by the clearing account) is created and linked via `glCorrectionTransactionId`, and is never auto-transitioned to `posted`
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no new HTTP endpoint (service-layer only, consumed via a future controller/workflow out of scope)
- [ ] Browser tests (Playwright MCP) for UI changes — N/A, no new UI (existing `VatCorrection` manifest pages already surface the records)
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` — N/A, backend detection engine with no new operator-facing surface beyond the already-documented `VatCorrection` pages
- [ ] Screenshot captured and committed to `docs/images/` — N/A, no UI change

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A, no new user-facing strings (all new fields render through the existing generic OR object-detail UI using schema `title`/`description`, already NL/EN per the register)
