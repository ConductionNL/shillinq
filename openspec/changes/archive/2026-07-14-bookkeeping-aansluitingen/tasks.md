# Tasks: bookkeeping-aansluitingen

## Implementation Tasks

### Task 1: Declare the `Aansluiting` + `AansluitingResult` schemas

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-001](specs/bookkeeping-aansluitingen/spec.md#req-aans-001)
**files:** `lib/Settings/register.d/bookkeeping-aansluitingen.json`,
`tests/Unit/Settings/AansluitingenFragmentTest.php`
**acceptance_criteria:**
- GIVEN the register fragment WHEN merged onto the monolith THEN exactly
  two new schemas (`Aansluiting`, `AansluitingResult`) are added and every
  pre-existing schema survives unchanged (ADR-037).
- GIVEN `AansluitingResult` WHEN inspected THEN it declares the
  `open -> explained -> resolved` `x-openregister-lifecycle`, the
  `AansluitingResolutionGuard::canResolve` guard on `resolve`, the
  `openCountByAdministration`/`openCountByAansluiting`
  `x-openregister-aggregations`, and `x-openregister-audit-trail.enabled`.

- [x] Implement
- [x] Test

### Task 2: `AansluitingCalculator` — pure tolerance/diff engine

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-003](specs/bookkeeping-aansluitingen/spec.md#req-aans-003)
**files:** `lib/Service/AansluitingCalculator.php`,
`tests/Unit/Service/AansluitingCalculatorTest.php`
**acceptance_criteria:**
- GIVEN sourceATotal/sourceBTotal and `expectedRelationship='equal'` WHEN
  `differenceCents()` runs THEN it returns `toCents(A) - toCents(B)`; GIVEN
  `'equal-with-sign-flip'` THEN it returns `toCents(A) + toCents(B)`.
- GIVEN a difference and a tolerance WHEN `isWithinTolerance()` runs THEN it
  returns true iff `abs(difference) <= tolerance`.
- GIVEN two bucket maps WHEN `diffBuckets()` runs THEN it emits one row per
  union key with `null` on the side missing a bucket.

- [x] Implement
- [x] Test

### Task 3: `AansluitingService` framework — compute/explain/resolve/reopen

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-004](specs/bookkeeping-aansluitingen/spec.md#req-aans-004)
**files:** `lib/Service/AansluitingService.php`,
`tests/Unit/Service/AansluitingServiceTest.php`
**acceptance_criteria:**
- GIVEN an `Aansluiting` definition and a period WHEN `compute()` runs THEN
  it dispatches on `aansluitingType`, persists an `AansluitingResult`, and
  sets `status='resolved'` (auto, `resolvedBy='system'`) when within
  tolerance or `status='open'` otherwise.
- GIVEN an existing result already `explained` or `resolved` WHEN
  `compute()` runs again THEN the existing record is returned unchanged
  (never silently overwrites an operator's explanation).
- GIVEN an open result WHEN `explain()` runs with a non-blank reason THEN
  status becomes `explained`; GIVEN a blank reason THEN it throws.
- GIVEN an explained result satisfying the guard WHEN `resolve()` runs THEN
  status becomes `resolved`; GIVEN a non-explained result THEN it throws.
- GIVEN an explained or resolved result WHEN `reopen()` runs THEN status
  becomes `open` and the reason is recorded.

- [x] Implement
- [x] Test

### Task 4: Resolver — btw-ledger-aangifte

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-002](specs/bookkeeping-aansluitingen/spec.md#req-aans-002)
**files:** `lib/Service/AansluitingService.php` (`resolveBtwLedgerAangifte()`,
`findFiledVatReturn()`, `findRelatedVatCorrection()`),
`tests/Unit/Service/AansluitingServiceTest.php`
**acceptance_criteria:**
- GIVEN a filed `VATReturn` for the administration + period WHEN the
  resolver runs THEN sourceA = `VATReturnService::computeCurrentDeclarations()`
  totals, sourceB = `::fetchFiledDeclarations()` totals, and `lineDeltas`
  carries one row per `type:taxRate` rubriek bucket.
- GIVEN no filed `VATReturn` exists for the period WHEN the resolver runs
  THEN `compute()` throws (no silent zero-total result).
- GIVEN a `VatCorrection` already exists referencing the same `VATReturn`
  WHEN the resolver runs THEN `relatedVatCorrectionId` is populated with it
  (REQ-AANS-007 — integrate, don't duplicate the suppletie flow).

- [x] Implement
- [x] Test

### Task 5: Resolver — subledger-gl-control

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-005](specs/bookkeeping-aansluitingen/spec.md#req-aans-005)
**files:** `lib/Service/AansluitingService.php` (`resolveSubledgerGlControl()`,
`controlAccountBalance()`, `openArInvoices()`, `openApTransactions()`),
`tests/Unit/Service/AansluitingServiceTest.php`
**acceptance_criteria:**
- GIVEN `controlAccountNumber` + `subLedgerType` on the definition WHEN the
  resolver runs THEN sourceA = the account's all-time cumulative GL balance
  and sourceB = the sum of open `ARInvoice`/`APTransaction` records for the
  administration; paid/written-off/voided items are excluded.
- GIVEN `subLedgerType='ap'` and `expectedRelationship='equal-with-sign-flip'`
  WHEN the resolver runs on a genuinely balanced position THEN
  `differenceCents` is 0 despite sourceA and sourceB carrying opposite
  signs.
- GIVEN a genuine drift WHEN the resolver runs THEN `lineDeltas` carries one
  row per open subledger item contributing to sourceB (drill-down).

- [x] Implement
- [x] Test

### Task 6: `AansluitingResolutionGuard` lifecycle guard

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-006](specs/bookkeeping-aansluitingen/spec.md#req-aans-006)
**files:** `lib/Lifecycle/AansluitingResolutionGuard.php`,
`tests/Unit/Lifecycle/AansluitingResolutionGuardTest.php`
**acceptance_criteria:**
- GIVEN a result with status `explained` and non-blank
  `explanationReasonText` WHEN `canResolve()` runs THEN it returns true.
- GIVEN status is not `explained`, OR `explanationReasonText` is blank/
  missing, OR any internal exception occurs WHEN `canResolve()` runs THEN
  it returns false (fail-closed, CWE-863).

- [x] Implement
- [x] Test

### Task 7: `AansluitingController` + routes

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-004](specs/bookkeeping-aansluitingen/spec.md#req-aans-004)
**files:** `lib/Controller/AansluitingController.php`, `appinfo/routes.php`,
`tests/Unit/Controller/AansluitingControllerTest.php`
**acceptance_criteria:**
- GIVEN an unauthenticated request WHEN any endpoint is called THEN HTTP
  401 is returned.
- GIVEN a missing/malformed `period_id`, `aansluitingId`, or `resultId` WHEN
  compute/explain/resolve/reopen is called THEN HTTP 400 is returned.
- GIVEN a service exception WHEN any endpoint is called THEN HTTP 500 is
  returned without leaking the exception message to the client.
- GIVEN a valid request WHEN explain/resolve/reopen is called THEN the
  acting user id (`IUserSession::getUser()->getUID()`) is forwarded as the
  actor, never a client-supplied value.

- [x] Implement
- [x] Test

### Task 8: Manifest navigation — Aansluitingen index/detail + Resultaten index/detail

**spec_ref:** [openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md#req-aans-008](specs/bookkeeping-aansluitingen/spec.md#req-aans-008)
**files:** `src/manifest.json`
**acceptance_criteria:**
- GIVEN the manifest WHEN loaded THEN `Bookkeeping > Aansluitingen` appears
  in navigation and renders an index of `Aansluiting` definitions with a
  detail page.
- GIVEN an `Aansluiting` detail page WHEN an operator drills through THEN
  an `AansluitingResultaten` index (filterable by `status`) with a detail
  page showing `sourceATotal`/`sourceBTotal`/`differenceCents`/`lineDeltas`
  and lifecycle action buttons is reachable.
- GIVEN both detail pages WHEN rendered THEN only generic
  `@conduction/nextcloud-vue` components are used (`CnIndexPage`/
  `CnDetailPage` + the standard audit-trail sidebar tab) — no bespoke Vue.

- [x] Implement
- [x] Test

## Quality checklist

- All new PHP classes carry `@spec` docblock tags pointing at this change's
  spec (`AansluitingCalculator`, `AansluitingService`,
  `AansluitingResolutionGuard`, `AansluitingController`) — gate-16
  spec-coverage.
- All new/changed logic is covered by PHPUnit unit tests
  (`tests/Unit/Service/AansluitingCalculatorTest.php`,
  `tests/Unit/Service/AansluitingServiceTest.php`,
  `tests/Unit/Lifecycle/AansluitingResolutionGuardTest.php`,
  `tests/Unit/Controller/AansluitingControllerTest.php`,
  `tests/Unit/Settings/AansluitingenFragmentTest.php`) — no mocked-away
  business logic (ADR-009).
- No `lib/Service/*Report*Service.php`/`*ReportBuilder*` files introduced —
  reviewer confirmed by grepping `lib/Service/` after implementation (ADR-031
  anti-pattern check, same discipline as `bookkeeping-trial-balance`
  REQ-TB-001 and `bookkeeping-reconciliation-reports` REQ-REC-007).
- `AansluitingController` routes are `#[NoAdminRequired]` + IDOR-safe
  (re-fetch-then-check-then-mutate, no client-supplied `administrationId`
  on any write) — gate-7 no-admin-idor.
- Newman/Postman API suite: N/A — no public-facing REST contract change
  beyond the four new `#[NoAdminRequired]` endpoints, covered by
  `AansluitingControllerTest.php` at the unit level.
- Playwright/E2E: N/A for this change — manifest pages use only generic
  renderer components already covered by the shared `CnIndexPage`/
  `CnDetailPage` E2E suite; `@e2e exclude` noted on the spec's scenarios.
- i18n (company-wide ADR-005): N/A — no new user-facing strings beyond
  manifest field labels, which are already Dutch-first per the existing
  `src/manifest.json` convention for this app; no hardcoded English UI
  copy introduced.
- Documentation (company-wide ADR-010): N/A for this change — user-guide
  page `docs/bookkeeping/aansluitingen.md` deferred to the same follow-up
  issue as the four deferred aansluitingen (avoids documenting a
  half-populated feature set before the follow-up lands).
