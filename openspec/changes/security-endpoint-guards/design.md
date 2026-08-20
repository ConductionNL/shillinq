# Design: security-endpoint-guards

## Context

shillinq's controllers default to Nextcloud's `#[NoAdminRequired]` attribute for any
endpoint a non-admin user should be able to call. Per ADR-005 Rule 3, that attribute
alone is not authorization — it only removes the framework's admin-only default. The
method body must independently establish that the calling user is entitled to act on
the specific object/administration/tenant the request names. `hydra-gate-no-admin-idor`
mechanically checks for this, but it is diff-scoped (ADR-020) and pattern-based (looks
for a call shaped like `authorize*`/`require*`/`ensure*`/`isAdmin(`/`OCSForbiddenException`
in the method body) — it cannot tell a real guard from a same-shaped stub. The
completed audit that scoped this change found 64 of 195 `#[NoAdminRequired]` methods
with no enforced guard at all, plus one case (`DBAController`) where a guard call
exists but does nothing.

## Goals / Non-Goals

**Goals:**
- Close all 64 confirmed missing-guard findings with the ADR-005-correct posture.
- Fix the one confirmed "fake guard" (`DBAController::ensureAdministrationAccess()`).
- Close all 29 confirmed exception-message leaks per ADR-050.
- Leave behind PHPUnit + Playwright evidence that each new guard actually enforces
  (not just "a call exists").

**Non-Goals:**
- Building ADR-023's action↔group admin-configurable matrix (`requireAction()`,
  `ActionAuthorization` service). That is cross-cutting infrastructure work with its
  own design surface; this change stays within ADR-005's existing per-object/
  tenant/admin guard vocabulary.
- Building the ADR-055 §5 `TenantScopedObjectService` shared primitive. Per-controller
  guards in this change hand-roll the membership check they need and document why,
  exactly as ADR-055 §5 instructs multi-tenant apps to do until that primitive ships.
- Re-auditing methods outside the audit's 195-method `#[NoAdminRequired]` population.

## Enumeration & Triage Methodology

### Step 1 — Mechanical candidate generation

The audit's number (64 confirmed) is the output of human triage on top of a
mechanical scan; it is not directly reproducible by grep alone. To ground this
change, `hydra-gate-no-admin-idor`'s exact check logic (see
`.claude/skills/hydra-gate-no-admin-idor/SKILL.md`, Step 1) was re-run **non-diff-
scoped** against all of `lib/Controller/*.php` at HEAD:

```bash
for f in lib/Controller/*.php; do
    [ -f "$f" ] || continue
    grep -nE "^\s*public\s+function\s+[a-zA-Z0-9_]+\s*\(" "$f" \
        | while IFS=: read line_no _; do
        method=$(sed -n "${line_no}p" "$f" | grep -oE 'function\s+[a-zA-Z0-9_]+' | awk '{print $2}')
        [ -z "$method" ] && continue
        start=$((line_no > 20 ? line_no - 20 : 1))
        head_block=$(sed -n "${start},${line_no}p" "$f")
        echo "$head_block" | grep -qE '#\[NoAdminRequired\b|@NoAdminRequired\b' || continue
        body=$(awk -v start="${line_no}" 'NR >= start { print; if (NR > start && /^    \}/) exit }' "$f")
        if ! echo "$body" | grep -qE 'OCSForbiddenException|isAdmin\s*\(|->\s*(authorize|require|ensure)[A-Z][a-zA-Z0-9_]*\s*\(|#\[PublicPage\]|@PublicPage\b'; then
            echo "FAIL ${f}:${line_no} method=${method}"
        fi
    done
done
```

**Result at HEAD: 105 candidates across 49 files** — a superset of the confirmed 64.
The gap runs both directions:

- **Over-triggers**: some of the 105 are already correctly guarded through a shape
  the regex doesn't recognize (e.g., a guard delegated to a private helper called
  under a different name, or an admin check expressed as
  `$this->groupManager->isAdmin($uid)` stored in a variable before branching).
- **Under-triggers**: the regex accepts *any* `->requireX(`/`->ensureX(` call as
  proof of a guard. `CBSSubmissionController::requireUser()` and
  `DBAController::ensureAdministrationAccess()` both match the pattern by name while
  enforcing nothing beyond "is logged in" (the former) or nothing at all (the
  latter, a documented stub). **Both would mechanically PASS gate-7 today.**

