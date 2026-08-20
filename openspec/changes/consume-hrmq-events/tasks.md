# Tasks: consume-hrmq-events

## 0. Pre-work — resolve the blocking cross-repo item before touching shillinq code
- [ ] Cross-repo task 1 (proposal.md): confirm with hrmq's owner that a
      typed `OCA\Hrmq\Event\TimesheetApprovedEvent` (dispatched via
      `IEventDispatcher::dispatchTyped()` at the same edge
      `TimeEntryEventService::maybeDispatchApproved()` already gates,
      additive alongside the existing `WebhookService` dispatch) will be
      built, or has already landed on hrmq's `development` branch. REQ-CHE-001
      cannot be implemented against a class that does not exist.
- [ ] Re-verify `time-entry-capture` and `TimeEntryEventService`/
      `TimesheetApprovalListener` against hrmq's actual `development` HEAD
      (this proposal was authored against `chore/openspec-archive` — proposal.md
      correction 1) before writing any shillinq code against the assumed
      payload shape.
- [ ] Confirm hrmq's `Timesheet.period` format is unchanged
      (`YYYY-MM`/`YYYY-Www`/`YYYY-Wnn-D`) against `hr-timesheet.json` on
      `development` — the mapping in design.md §3 depends on this exact
      polymorphic shape.

## 1. The listener + registration (REQ-CHE-001)
- [ ] Add `lib/Listener/HrmqTimesheetApprovedListener.php` implementing
      `IEventListener<Event>`, `class_exists()`-guarding
      `OCA\Hrmq\Event\TimesheetApprovedEvent` before touching the event,
      never throwing into hrmq's dispatch (fail-soft, mirroring
      `ExtractionCompletedListener`'s try/catch shape).
- [ ] Register it in `lib/AppInfo/Application.php::register()` via
      `registerEventListener(event: \OCA\Hrmq\Event\
      TimesheetApprovedEvent::class, listener:
      HrmqTimesheetApprovedListener::class)`, placed alongside the existing
      `ExtractionCompletedListener`/`PosStockDecrementListener`
      registrations with a comment citing this change and design.md §1.2's
      reasoning for why `register()` (not `boot()`) is correct here.

## 2. The deferred projection job (REQ-CHE-002)
- [ ] Add `lib/BackgroundJob/HrmqTimesheetProjectionJob.php` extending
      `OCA\OpenRegister\BackgroundJob\ActorForwardedJob`, implementing
      `runDeferred(DeferredListenerContext $context)`.
- [ ] `HrmqTimesheetApprovedListener::handle()` calls
      `ListenerDeferralService::defer(jobClass:
      HrmqTimesheetProjectionJob::class, entry: [...], dedupeKey:
      $timesheetId)` — this is the first shillinq adopter of
      `ListenerDeferralService`; verify its constructor/DI wiring resolves
      cleanly from shillinq's container (it lives in OpenRegister,
      consumed as a soft dependency like every other OR service shillinq
      uses).
- [ ] Implement the `period` → `date` mapping per design.md §3 for all
      three grains (`YYYY-MM`, `YYYY-Www`, `YYYY-Wnn-D`), including the
      raw-period-string note appended to `description` for week/month
      grain.
- [ ] Implement the upsert: find an existing `UrenRegistratie` row by
      `(sourceApp="hrmq", externalId=<timesheetId>)` via `ObjectService`;
      update in place if found, `saveObject()` a new row if not.
- [ ] Log (never persist to a non-existent field) `billable`, `clientRef`,
      `approvedBy`, `approvedAt` on every upsert.
- [ ] Best-effort `costCenter` → `costProjectId` resolution against
      existing `AnalyticalDimension` rows; leave null + log when no match
      (design.md §3 — no new matching service is built here).

## 3. WBSO auto-tagging confirmation (REQ-CHE-003)
- [ ] No code change expected — confirm via a PHPUnit assertion (task 5)
      that `wbsoAutoTag` fires on a job-upserted row exactly as it does on
      a manually-created one. If it does NOT fire (e.g. the aggregation
      trigger is scoped in a way that misses a job-context write), that is
      a real defect to fix here, not to route around.

## 4. UrenRegistratie index page — provenance indicator (REQ-CHE-004)
- [ ] Add a provenance column/badge to the existing `UrenRegistratie` index
      page manifest entry (`src/manifest.json`, the page at line ~11255)
      showing `sourceApp` when set, without altering existing filters,
      columns, or the create/edit action.
- [ ] Confirm manual create/edit still works unchanged (no gating logic
      added anywhere in the page or its underlying schema/lifecycle).
- [ ] `node tests/check-manifest-budget.js` — PASS, report the byte delta
      (expected small/negligible — one additive column, no new page).

## 5. Tests
- [ ] `tests/Integration/HrmqTimesheetApprovedListenerIntegrationTest.php`
      — day/week/month grain projection, dedupe-on-`externalId`,
      hrmq-absent inertness (REQ-CHE-001 scenario 2), WBSO auto-tag
      confirmation (REQ-CHE-003), unmatched-field logging (REQ-CHE-002
      scenario 4).
- [ ] `tests/e2e/consume-hrmq-events.spec.ts` —
      `consume-hrmq-events::hours-index-renders-projected-rows`: seed an
      hrmq-sourced row via the OR API directly, open the index page, assert
      the provenance indicator renders and manual-entry affordances remain
      present.
- [ ] Tag the Playwright spec `@e2e consume-hrmq-events::
      hours-index-renders-projected-rows` matching the spec.md scenario id
      exactly (gate-19).

## 6. Cross-repo tasks — handed back, not implemented here (see proposal.md)
- [ ] File/flag: hrmq typed `TimesheetApprovedEvent` addition (cross-repo
      task 1) — blocking.
- [ ] File/flag: hrmq `nl.conduction.hrmq.expense.approved` gap + the
      `ExpenseClaimEntry` retirement product decision (cross-repo task 2).
- [ ] File/flag: hrmq `Timesheet.period` granularity product direction
      (cross-repo task 3).
- [ ] File/flag: re-verification against hrmq's `development` HEAD
      (cross-repo task 4) — should already be closed by task 0 above before
      this change ships, recorded here for the orchestrator's visibility.

## 7. Validation
- [ ] PHPUnit suite green, including the new integration test.
- [ ] `npx playwright test tests/e2e/consume-hrmq-events.spec.ts` — PASS.
- [ ] `node tests/check-manifest-budget.js` — PASS.
- [ ] `npm run check:nav-reachability` — PASS (no nav change expected, but
      confirm no regression from the manifest edit).
- [ ] `openspec validate consume-hrmq-events --strict` — PASS.
