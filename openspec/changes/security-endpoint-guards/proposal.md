---
kind: code
depends_on: []
---

# Proposal: security-endpoint-guards

## Summary

A completed security audit of shillinq's `lib/Controller/` found two confirmed,
verified defect classes: (1) 64 of 195 `#[NoAdminRequired]` controller methods carry
no per-object, tenant, or admin authorization guard in the method body (ADR-005 Rule
3 / OWASP A01:2021), and (2) 29 call sites across 16 controllers return the raw PHP
exception message (`$e->getMessage()`) to the HTTP client, leaking internal
implementation detail (ADR-050). This change enumerates every finding, applies the
ADR-005-correct guard posture per method (per-object ownership, admin-only, or a
documented open-to-all justification), replaces the 29 leaks with kebab-case error
slugs logged server-side, and adds PHPUnit + Playwright coverage proving each new
guard actually rejects the unauthorized case.

## Motivation

The worst confirmed instance is `CBSSubmissionController` — `create()`, `update()`,
`destroy()` (`:280`), and `generate()` operate on instance-wide statutory CBS
(Centraal Bureau voor de Statistiek) filings and are guarded only by a private
`requireUser()` helper that checks authentication, not authorization:

```php
// lib/Controller/CBSSubmissionController.php:92-97
/**
 * Authorization guard — every endpoint requires an authenticated
 * Nextcloud user (REQ-CBS-001 / ADR-005). The administration scope is
 * validated by the IDOR-safe service layer. This helper is the
 * in-body counterpart to #[NoAdminRequired] so gate-7 no-admin-idor /
 * gate-9 semantic-auth see the explicit auth posture.
 */
private function requireUser(): ?JSONResponse { ... }
```

`destroy()` (verified at `:280-326`) fetches the target `CBSSubmission` by `$id`,
checks only its `status` (`draft`), and calls `deleteObject($id)` — no ownership,
administration-membership, or admin check anywhere in the call chain. The docblock's
claim that "the administration scope is validated by the IDOR-safe service layer" is
false: `objectService()->setRegister(...)->setSchema('CBSSubmission')->find($id)` /
`->deleteObject($id)` perform no such validation. **Any authenticated user can delete
any other organization's draft statutory filing by guessing or enumerating its ID.**

A second, more insidious pattern was found in `DBAController::setTussenkomstMode()`
(`:356-385`): the method DOES call a named guard, `ensureAdministrationAccess()` —
but that guard is a documented stub:

```php
// lib/Controller/DBAController.php:699-714
private function ensureAdministrationAccess(array $assignment): void {
    ...
    // Real check would call AdministrationMembership::isMember($user, $administrationId).
    // Until the implementation cycle wires it, we log the guarded access.
    $this->logger->debug('DBA controller: administration access', [...]);
}
```

It never throws, never denies — it logs and returns unconditionally. A naive
mechanical scan for "a method call named `authorize*`/`require*`/`ensure*`" (the
exact heuristic `hydra-gate-no-admin-idor` uses) treats this as PASS. **This is why
the change requires a per-method code read, not a grep-and-add pass**: a syntactically
present guard is not evidence of an enforced one.

Also confirmed by the audit: `BankRuleController::acceptSuggestion()` (creates a
`MatchingRule` scoped to an administration resolved server-side, but the
apply phase must confirm that resolution path cannot be influenced by request
input) and `CalendarController::createBooking()` (guarded only by
`requireAuth()`, an authentication-only check — any authenticated user can book any
calendar/resource with no ownership check).

`BookingNotificationController` was flagged by the audit for mixing
`#[NoAdminRequired]` (`:89`, `:122`) with `#[AuthorizedAdminSetting]` (`:164`,
`:220`) on the same resource type (`Booking` / `BookingNotificationTrigger`). Our own
code read of `authorizeBookingAccess()` (`:272-`) found what looks like a genuine
per-object guard (admin bypass + `AdministrationContextService` membership check,
with a docblocked history of a prior `findObject()` vs `find()` bug that turned the
guard into a blanket 403). **We could not independently confirm this is still broken
as of HEAD — it may be that the audit's "confirmed" classification predates a fix, or
that the concern is really about the annotation split rather than a missing guard.**
This is flagged as an Open Question below rather than guessed at.

On the second defect class: 29 occurrences of
`new JSONResponse(['error' => $e->getMessage()], ...)` across 16 controllers
(confirmed via `grep -rlF "JSONResponse(['error' => \$e->getMessage()]" lib/Controller`)
return raw exception text — which can include database error fragments, file paths,
or third-party API error bodies — directly to any authenticated client.

## Affected Projects