### Step 2 — Per-candidate manual triage (this change's Wave-1 task)

Every one of the 105 candidates gets a one-line verdict, following the
`orphan-auth-remediation` change's precedent (verdict table in design.md, one row
per finding, four possible verdicts):

| Verdict | Meaning | Action |
|---|---|---|
| **GUARD (ownership)** | User-scoped object; needs a fetch→compare-owner/administration→throw pattern | Add the guard, call it from every mutating method on the resource |
| **GUARD (admin)** | Instance-wide administrative CRUD wrongly left `#[NoAdminRequired]` | Swap to `#[AuthorizedAdminSetting(settings: ...)]` |
| **JUSTIFY** | Genuinely open-to-all-authenticated-users behavior (e.g., a read already scoped server-side by the caller's own uid downstream) | Add a code comment explaining why, per the `hydra-gate-no-admin-idor` false-positive guidance |
| **ALREADY-GUARDED (false positive)** | The regex missed an existing guard | No code change; note in the verdict table so re-runs don't re-flag it |
| **STUB (fix the fake guard)** | A guard call exists but its body doesn't enforce anything | Wire the real check or delete the misleading stub and replace it honestly |

The five worst / most illustrative findings, verified against HEAD for this design
document (not guessed):

| File:line | Method | Verdict | Evidence |
|---|---|---|---|
| `lib/Controller/CBSSubmissionController.php:280` (also `:179 create`, `:229 update`, `:341 generate`) | `destroy` et al. | **GUARD (ownership/tenant)** | `requireUser()` (`:101-110`) checks only `getUser() !== null`. `destroy()` fetches by id, checks only `status`, calls `deleteObject($id)` — zero ownership/administration check. Docblock's claim of service-layer validation is false (verified: `objectService()`/`register()` at `:541-556` do no such thing). |
| `lib/Controller/DBAController.php:357` | `setTussenkomstMode` | **STUB (fix the fake guard)** | `ensureAdministrationAccess()` (`:699-714`) is a documented no-op: "Real check would call `AdministrationMembership::isMember($user, $administrationId)`. Until the implementation cycle wires it, we log the guarded access." It never throws. |
| `lib/Controller/BankRuleController.php:238` | `acceptSuggestion` | **NEEDS VERIFICATION** (see Open Question in proposal.md) | `requireAuthenticatedSession()` (`:420`) is auth-only (throws `OCSForbiddenException` only on `getUser() === null`). The created `MatchingRule`'s `administrationId` comes from `resolveAdministrationId()` (`:390`), which reads `AdministrationContextService::buildContext()['activeAdministrationId']`. Whether that context can be influenced by request-supplied input was not resolved before this design was written — the apply phase must read `AdministrationContextService` first. |
| `lib/Controller/CalendarController.php:302` | `createBooking` | **GUARD (ownership/tenant)** | `requireAuth()` (`:438-444`) checks only `$this->context->currentUserId() !== null`. No check that the caller is entitled to book the resolved `$resourceId`. |
| `lib/Controller/BookingNotificationController.php:91,123` | `getBookingTriggers`, `updateBookingTriggers` | **ALREADY-GUARDED (re-verify)** | `authorizeBookingAccess()` (`:272-`) does check admin-or-`AdministrationContextService`-membership against the booking's `administrationId`, and its docblock documents a prior `findObject()`/`find()` bug that made the guard fail-open-as-403-to-everyone (already fixed — it now calls the real `find()`). This may be a resolved finding; the audit's "confirmed" note may predate the fix, or point at the annotation split (`:164`/`:220` use `#[AuthorizedAdminSetting]` on the same resource type) as the actual concern rather than a missing guard. **Do not blindly re-add a guard here — re-read the method first.** |

### Apply-phase resolution of the five named findings

Resolved against HEAD during implementation (code read, not guessed):

| File:line | Method | Final Verdict | Resolution |
|---|---|---|---|
| `CBSSubmissionController` `create/update/destroy/generate` | — | **GUARD (ownership/tenant) — fixed** | Injected `AdministrationContextService`; added `requireAdministrationAccess(administrationId)` (403 on non-member, matching this change's spec scenario) called after fetching the existing object (`update`/`destroy`/`generate`) or against the request-body `administrationId` (`create`, before the object is persisted). **Also found and fixed during the same code read, beyond the audit's named 4**: `index()` and `show()` had the identical missing-guard shape (`requireUser()` is auth-only) — any authenticated user could list or read another organization's filing. `index()` now scopes an unfiltered list to the caller's own accessible administrations and 403s an explicit out-of-scope `administrationId` filter; `show()` 403s on a non-member. Fixed for consistency since REQ-001 applies to every `#[NoAdminRequired]` method on the file, not only the four the audit enumerated. |
| `DBAController::ensureAdministrationAccess()` | `setTussenkomstMode` + 6 other call sites | **STUB — fixed** | Injected `AdministrationContextService`; the stub now calls `canAccess($administrationId)` and throws `OCSForbiddenException` on denial (anonymous, empty administrationId, or non-member) instead of logging-and-returning unconditionally. All 7 call sites (`saveIntake`, `computeScore`-adjacent flows, `uploadWba`, `setTussenkomstMode`, `evidenceConsent`, and 2 more) inherit the fix with no per-call-site change needed, since they all funnel through this one helper. |
| `BankRuleController::acceptSuggestion()` | — | **ALREADY-GUARDED (session-derived, IDOR-safe) — Open Question resolved, no code change** | Read `AdministrationContextService::buildContext()` (`lib/Service/AdministrationContextService.php:113-162`): `activeAdministrationId` is `$administrations[0]['administrationId']`, derived from `membershipsForUser($this->currentUserId())` — i.e. purely from the authenticated session uid via the caller's own `AdministrationMembership` records. No request parameter, header, or client-supplied value reaches `buildContext()` anywhere in its call graph. The only client-observable effect is that a multi-administration user always lands on their FIRST accessible administration (a business-logic limitation — they cannot choose which — not an IDOR vector, since it is always an administration they are a genuine member of). Verdict: confirmed already IDOR-safe by construction. Documented in the method's docblock (`resolveAdministrationId()`) rather than adding a redundant membership check. |
| `CalendarController::createBooking()` | — | **GUARD (ownership/tenant) — fixed** | Added a check after `loadCalendar()`: `$this->context->canAccess($calendarRow['administrationId'])`, returning 403 (`calendar-booking-forbidden`) before the transactional conflict-check/write runs. `$this->context` (`AdministrationContextService`) was already injected for `resolveAdministrationId()`'s read-side filtering — reused, no new dependency. |
| `BookingNotificationController::getBookingTriggers/updateBookingTriggers` | — | **ALREADY-GUARDED — Open Question resolved, no code change** | Re-read `authorizeBookingAccess()` (`:273-323`) against HEAD before touching the file, per Risk 3's mitigation. The guard is genuine: admin bypass via `IGroupManager::isAdmin()`, then `AdministrationContextService::canAccess($booking['administrationId'])`, throwing `OCSForbiddenException` on both a missing booking and a non-member caller. The docblock's own history note (the `findObject()` vs `find()` bug) documents a PRIOR defect that was already fixed before this change started — that fix, not a still-open gap, is what the audit's "confirmed" note most likely trailed. The `#[NoAdminRequired]` (per-object: `getBookingTriggers`/`updateBookingTriggers`) vs `#[AuthorizedAdminSetting]` (instance-wide: `getNotificationMonitor`/`disableAllTriggers`) annotation split was also re-checked and is correct for what each method does — not a finding. Verdict recorded in the method's docblock. |
| `lib/Service/InvoiceGenerationService.php::draftInvoice()` | `loadTimeEntries`/`loadExpenses`/`loadMeterReadings`/`loadMilestone` | **GUARD (ownership/tenant) — fixed** | Second-order IDOR found during Wave-2 triage of `InvoiceApiController` (recorded below as an Incomplete Item, now closed for this half). Each of the four private loaders resolved its client-supplied id (`timeEntryIds`/`expenseIds`/`meterReadingIds`/`milestoneId` on `InvoiceGenerationRequest`) via `ObjectService::find($id)` alone — no `administrationId` check — so a member of administration A could reference administration B's `UrenRegistratie`/`ExpenseClaimEntry`/`MeterReading`/`Milestone` records and have B's hours/expense amounts/metered usage/milestone name+budget billed onto A's invoice. Fixed by adding `findScoped()` — a compound `id`+`administrationId` equality filter via `findAll()` (mirrors `GoodsReceiptNoteService::findOne()`'s scoping-the-lookup pattern rather than fetch-then-check) — and threading `$request->administrationId` through all four loaders plus the `UsageRatePlan` lookup inside `loadMeterReadings()` (a `MeterReading`'s own `ratePlanId`, or the request's `usageRatePlanId`, could also point at another tenant's price book). A cross-tenant id is now indistinguishable from an unknown one: silently excluded (time entry/expense/meter reading) or falls back to the existing generic stub (milestone) — matching this file's own pre-existing convention for an unresolvable reference, not a new error path. 6 new tests in `tests/Unit/Service/InvoiceGenerationServiceIdorTest.php` cover all four id types + the nested UsageRatePlan reference, both directions (cross-tenant excluded, own-tenant still drafts); verified with a temporary negative control (guard reverted to the pre-fix `find()` call) showing the cross-tenant time-entry test fail red (`1300.0` leaked vs `500.0` expected) before restoring green. `RateCardResolver`/`RetainerResolver` (the `rateCardId`/`retainerScheduleId` half of the original finding) remain open — see Incomplete Items. |

### Wave 2 — full 105-candidate triage (complete)

All 105 mechanical-scan candidates were triaged file-by-file, read (method body +
any guard helper), and classified. Work was split three ways by file (Group A: 34
candidates across 17 files; Group B: 36 candidates across 14 files; Group C: 35
candidates across 18 files — 105 total, reconciling exactly with the Step-1 scan
count). Full per-candidate verdict tables with evidence live in the scratch
reports the triage passes produced; the counts below are the authoritative
roll-up.

**Verdict counts (Wave 2, 105/105 candidates)**:

| Verdict | Count | Notes |
|---|---|---|
| GUARD (ownership/tenant) — fixed/hardened | 5 | `FinancialDashboardController::series/summary` (partial — see note below), `DunningController::resumePause`, `BookingDepthController::createSeries`, `WbsoAdministratieController::realisatie` |
| GUARD (admin) | 0 | No candidate was genuinely instance-wide admin CRUD mis-annotated as `#[NoAdminRequired]` |
| JUSTIFY | 14 | Methods with no caller-reachable cross-tenant object (session-only scope, global reference data, or no OpenRegister read at all) — inline comment + `@spec`/`@e2e` tags added, no behavior change |
| ALREADY-GUARDED (false positive) | 86 | A real, enforcing guard already existed under a shape the mechanical regex doesn't recognize — see below |
| STUB | 0 | The only STUB in the whole change was `DBAController::ensureAdministrationAccess()`, found and fixed as one of the five named findings (not part of the 105) |
| **Total** | **105** | |

**Why 86 of 105 were false positives — the dominant, systemic cause**: the
mechanical scan's regex (`.claude/skills/hydra-gate-no-admin-idor`, and this
change's Step 1 reproduction) recognizes a guard only when it is shaped
`authorize*(`/`require*(`/`ensure*(`/`isAdmin(`/`OCSForbiddenException`/
`#[PublicPage]`. This app's actual canonical per-object/tenant guard call is
**`AdministrationContextService::canAccess(administrationId: …)`**, called
either inline in the controller method or one hop away in a service/helper
(`resolveScope()`, `mayAccessReturn()`, `guardDraftAccess()`, or a service-layer
`accessibleAdministrationIds()`/double-filtered `findOne(['id' => …,
'administrationId' => …])` lookup). None of those shapes match the regex's verb
list. **Every one of the 86 ALREADY-GUARDED verdicts was independently verified
by reading the guard's actual implementation** (not inferred from its presence)
— `AdministrationContextService::canAccess()` performs a real
`AdministrationMembership` lookup and returns `false` for a non-member, and
several batches additionally ran a live negative-control (temporarily disabling
the guard and re-running the existing test) to confirm it denies under the
removal, not just exists. **Recommendation**: extend
`hydra-gate-no-admin-idor`'s regex to also match `->canAccess(` (and, ideally,
delegated-service scoping shapes like `accessibleAdministrationIds()`) so this
false-positive class does not recur on the next non-diff-scoped re-run — flagged
here as a follow-up gate-fix, not implemented as part of this change (out of
scope: this change fixes findings, it does not modify the gate scripts).

**The 5 real GUARD fixes, briefly** (full evidence in the per-batch scratch
reports):
- `FinancialDashboardController::series()`/`summary()` — the underlying
  `FinancialDashboardService::fetchSchema()` reads several schemas via an
  unfiltered `findAll([])`; **partially fixed** within this task's file scope
  (`respond()` now 403s a caller with zero accessible administrations), but a
  full per-row scope fix needs `GLLine` (which carries no `administrationId`
  property at all — a documented, already-pinned gap) and touches
  `lib/Service/FinancialDashboardService.php`, outside this task's assigned
  file list. **Flagged as an incomplete item, not silently closed** — see
  Incomplete Items below.
- `DunningController::resumePause()` — the controller's own `canAccess()` guard
  checked the *request-supplied* `administration_id`, but the target
  `DunningPauseDispute` (addressed by a separate `pauseId` path param) was never
  re-checked against its *own* `administrationId` — `DunningRunService::resumePause()`
  fetches by id alone and never reads its `$administrationId` parameter
  (flagged by its own pre-existing `@SuppressWarnings(PHPMD.UnusedFormalParameter)`
  note). A member of administration A supplying `administration_id=A` (passes)
  and a guessed/enumerated `pauseId` from administration B could resume/read B's
  dunning pause. Fixed entirely at the controller layer: fetch the pause first,
  then check `canAccess()` against its real `administrationId`.
- `BookingDepthController::createSeries()` — the request-supplied
  `administrationId` was correctly checked, but the caller-supplied `resourceId`
  was never checked to actually belong to that administration — a member of A
  could pass `administrationId=A` (their own) with a `resourceId` from B and
  book against B's resource. Fixed with an explicit
  `$resource['administrationId'] !== $administrationId` check before persistence.
- `WbsoAdministratieController::realisatie()` — no guard at all; the method's own
  `@no-admin-idor-exempt` docblock claimed "OpenRegister's ObjectService RBAC
  layer" validates the scope, which is false (no schema in this app declares an
  `authorization` block — the same false-claim pattern this change's Motivation
  section already documented for `CBSSubmissionController`). Fixed with a new
  `canAccess()` check, masked 404 on denial.

