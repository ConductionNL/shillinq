# security-endpoint-guards Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- security-endpoint-guards

## Purpose

Establishes and enforces the authorization posture every `#[NoAdminRequired]`
controller method in `lib/Controller/` must carry (ADR-005 Rule 3 / OWASP A01:2021),
and the error-response shape every controller method must use when translating a
caught exception into an HTTP response (ADR-050). Closes a completed audit's 64
confirmed missing-guard findings and 29 confirmed exception-message leaks. Does not
build ADR-023's action↔group RBAC matrix — see Notes.

## ADDED Requirements

### Requirement: REQ-001: Every `#[NoAdminRequired]` method SHALL carry an enforced authorization guard

Every controller method annotated `#[NoAdminRequired]` / `@NoAdminRequired` MUST
either (a) verify the authenticated caller owns, is a member of the administration/
tenant of, or is an admin for the specific object/resource the request names, before
performing any read beyond existence-check or any mutation, or (b) carry an inline
code comment justifying why the method is intentionally reachable by any
authenticated user with no per-object check. A guard that is present syntactically
(a method call shaped like `authorize*`/`require*`/`ensure*`) but whose body does
not actually deny unauthorized callers does NOT satisfy this requirement — the guard
MUST enforce, not merely exist.

#### Scenario: A user cannot delete another organization's CBS submission

- **GIVEN** two authenticated users, A and B, in different administrations, and a
  `CBSSubmission` object in `draft` status owned by administration A
- **WHEN** user B calls `DELETE /api/cbs-submissions/{id}` naming administration A's
  submission
- **THEN** the response is 403 Forbidden and the submission is NOT deleted

#### Scenario: A user can delete their own administration's draft CBS submission

- **GIVEN** an authenticated user A who is a member of administration A, and a
  `CBSSubmission` object in `draft` status owned by administration A
- **WHEN** user A calls `DELETE /api/cbs-submissions/{id}` naming that submission
- **THEN** the response is 200 and the submission is deleted

#### Scenario: A guard call that never denies is treated as no guard

- **GIVEN** a controller method body containing a call to a helper named
  `ensure*`/`authorize*`/`require*`
- **WHEN** that helper's implementation cannot throw, return an error response, or
  otherwise deny the request under any input
- **THEN** the method is classified as ungated and MUST be fixed with an enforcing
  guard, regardless of the helper's name

### Requirement: REQ-002: Instance-wide administrative CRUD SHALL use `#[AuthorizedAdminSetting]`, not `#[NoAdminRequired]`

A controller method that mutates instance-wide administrative state (not scoped to
a single user's or administration's own objects) MUST be annotated
`#[AuthorizedAdminSetting(settings: <AdminSettingsClass>::class)]` (or the
positional `#[AuthorizedAdminSetting(Application::APP_ID)]` form), never
`#[NoAdminRequired]`.

#### Scenario: An admin-only monitoring endpoint rejects a non-admin caller

- **GIVEN** an authenticated user who is not a Nextcloud admin
- **WHEN** they call an endpoint annotated `#[AuthorizedAdminSetting]`
- **THEN** the framework middleware rejects the request before the controller body
  runs

### Requirement: REQ-003: Exception responses SHALL NOT leak raw exception text

No controller method may return `new JSONResponse(['error' => $e->getMessage()], ...)`
or any equivalent shape that places the caught `\Throwable`'s message text directly
in the HTTP response body. Every caught exception translated to an error response
MUST return a flat `{message, error}` body per ADR-050 — `message` MUST be a static,
localized, human-readable string (`IL10N::t()`), `error` MUST be a stable
machine-readable kebab-case slug — and the real exception MUST be logged
server-side via `LoggerInterface->error()`.

#### Scenario: A failed CBS submission deletion returns a slug, not exception text

- **GIVEN** `CBSSubmissionController::destroy()` catches a `\Throwable` while
  deleting the underlying object
- **WHEN** the exception is translated to an HTTP response
- **THEN** the response body is `{"message": "<localized text>", "error": "cbs-submission-delete-failed"}`
  and contains no fragment of `$e->getMessage()`
- **AND** the real exception is logged via `$this->logger->error(...)`

#### Scenario: No response body across the fixed 16 controllers contains raw exception text