- [x] Project: `shillinq` — 64 controller-method authorization guards, 29
      error-response hardenings, new PHPUnit + Playwright coverage. No other
      ConductionNL app is touched.

## Scope

### In Scope

- Mechanically enumerate every `#[NoAdminRequired]` / `@NoAdminRequired` controller
  method in `lib/Controller/` (195 total per the audit) and produce the definitive,
  human-triaged list of the ones lacking an enforced per-object, tenant, or admin
  guard (starting from the 64 the audit already confirmed).
- Per flagged method, apply exactly one of three ADR-005-correct postures:
  1. **Per-object / tenant ownership guard** — user-scoped objects (the common
     case): fetch the object, verify the caller owns it / is a member of its
     administration / tenant, throw `OCSForbiddenException` on failure.
  2. **`#[AuthorizedAdminSetting(settings: ...)]`** — methods that mutate
     instance-wide administrative state (per ADR-005's admin-surface-CRUD rule).
  3. **Documented open-to-all justification** — a code comment explaining why the
     method is intentionally reachable by any authenticated user with no
     per-object check (e.g., a read that is itself scoped server-side by the
     caller's own uid), for the rare case that is genuinely correct as-is.
- Fix the specific "fake guard" pattern found in `DBAController::ensureAdministrationAccess()` —
  either wire the real membership check or remove the misleading stub and replace it
  with an honest guard.
- Replace all 29 confirmed `$e->getMessage()` leaks with a machine-readable
  kebab-case error slug (ADR-050 error-shape convention) plus server-side
  `LoggerInterface->error()` logging of the real exception.
- PHPUnit coverage for every re-guarded method: the unauthorized case is rejected
  AND the authorized case still succeeds (positive direction, per ADR-055's
  guard-gaming lesson — a guard that only denies is as unproven as one that only
  logs).
- Playwright e2e coverage for the CBS Submissions manifest-v2 UI surface
  (`src/manifest.d/bookkeeping-cbs-bestanden-extended.json`, route
  `/bookkeeping/cbs-submissions`), since a real UI surface exists there.
- `@spec` (gate-16) and `@e2e` (gate-19) traceability on every changed method;
  reason-bearing `@e2e exclude` for API-only endpoints with no UI surface.

### Out of Scope

- **ADR-023's full `requireAction()`/`ActionAuthorization` declarative action-RBAC
  machinery** — building an admin-configurable action↔group matrix service is a
  separate, larger change. This change uses the narrower per-object/tenant/admin
  guard vocabulary ADR-005 Rule 3 already defines, which is sufficient to close the
  64 confirmed findings without introducing new shared infrastructure.
- Any controller method not in the audit's 195-method `#[NoAdminRequired]`
  population (e.g., `#[PublicPage]` or unannotated admin-default methods) — those
  are governed by different gates (`hydra-gate-route-auth`,
  `hydra-gate-semantic-auth`) and are not re-examined here.
- Multi-tenant `TenantScopedObjectService` (ADR-055 §5) — that shared primitive does
  not exist yet; this change documents per-controller why a guard is tenant-safe
  instead of depending on infrastructure that hasn't shipped.
- UI/UX changes beyond what's needed to keep the CBS Submissions page functioning
  after the guard change (no new screens, no new nav entries).

## Approach

1. Re-run the audit's mechanical enumeration (`hydra-gate-no-admin-idor`'s check
   logic, non-diff-scoped, across all of `lib/Controller/*.php`) to produce a
   candidate list. A naive run of this logic against current HEAD returns 105
   candidates across 49 files — a superset of the confirmed 64, because the regex
   both over-triggers (some candidates are already correctly guarded through a
   pattern the regex doesn't recognize) and under-triggers (`requireUser()` /
   `ensureAdministrationAccess()`-shaped stub or auth-only helpers pass the naive
   "a `require*`/`ensure*` call exists" check even though they enforce nothing
   beyond authentication).
2. Triage every candidate by reading the method body and any guard helper it calls
   — classify DELETE-nothing-add-guard / re-wire-stub-guard / already-guarded /
   needs-admin-attribute / needs-justification-comment. Record the verdict table in
   design.md (pattern established by the `orphan-auth-remediation` change).
3. Apply guards per the classified posture, controller by controller, in priority
   order: the named worst cases first (CBS, DBA, BankRule, Calendar,
   BookingNotification re-verification), then the remaining candidates.
4. Replace the 29 error-leak call sites with slugs + logging.
5. Add PHPUnit tests (positive + negative direction) per re-guarded method, and
   Playwright e2e for the CBS Submissions UI surface.
6. Re-run `hydra-gate-no-admin-idor`, `hydra-gate-semantic-auth`, gate-16, gate-19,
   and the full `composer check:strict` suite before PR.

## New Dependencies

None.

## Impact

- `lib/Controller/CBSSubmissionController.php` — worst confirmed case: 4 methods on
  instance-wide statutory filings guarded only by authentication.
- `lib/Controller/DBAController.php` — a named guard call that is a no-op stub.
- `lib/Controller/BankRuleController.php`, `lib/Controller/CalendarController.php` —
  confirmed missing per-object/tenant guards.
- `lib/Controller/BookingNotificationController.php` — re-verification of a
  possibly-already-fixed finding.
- ~55-60 additional controller methods across the remaining `lib/Controller/*.php`
  files, enumerated and triaged per the process above.
- 16 controllers with `$e->getMessage()` leaks: `BankStatementImportController`,
  `BillingIntakeController`, `BookingDepthController`, `CalendarController`,
  `ComplianceExportController`, `DunningController`, `GRIRReconciliationController`,
  `InvoiceApiController`, `PayrollController`, `PurchaseOrderController`,
  `ReconciliationResolutionController`, `RequisitionController`,
  `VATReturnController`, `WbsoAccountApiController`, `WbsoDocumentApiController`,
  `WbsoTransactionApiController`.
- New/expanded `tests/Unit/Controller/*Test.php` files for every re-guarded
  method.
- New `tests/e2e/cbs-submissions.spec.ts` (or an addition to an existing bookkeeping
  e2e spec) for the CBS Submissions UI surface.

## Cross-Project Dependencies

None — this is a self-contained shillinq change. It draws on company-wide ADR-005,
ADR-050, and ADR-055 (all already accepted, no changes requested to them) and
explicitly does not build ADR-023's cross-app action-RBAC machinery.

## Risks

### Risk 1: The full 64+29 remediation may not fit one Hydra build cycle

**Severity:** High — **Mitigation:** Per ADR-032, this is a `kind: code` change; the
default 200-turn Sonnet budget may not cover ~64 method-level guard additions plus
29 error-response edits plus their tests in one pass. tasks.md groups the work into
priority-ordered waves so a partial cycle still lands the highest-severity fixes
(CBS, DBA stub, BankRule, Calendar) first. If the builder times out before finishing
all waves, set `HYDRA_BUILDER_MAX_TURNS=400` (or `budget:large` label) on the retry
rather than re-scoping — the finding set is fixed by the audit, not negotiable down.

### Risk 2: A guard added in the wrong place breaks a legitimate cross-user workflow

**Severity:** Medium — **Mitigation:** Every method gets BOTH a negative test (guard
rejects) and a positive test (the legitimate caller still succeeds) before merge —
this is the change's core testing requirement, chosen specifically because ADR-055's
review round found guards that only prove the negative direction are unproven in the
positive one.

### Risk 3: The `BookingNotificationController` finding may already be resolved

**Severity:** Low — **Mitigation:** Flagged as an Open Question; the apply phase
re-verifies `authorizeBookingAccess()` against current HEAD before touching the file,
and either confirms the fix already landed (no-op, document in design.md) or finds
the residual gap the audit meant.

## Rollback Strategy

Every change in this set is additive at the guard layer (a new authorization check
that runs before existing business logic) or a response-shape change (error body
only, not the success path). Revert is a straight `git revert` of the merged PR(s) —
no data migration, no schema change, nothing to unwind. If a specific guard proves
too strict in production (blocks a legitimate caller), the fix is a follow-up PR
narrowing that one guard's condition, not a global rollback.

## Open Questions

- Is `BankRuleController::acceptSuggestion()`'s `resolveAdministrationId()` — which
  reads `AdministrationContextService::buildContext()['activeAdministrationId']` —
  ever influenced by request-supplied input (header, session value the client can
  set), or is it always derived purely server-side from the authenticated session?
  This determines whether the fix is "add an explicit membership check" or "confirm
  and document why session-derived scope is already IDOR-safe." Needs a code read of
  `AdministrationContextService` before the apply phase touches this controller.
- Is the `BookingNotificationController` finding still live at HEAD, or did
  `authorizeBookingAccess()`'s fix (referenced in its own docblock as a past bug fix)
  already close it? See Risk 3.
- Should the ~55-60 not-yet-individually-verified candidate methods from the 105-item
  mechanical scan be triaged as part of this change's Wave 2 task, or does the true
  count converge closer to the audit's 64 once stubs/false-positives are excluded?
  design.md's enumeration methodology section is written so the apply phase can
  answer this empirically rather than guessing a number now.