Full per-candidate evidence (105 rows) is preserved in the triage passes' scratch
output; summarized here to keep this file navigable. Ask the orchestrator for the
raw per-batch tables if a specific candidate's reasoning needs re-verification.

### Step 3 — Convergence with the audit's "64"

The audit's 64 is the already-triaged count (candidates with a real GUARD or STUB
verdict, excluding ALREADY-GUARDED false positives and JUSTIFY cases). This design
does not force the Wave-2 batch triage to land on exactly 64 — the true number is
whatever the per-method evidence supports. If Wave 2's triage converges on a
materially different number, that is expected (grep-based candidate generation is
intentionally over-inclusive) and should be recorded, not treated as a discrepancy
to explain away.

## Error-response hardening (ADR-050)

29 occurrences across 16 controllers return
`new JSONResponse(['error' => $e->getMessage()], <status>)`. Per ADR-050's envelope
convention, replace with:

```php
} catch (\Throwable $e) {
    $this->logger->error('CBSSubmissionController.destroy failed', ['exception' => $e]);
    return new JSONResponse(
        ['message' => $this->l10n->t('Unable to delete CBS submission'), 'error' => 'cbs-submission-delete-failed'],
        Http::STATUS_INTERNAL_SERVER_ERROR,
    );
}
```

- `message` — localized, human-readable, shown in the UI.
- `error` — kebab-case, machine-readable, stable slug for frontend dispatch/testing.
  Convention: `<resource>-<verb>-failed` or a more specific slug when the exception
  maps to a known condition (e.g. `cbs-submission-not-found`,
  `cbs-submission-not-draft`).
