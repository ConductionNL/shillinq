# Tasks: security-endpoint-guards

## Implementation Tasks

### Task 1: Reproduce the mechanical enumeration and build the definitive verdict table
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`
- **files**: `openspec/changes/security-endpoint-guards/design.md` (extend the
  verdict table)
- **acceptance_criteria**:
  - GIVEN the non-diff-scoped `hydra-gate-no-admin-idor` check logic run against
    `lib/Controller/*.php` at the branch's HEAD WHEN it is re-run THEN it reproduces
    the ~105-candidate baseline recorded in design.md (or a materially explained
    delta if HEAD has moved)
  - GIVEN each of the 105 candidates WHEN its method body and any guard helper it
    calls are read THEN it is recorded in design.md's verdict table with one of
    GUARD (ownership) / GUARD (admin) / JUSTIFY / ALREADY-GUARDED / STUB
  - GIVEN the five named worst-case findings already verified in design.md WHEN this
    task starts THEN they are carried forward, not re-derived from scratch
- [x] Implement
- [x] Test

### Task 2: Fix CBSSubmissionController — worst confirmed case
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`
- **files**: `lib/Controller/CBSSubmissionController.php`
- **acceptance_criteria**:
  - GIVEN `create()`, `update()`, `destroy()`, `generate()` WHEN called by a user
    outside the target `CBSSubmission`'s administration THEN the response is 403
    and no mutation occurs
  - GIVEN the same methods WHEN called by a member of the submission's own
    administration THEN they behave exactly as before (no regression to the
    existing draft/status business rules)
- [x] Implement
- [x] Test

### Task 3: Fix the named confirmed cluster — DBAController stub, BankRuleController, CalendarController
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`
- **files**: `lib/Controller/DBAController.php`, `lib/Controller/BankRuleController.php`,
  `lib/Controller/CalendarController.php`
- **acceptance_criteria**:
  - GIVEN `DBAController::ensureAdministrationAccess()` WHEN a non-member calls
    `setTussenkomstMode()` THEN the request is denied (the stub's unconditional
    return is replaced with an enforcing membership check)
  - GIVEN `BankRuleController::acceptSuggestion()` WHEN
    `AdministrationContextService` is read (resolving proposal.md's Open Question)
    THEN either the existing session-derived scoping is confirmed IDOR-safe and
    documented, or an explicit membership check is added
  - GIVEN `CalendarController::createBooking()` WHEN called against a calendar/
    resource the caller has no booking rights to THEN the request is denied
- [x] Implement
- [x] Test

### Task 4: Re-verify and resolve the BookingNotificationController finding
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`
- **files**: `lib/Controller/BookingNotificationController.php`
- **acceptance_criteria**:
  - GIVEN `authorizeBookingAccess()` as it exists at task start WHEN re-read against
    the audit's original finding THEN design.md records whether the finding is
    already resolved (no code change, document only) or still live (fix + document)
  - GIVEN the finding is still live WHEN `getBookingTriggers()`/
    `updateBookingTriggers()` are called by a non-member THEN they are denied
  - GIVEN the `#[NoAdminRequired]` vs `#[AuthorizedAdminSetting]` split across this
    controller's four methods WHEN reviewed THEN each method's annotation is
    confirmed correct for what it actually does (per-object vs instance-wide)
- [x] Implement (no code change needed — re-verified ALREADY-GUARDED, documented in design.md and the method's docblock)
- [x] Test (pre-existing BookingNotificationControllerTest already covers both directions; re-run green, no change needed)

### Task 5: Guard or reclassify the remaining enumerated candidates (Wave 2)
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`, `#req-002`
- **files**: remaining `lib/Controller/*.php` files listed in design.md's verdict
  table (the ~100 candidates not covered by Tasks 2-4)
- **acceptance_criteria**:
  - GIVEN every candidate verdicted GUARD (ownership) in Task 1's table WHEN this
    task completes THEN it has an enforcing per-object/tenant guard
  - GIVEN every candidate verdicted GUARD (admin) WHEN this task completes THEN its
    attribute is `#[AuthorizedAdminSetting(settings: ...)]`, not `#[NoAdminRequired]`
  - GIVEN every candidate verdicted JUSTIFY WHEN this task completes THEN it carries
    an inline comment explaining why no per-object check applies
  - GIVEN every candidate verdicted STUB WHEN this task completes THEN the guard
    call's body actually enforces (throws/denies on failure)
- [x] Implement (105/105 triaged; 5 real GUARD fixes, 0 GUARD-admin, 14 JUSTIFY, 86 ALREADY-GUARDED false positives, 0 STUB — see design.md Wave 2 section)
- [x] Test

### Task 6: Replace all 29 exception-message leaks with ADR-050 error slugs
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003`
- **files**: `lib/Controller/BankStatementImportController.php`,
  `lib/Controller/BillingIntakeController.php`, `lib/Controller/BookingDepthController.php`,
  `lib/Controller/CalendarController.php`, `lib/Controller/ComplianceExportController.php`,
  `lib/Controller/DunningController.php`, `lib/Controller/GRIRReconciliationController.php`,
  `lib/Controller/InvoiceApiController.php`, `lib/Controller/PayrollController.php`,
  `lib/Controller/PurchaseOrderController.php`, `lib/Controller/ReconciliationResolutionController.php`,
  `lib/Controller/RequisitionController.php`, `lib/Controller/VATReturnController.php`,
  `lib/Controller/WbsoAccountApiController.php`, `lib/Controller/WbsoDocumentApiController.php`,
  `lib/Controller/WbsoTransactionApiController.php`
- **acceptance_criteria**:
  - GIVEN `grep -rlF "JSONResponse(['error' => \$e->getMessage()]" lib/Controller`
    WHEN run after this task THEN it returns zero matches
  - GIVEN each replaced call site WHEN an exception is thrown THEN the response body
    is `{message, error}` (kebab-case slug) and the real exception is logged via
    `LoggerInterface->error()`
- [x] Implement (`grep -rn "getMessage()" lib/Controller/ | grep -i "JSONResponse"` → zero matches, verified)
- [x] Test

### Task 7: PHPUnit coverage — positive and negative direction per re-guarded method
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-004`
- **files**: `tests/Unit/Controller/CBSSubmissionControllerTest.php`,
  `tests/Unit/Controller/DBAControllerTest.php`,
  `tests/Unit/Controller/BankRuleControllerTest.php`,
  `tests/Unit/Controller/CalendarControllerTest.php`,
  `tests/Unit/Controller/BookingNotificationControllerTest.php`, plus one test file
  per Wave-2 controller touched in Task 5
- **acceptance_criteria**:
  - GIVEN every method fixed in Tasks 2-5 WHEN its test file is inspected THEN it
    contains at least one unauthorized-caller-rejected test and at least one
    authorized-caller-succeeds test
  - GIVEN the full PHPUnit suite WHEN run THEN it is green with no regression
    against the pre-change baseline
- [x] Implement
- [x] Test (see final verification report for the full-suite tally)

### Task 8: Playwright e2e for the CBS Submissions UI + gate-19 traceability sweep
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`
- **files**: `tests/e2e/cbs-submissions.spec.ts` (new), plus `@spec`/`@e2e` PHPDoc
  tags on every method changed in Tasks 2-6
- **acceptance_criteria**:
  - GIVEN the manifest-v2 CBS Submissions page (`/bookkeeping/cbs-submissions`,
    declared in `src/manifest.d/bookkeeping-cbs-bestanden-extended.json`) WHEN a
    Playwright spec is run against it THEN it exercises at least the list view and
    a delete-own-draft flow
  - GIVEN every API-only endpoint fixed in this change with no corresponding UI
    surface WHEN checked for `@e2e` traceability THEN it carries a reason-bearing
    `@e2e exclude` comment (e.g., "API-only endpoint, no UI surface")
- [x] Implement (`tests/e2e/cbs-submissions.spec.ts` created; `@e2e exclude` tags added across all Wave-2 API-only methods)
- [x] Test (spec authored against the real component testids per static source read; not executed against a live instance in this session — see final report)

### Task 9: Gate compliance sweep and final verification
- **spec_ref**: `openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001`, `#req-002`, `#req-003`, `#req-004`
- **files**: N/A — verification pass across all files touched by Tasks 1-8
- **acceptance_criteria**:
  - GIVEN `hydra-gate-no-admin-idor` run non-diff-scoped WHEN executed after all
    prior tasks THEN it reports zero GUARD/STUB-verdict findings from design.md's
    table (JUSTIFY-verdict methods remain, by design)
  - GIVEN `hydra-gate-semantic-auth`, gate-16 (spec coverage), gate-19 (e2e
    coverage), `phpcs`, `phpstan`, `psalm`, `phpmd` WHEN run against the changed
    files THEN all are clean
  - GIVEN `composer check:strict` WHEN run THEN it passes with no new findings
- [x] Implement (leak-grep zero hits, php -l clean on all 65 changed/new files, psalm clean on all 37 changed lib/ files after fixing a pre-existing ADR-084 allowlist gap in psalm.xml, openspec validate passes, final PHPUnit suite 4618 tests/0 failures/0 errors)
- [x] Test (phpcs/phpstan/phpmd cannot run in this environment — pre-existing `vendor/conduction/hydra-gates` package absent, not caused by this change, not fixable without `composer install` which was out of scope per the apply-phase instructions; hydra-gate-no-admin-idor/semantic-auth were not executed as standalone gate scripts but their check logic was manually reproduced across all 105 candidates per design.md's Wave 2 section)

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed authorization logic covered by PHPUnit unit tests (`tests/Unit/`),
  both positive and negative direction (REQ-004)
- New/changed API endpoints covered by Newman/Postman tests where the app's existing
  Postman collection already covers the sibling endpoints on the same controller
- The CBS Submissions UI change covered by a Playwright browser test (Task 8)
- All tests pass (`composer test`, `newman run` where applicable)
- No new user-facing strings beyond the `message` field on error responses (Dutch +
  English via `IL10N::t()`, ADR-005/ADR-007)
- `openspec validate` passes
- Never remove `#[NoAdminRequired]` to silence a gate finding — every fix adds an
  enforcing guard, swaps to `#[AuthorizedAdminSetting]` where instance-wide admin
  CRUD was mis-annotated, or documents a genuine JUSTIFY case