- **GIVEN** the 16 controllers this change modifies
  (`BankStatementImportController`, `BillingIntakeController`,
  `BookingDepthController`, `CalendarController`, `ComplianceExportController`,
  `DunningController`, `GRIRReconciliationController`, `InvoiceApiController`,
  `PayrollController`, `PurchaseOrderController`,
  `ReconciliationResolutionController`, `RequisitionController`,
  `VATReturnController`, `WbsoAccountApiController`, `WbsoDocumentApiController`,
  `WbsoTransactionApiController`)
- **WHEN** `grep -rlF "JSONResponse(['error' => \$e->getMessage()]" lib/Controller`
  is run at the changed HEAD
- **THEN** it returns zero matches

### Requirement: REQ-004: Every re-guarded method SHALL have a positive-direction and negative-direction test

Every controller method whose authorization posture is added or changed by this
change MUST have PHPUnit coverage proving BOTH: an unauthorized caller is rejected,
AND the legitimate/authorized caller still succeeds. A negative-only test is
insufficient — it cannot distinguish "the guard works" from "the guard denies
everyone."

#### Scenario: A re-guarded method's test suite covers both directions

- **GIVEN** a controller method fixed by this change
- **WHEN** its `tests/Unit/Controller/*Test.php` coverage is inspected
- **THEN** it contains at least one test asserting a 403/rejection for an
  unauthorized caller AND at least one test asserting success (2xx, correct payload)
  for the authorized caller

## Non-Functional Requirements

- **Performance:** Guard checks add at most one additional `find()`/membership
  lookup per request; no method may introduce an N+1 pattern (e.g., looping a
  membership check per list item where a single scoped query would do).
- **Accessibility:** N/A — this change is API-layer only; no new UI is introduced
  beyond the existing CBS Submissions manifest-v2 page, which is unchanged in
  layout/markup.
- **Internationalization:** Dutch and English MUST be supported for every new
  `message` string introduced by REQ-003 (ADR-005 / ADR-007).

## Acceptance Criteria

- [ ] Every candidate from the non-diff-scoped `hydra-gate-no-admin-idor` scan
      (see design.md's enumeration methodology) carries a recorded verdict —
      GUARD, JUSTIFY, ALREADY-GUARDED, or STUB-fixed.
- [ ] `CBSSubmissionController::create/update/destroy/generate`,
      `DBAController::setTussenkomstMode`, `BankRuleController::acceptSuggestion`,
      and `CalendarController::createBooking` each carry an enforced guard.
- [ ] `DBAController::ensureAdministrationAccess()` no longer unconditionally
      returns without checking membership.
- [ ] `BookingNotificationController`'s finding is re-verified against HEAD and
      either confirmed already-fixed (documented) or fixed.
- [ ] All 29 confirmed `$e->getMessage()` leak sites return `{message, error}` with
      no raw exception text.
- [ ] `hydra-gate-no-admin-idor` run non-diff-scoped against `lib/Controller/`
      shows zero remaining GUARD/STUB-verdict findings that were in scope for this
      change (JUSTIFY-verdict methods remain, by design, with their justification
      comment).
- [ ] Every re-guarded method has both a rejection test and a success test.
- [ ] Playwright e2e coverage exists for the CBS Submissions UI surface
      (`/bookkeeping/cbs-submissions`), or a reason-bearing `@e2e exclude` is
      recorded for any API-only endpoint with no UI surface.

## Notes

- **ADR-023 out of scope**: this spec deliberately does not introduce
  `requireAction()` or an admin-configured action↔group matrix. The guard
  vocabulary here (per-object/tenant ownership, `#[AuthorizedAdminSetting]`,
  documented justification) is the narrower ADR-005 Rule 3 mechanism, sufficient
  to close the confirmed findings without new shared infrastructure. A future
  change may migrate some of these guards onto ADR-023's machinery once it exists
  for shillinq; that is not this change.
- **ADR-055 alignment**: this spec's REQ-001 "guard call that never denies" clause
  directly extends ADR-055's semantic-auth hard-fail principle (§4) — a guard-
  shaped call with no enforcing body is treated identically to no guard at all.
- Related ADRs: ADR-005 (security baseline), ADR-020 (gate scope = diff, does not
  limit this change's non-diff-scoped enumeration pass), ADR-050 (response
  envelope), ADR-055 (authorization gate extensions).
- See `openspec/changes/security-endpoint-guards/design.md` for the full
  enumeration methodology, the five-way verdict table, and the worked evidence for
  the named worst-case findings.