- The real exception goes to `$this->logger->error()` (already the pattern used by
  several sibling controllers in the same files, e.g.
  `WbsoTransactionApiController::fail()` at `:107`), never to the response body.

Each of the 16 files gets its slugs chosen per call site based on the specific
exception/condition being handled — this is enumerated per-file in tasks.md rather
than here, since the exact slug text is implementation detail, not a design
decision.

## Nextcloud Integration

- Controllers: all 16+ files listed in proposal.md's Impact section.
- Services: no new services. `DBAController::ensureAdministrationAccess()` either
  gets a real body (calling whatever membership-check service the app already uses
  elsewhere — same pattern as `BookingNotificationController::authorizeBookingAccess()`'s
  `AdministrationContextService` check) or is deleted in favor of an inline check.
- No new Mappers/Entities, no new Events/Hooks — this is guard logic inside existing
  controller methods.

## Security Considerations

This entire change IS the security consideration — see Context above. One
implementation rule carried over from ADR-005 / ADR-055: **never suppress a
`hydra-gate-no-admin-idor` finding by removing `#[NoAdminRequired]`** — that
silently makes the endpoint admin-only and breaks the non-admin flow it exists for.
Every fix in this change either adds an enforced guard or (rarely, for the JUSTIFY
verdict) documents why none is needed; none of them removes the attribute to make
the gate stop complaining.

