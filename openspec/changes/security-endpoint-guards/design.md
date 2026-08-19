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

The remaining ~100 candidates (105 minus the 5 above) are enumerated in
`tasks.md` Wave 2 as a batch triage-and-fix task, working file-by-file through the
mechanical scan's output, applying the same five-verdict process.

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
