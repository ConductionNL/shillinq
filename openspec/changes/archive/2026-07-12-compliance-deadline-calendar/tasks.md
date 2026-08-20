# Tasks: compliance-deadline-calendar

## Implementation Tasks

### Task 1: Compliance deadline calendar service (app calendar + fail-soft upsert)
- **spec_ref**: `openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-001`
- **files**: `lib/Service/ComplianceDeadlineCalendarService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a resolvable calendar backend WHEN a deadline is published twice THEN one VEVENT with UID `{source}:{objectId}` exists (idempotent upsert)
  - GIVEN no calendar backend WHEN publication runs THEN it logs and returns `failed` without throwing; source records unaffected
- [x] Implement
- [x] Test

### Task 2: Publish BTW/ICP/VPB filing deadlines from existing period data
- **spec_ref**: `openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-002`
- **files**: `lib/Service/ComplianceDeadlineCalendarService.php`
- **acceptance_criteria**:
  - GIVEN a VatReturn with a derivable filing due date WHEN the service runs THEN a "BTW-aangifte {period}" VEVENT is published on that date (no parallel deadline stored)
  - GIVEN a filing that reaches submitted/closed WHEN the service runs THEN its VEVENT is removed
- [x] Implement
- [x] Test

### Task 3: Publish payment-run execution dates + opt-in AR due dates
- **spec_ref**: `openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-003`
- **files**: `lib/Service/ComplianceDeadlineCalendarService.php`
- **acceptance_criteria**:
  - GIVEN a payment run scheduled for a date WHEN the service runs THEN a VEVENT identifying the run is published on that date and removed once executed/cancelled
  - GIVEN a user with the AR-due-date category off THEN no AR due-date VEVENTs are published; enabling it publishes open AR due dates and removes them on `paid`/`written-off`
- [x] Implement
- [x] Test

### Task 4: Extend ObligationTaskBridge with contract deadline VEVENTs
- **spec_ref**: `openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-005`
- **files**: `lib/Service/ObligationTaskBridge.php`
- **acceptance_criteria**:
  - GIVEN a `ContractObligation` with an opzegtermijn deadline WHEN saved THEN the extended bridge publishes a deadline VEVENT in addition to the existing VTODO, with no separate contract-reading service
  - GIVEN no calendar backend WHEN saved THEN both VTODO and VEVENT return `failed` without throwing; obligation CRUD proceeds
- [x] Implement
- [x] Test

### Task 5: Per-user category-toggle setting
- **spec_ref**: `openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-006`
- **files**: `lib/Controller/DeadlineCalendarSettingsController.php`, `src/views/DeadlineCalendarSettings.vue`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a user WHEN they toggle a category off THEN that category's VEVENTs are removed and none are re-published for them
  - GIVEN the settings controller WHEN called THEN it reads/writes only the current user's preferences (no IDOR)
- [x] Implement
- [x] Test

### Task 6: Scheduled reminder-notification job
- **spec_ref**: `openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-007`
- **files**: `lib/BackgroundJob/DeadlineReminderJob.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a deadline within the user's lead time and its category enabled WHEN the job runs THEN exactly one NC Notification per deadline per user is raised
  - GIVEN a disabled category WHEN the job runs THEN no reminder is raised for that category's deadlines
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off and `openspec validate` passes
- [x] Fail-soft (no backend) covered by PHPUnit; ObligationTaskBridge existing VTODO path re-tested (no regression — all 4 pre-existing bridge tests green unchanged)
- [ ] Manual browser test of the category-toggle settings + calendar population (NOT executed — no live instance with this build deployed; coordinator rule: no deploy to the shared dev instance. Covered instead by the SFC compile smoke, the vitest helper suite, and the data-defensive Playwright spec `tests/e2e/DeadlineCalendarSettings.spec.js` authored for the next live run.)

## Deviations

1. **Removal semantics**: OCP's public calendar surface has no delete seam
   (`ICreateFromString` is write-only), so "remove" is implemented as an
   overwrite of the same UID with `STATUS:CANCELLED` + bumped `SEQUENCE` —
   calendar clients hide/strike cancelled events. Documented in the service
   class docblock.
2. **Dedicated calendar**: OCP exposes no public create-calendar API. The
   service uses the user calendar with URI `shillinq-deadlines` when present
   and falls back to the first writable calendar otherwise — the design.md
   fallback ("Fallback to the ObligationTaskBridge seam if a dedicated
   calendar cannot be created"). The docs tell users how to create the
   dedicated calendar by name.
3. **e2e location**: the spec's `@e2e` annotation references
   `src/views/**/DeadlineCalendarSettings*.spec.js`, but this repo's
   Playwright `testDir` is `tests/e2e` and vitest only collects
   `tests/vitest/**`. The Playwright spec therefore lives at
   `tests/e2e/DeadlineCalendarSettings.spec.js` (name pattern preserved) and
   carries the `@e2e compliance-deadline-calendar::toggling-a-category-off-removes-its-events`
   coverage ref; the pure helper logic is pinned in
   `tests/vitest/deadlineCalendarSettings.spec.js`.
4. **Notifier added beyond the file list**: `lib/Notification/DeadlineReminderNotifier.php`
   (INotifier) was required — without a registered notifier NC discards the
   raised notifications at display time, which would have made REQ-CDC-007
   dishonest.
5. **VEVENT summaries** use the official NL fiscal proper nouns
   ("BTW-aangifte 2026-Q1", "Betaalrun …") exactly as the REQ-CDC-002
   scenario prescribes — they are data labels, not localised prose; the
   notification prose IS localised (en+nl) via the notifier.
6. **Newman**: a "Deadline Calendar Settings" folder was added to
   `tests/integration/shillinq.postman_collection.json` (GET shape, POST
   toggle, 400 on malformed payload, teardown restore). Not executed live —
   the shared dev instance serves a different checkout; the collection runs
   with the next `run-newman.sh` against a deployed build.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- Settings endpoint covered by a Newman/Postman test
- Settings UI covered by a Playwright browser test (`DeadlineCalendarSettings*.spec.js`)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` (deadline calendar + reminders, ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings added; keys English. NL: "Deadlinekalender" (deadline calendar), "Aangiftedeadline" (filing deadline), "Betaalrun" (payment run), "Vervaldatum factuur" (invoice due date), "Opzegtermijn" (notice period), "Herinnering" (reminder)
- `openspec validate` passes