## Seed Data

None. This change adds authorization logic and error-response shape changes to
existing controller methods — no new OpenRegister schema, no new object types, no
`lib/Settings/shillinq_register.json` change.

## ADR-031 alignment

ADR-031 (schema-declarative business logic over service classes) does not apply
here — per-request authorization decisions (compare the caller's identity against
the target object's owner/administration) are exactly the kind of imperative,
per-request check ADR-031 does not ask apps to express declaratively; OpenRegister's
own `x-openregister-*` declarative surfaces cover object-level RBAC
(register/schema permissions), not the app-level "does this specific authenticated
user own this specific object" check that ADR-005 Rule 3 requires in the controller.
No new `x-openregister-*` metadata is introduced or needed.

## Risks / Trade-offs

- **[Risk] Scope too large for one Hydra build cycle** → **Mitigation**: tasks.md
  orders work in priority waves (named worst cases first) so a partial cycle still
  lands the highest-value fixes; `HYDRA_BUILDER_MAX_TURNS=400` / `budget:large` are
  available if needed rather than re-scoping the finding set down.
- **[Risk] Over-guarding breaks a legitimate cross-administration workflow** (e.g., an
  accountant-portal user who is meant to act across multiple client administrations)
  → **Mitigation**: every guard addition is checked against
  `openspec/specs/accountant-portal/spec.md` and `openspec/specs/app-administration/spec.md`
  before landing, and gets a positive-direction test proving the legitimate
  cross-administration caller (where one exists) still succeeds.
- **[Risk] The `DBAController` stub-fix requires a real membership-check dependency
  that may not exist yet** → **Mitigation**: if no membership service exists,
  the fallback is an explicit `administrationId` match against the authenticated
  user's own session-scoped administration context (same pattern
  `BookingNotificationController::authorizeBookingAccess()` already uses via
  `AdministrationContextService`) — not a new shared service, reusing what's already
  proven correct in this codebase.

## Migration Plan

No data migration. Deploy is a normal PHP code change:
1. Merge to `development`.
2. Existing objects are unaffected — guards evaluate at request time, not stored
   state.
3. Rollback is `git revert` (see proposal.md Rollback Strategy) — no forward-only
   state to unwind.

## Open Questions

See proposal.md's Open Questions section — carried here for reference:
`AdministrationContextService`'s input surface (BankRuleController), whether the
`BookingNotificationController` finding is already resolved, and whether the Wave-2
batch triage converges near 64 or a different number.

**All three resolved during implementation:**
- `AdministrationContextService`'s input surface (BankRuleController) — session-derived,
  confirmed IDOR-safe by construction (no code change). See the five-named-findings
  table above.
- `BookingNotificationController` — already resolved before this change started
  (ALREADY-GUARDED, no code change). See the five-named-findings table above.
- The Wave-2 batch triage converged on **105 candidates, 5 real guard fixes (+ 1 more
  in the CBS index/show bonus find), 0 admin-mis-annotations, 14 JUSTIFY, 86
  false-positive ALREADY-GUARDED**, materially different from the audit's original
  "64 confirmed" figure. This is not a discrepancy to explain away — the audit's 64
  was itself an estimate ahead of the per-method code read this change's methodology
  section calls for, and the true number (per-method evidence, not a target) is 5
  fixes out of 105 mechanically-flagged candidates. The dominant reason the audit's
  64 overshot: `AdministrationContextService::canAccess()` is this app's canonical
  guard and was already wired into the overwhelming majority of flagged methods, just
  under a call shape (`canAccess(`, not `authorize*`/`require*`/`ensure*`) neither the
  audit's manual read nor the mechanical scan's regex fully accounted for ahead of time.

## Incomplete Items (honest ledger, not silently closed)

- **`FinancialDashboardController::series()`/`summary()`** — only partially fixed.
  A caller with zero accessible administrations is now rejected (403), but a caller
  who belongs to administration A still receives dashboard aggregates computed across
  **every** administration's `Account`/`GLTransaction`/`GLLine`/`UrenRegistratie`/
  `ARInvoice`/`APTransaction` data, because `FinancialDashboardService::fetchSchema()`
  reads via an unfiltered `findAll([])`. A full fix needs per-row administration
  scoping in `lib/Service/FinancialDashboardService.php` — outside this change's
  assigned file list (the Wave-2 batch that found this was scoped to
  `lib/Controller/FinancialDashboardController.php` only) — and, for `GLLine`
  specifically, that schema carries no `administrationId` property at all (a
  documented, already-pinned gap per `SpendAnalyticsServiceTest`), so closing this
  fully may need a schema/data migration, not just a code change. **Recommended as a
  focused follow-up change**, not silently left looking "done."
- **`DunningRunService::resumePause()`'s dead `$administrationId` parameter** — the
  `resumePause()` IDOR was fixed entirely at the controller layer (fetch-then-check
  before calling the service); the service method's own unread `$administrationId`
  parameter (and its `@SuppressWarnings(PHPMD.UnusedFormalParameter)` note) is now
  redundant defense-in-depth, not currently exploitable, but worth wiring for
  defense-in-depth in a follow-up (`lib/Service/DunningRunService.php` was outside
  this change's assigned file list).
- **`InvoiceGenerationService::draftInvoice()` — `rateCardId`/`retainerScheduleId`
  half only, still open** (discovered during Wave-2 triage of
  `InvoiceApiController`; the `timeEntryIds`/`expenseIds`/`meterReadingIds`/
  `milestoneId` half of this finding is now **GUARD-fixed**, see the Apply-phase
  table above). `RateCardResolver::resolveRate()` and
  `RetainerResolver::resolveRetainerAmount()` (both `lib/Service/`, both outside
  this change's assigned file list) still resolve `rateCardId`/`retainerScheduleId`
  purely by client-supplied id with no `administrationId` filter at all — a caller
  can reference another administration's `RateCard`/`RateRecord`/`RetainerSchedule`
  and have its negotiated rate applied to their own invoice. A second-order IDOR of
  the same shape, requiring a signature change across two service classes and their
  ~13 existing unit-test call sites — **recommended as a focused follow-up change**,
  not silently left looking "done."
- **Two ADR-050 leak variants outside the literal grep's exact-line match, found but
  not fixed** (both explicitly out of the assigned per-file leak-fix scope):
  `GoodsReceiptNoteController::mapRuntimeException()` (`lib/Controller/GoodsReceiptNoteController.php:390-404`)
  returns `['error' => $exception->getMessage()]` on 3 status branches via a
  `$message` intermediate variable — not literally
  `JSONResponse(['error' => $e->getMessage()]`, so the exact grep this change's
  acceptance criteria specifies does not catch it, but it is the same defect class.
  `ReconciliationResolutionController::bulkResolve()` similarly returns
  `$failed[$matchId] = $e->getMessage()` in a per-item partial-failure map inside a
  200 response — a batch-operation variant of the same pattern, also outside the
  literal grep and this change's explicit per-file leak-fix assignment list.
- **Missing PHPUnit coverage for two files with no test file at all**:
  `ThreeWayMatchController` and `ThreeWayMatchAuditController` have zero existing
  PHPUnit coverage (`tests/Unit/Controller/ThreeWayMatchControllerTest.php` /
  `ThreeWayMatchAuditControllerTest.php` do not exist). Their guards are real and
  already enforce (ALREADY-GUARDED, confirmed by code read), so REQ-004 does not
  require new tests here (no posture was added/changed), but the fleet-wide absence
  of a test file for either controller is worth a dedicated follow-up.
- **Two real UI surfaces with zero e2e coverage, neither touched by this change's
  guard fixes**: Requisitions (`/inkoop/requisitions`) and the Reporting & Compliance
  overview (`ReportingComplianceOverview.vue`) both have live manifest routes and no
  Playwright spec. This change's own e2e scope (per proposal.md) is limited to the
  CBS Submissions surface (Task 8); authoring new coverage for these two is a
  separate, larger task.
- **Mechanical scan false-positive rate (systemic, for a follow-up gate-fix)**: 86 of
  105 Wave-2 candidates (82%) were false positives because
  `hydra-gate-no-admin-idor`'s regex does not recognize `->canAccess(` as a guard
  shape. Recommend extending the regex (see the Wave-2 section above) — not
  implemented here, since this change's scope is fixing findings, not modifying gate
  scripts.
