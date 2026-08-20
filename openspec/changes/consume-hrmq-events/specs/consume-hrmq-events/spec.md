# Spec: consume-hrmq-events (delta)

## ADDED Requirements

### Requirement: REQ-CHE-001 — Shillinq MUST consume hrmq's approved-timesheet notification via a typed in-process event, not the WebhookService HTTP path

Shillinq MUST register a listener against hrmq's typed
`OCA\Hrmq\Event\TimesheetApprovedEvent` (an `OCP\EventDispatcher\Event`
subclass dispatched via `IEventDispatcher::dispatchTyped()`), following the
same `IRegistrationContext::registerEventListener(event: <ProducerFQCN>::
class, listener: …)` pattern already used to consume docudesk's
`FinancialExtractionCompletedEvent` and pipelinq's `PosStockMovedEvent`.
Shillinq MUST NOT build a receiving HTTP controller for hrmq's existing
`nl.conduction.hrmq.timeentry.approved` `WebhookService` dispatch — no
consumer of that HTTP delivery mechanism exists anywhere in shillinq today,
and every existing cross-app event consumption in this codebase uses the
typed in-process pattern instead (design.md §1.1). The registration MUST be
safe when hrmq is not installed: `registerEventListener()` is called with
only the event class name as a string, and the listener's `handle()` MUST
`class_exists()`-guard the event type before touching it, so the listener is
inert — never throws, never breaks the approval save in hrmq — when hrmq is
absent.

@e2e exclude registration-site wiring, no rendered page — verified by
`Application::register()` diff inspection and the PHPUnit integration test's
class_exists()-absent-hrmq branch (REQ-CHE-005)

#### Scenario: The listener is registered against hrmq's typed event, not a webhook route

- **GIVEN** `lib/AppInfo/Application.php` after this change
- **WHEN** it is inspected
- **THEN** a `registerEventListener(event: \OCA\Hrmq\Event\
  TimesheetApprovedEvent::class, listener: HrmqTimesheetApprovedListener::
  class)` call is present, and no new route or controller for receiving an
  inbound `nl.conduction.hrmq.timeentry.approved` HTTP webhook exists
  anywhere in `appinfo/routes.php`

#### Scenario: The listener is inert when hrmq is absent

- **GIVEN** hrmq is not installed on this instance (the event class does not
  autoload)
- **WHEN** any OpenRegister object write occurs
- **THEN** `HrmqTimesheetApprovedListener::handle()` returns immediately
  without error, and no `UrenRegistratie` row is written or altered as a
  side effect of this listener

### Requirement: REQ-CHE-002 — Approved hours MUST project into `UrenRegistratie` as an idempotent, deferred upsert keyed on `(sourceApp, externalId)`

On receiving a `TimesheetApprovedEvent`, shillinq MUST defer the projection
work via OpenRegister's `ListenerDeferralService` (ADR-078 Rule 1 — this is
a post-event notification with no veto surface) rather than writing
`UrenRegistratie` synchronously inside `handle()`. The deferred job MUST
carry the acting user captured at dispatch time (ADR-078 Rule 6) and MUST
re-check for an existing row before writing (ADR-078 Rule 7 — at-least-once
delivery). The upsert MUST be keyed on `UrenRegistratie.sourceApp="hrmq"`
combined with `UrenRegistratie.externalId=<the hrmq Timesheet uuid>`: a
second delivery for the same timesheet id MUST update the existing row in
place, never create a duplicate. The projection MUST map the event's
`period` field to `UrenRegistratie.date` per the grain rules in design.md
§3 (day-grained periods map losslessly; week/month-grained periods use the
period's last day and record the raw period string in the row's
`description` so the coarser grain is visible, never silently disguised as
a precise day). A field the event carries that `UrenRegistratie` has no slot
for (`billable`, `clientRef`, `approvedBy`, `approvedAt`) MUST be logged,
never silently dropped without a trace and never invented a matching field
speculatively.

@e2e exclude backend projection logic, no rendered page — verified by the
PHPUnit integration test exercising the deferred job directly (REQ-CHE-005)

#### Scenario: A day-grained approved timesheet projects losslessly

- **GIVEN** a `TimesheetApprovedEvent` with `period: "2026-W21-3"`,
  `hours: 8`, `employeeId: "emp-42"`, `timesheetId: "ts-9001"`
- **WHEN** the deferred projection job runs
- **THEN** a `UrenRegistratie` row is upserted with `date` equal to the
  Wednesday of ISO week 21, 2026, `hours: 8`, `personId: "emp-42"`,
  `sourceApp: "hrmq"`, `externalId: "ts-9001"`

#### Scenario: A month-grained approved timesheet projects with a recorded precision note

- **GIVEN** a `TimesheetApprovedEvent` with `period: "2026-05"`,
  `hours: 160`, `timesheetId: "ts-9002"`
- **WHEN** the deferred projection job runs
- **THEN** a `UrenRegistratie` row is upserted with `date` equal to
  2026-05-31 (the period's last day), `hours: 160`, and `description`
  includes the raw period string `"2026-05"` so the month grain is visible

#### Scenario: A second delivery for the same timesheet updates, not duplicates

- **GIVEN** a `UrenRegistratie` row already exists with `sourceApp: "hrmq"`,
  `externalId: "ts-9001"`, `hours: 8`
- **WHEN** a second `TimesheetApprovedEvent` for `timesheetId: "ts-9001"`
  is delivered with `hours: 8.5` (a correction)
- **THEN** the existing row is updated to `hours: 8.5`; no second
  `UrenRegistratie` row for `externalId: "ts-9001"` is created

#### Scenario: Fields with no matching slot are logged, not fabricated

- **GIVEN** a `TimesheetApprovedEvent` carrying `billable: true` and
  `clientRef: "cust-77"`
- **WHEN** the deferred projection job runs
- **THEN** the upserted `UrenRegistratie` row carries no `billable` or
  `clientRef` value (no such fields exist on the schema), and the job's log
  line records both values for audit traceability

### Requirement: REQ-CHE-003 — Existing WBSO auto-tagging MUST apply to hrmq-projected rows identically to manually-entered rows

`UrenRegistratie`'s existing `wbsoAutoTag` `x-openregister-aggregations`
entry (triggered on `create`/`update`, auto-assigning `wbsoTagId`/
`activityCodeId`/`tagSource` from the parent `Project`) MUST require no
code change to fire on rows written by the hrmq projection job. The
projection MUST write through the same `ObjectService::saveObject()`
surface every other `UrenRegistratie` writer uses (manual entry, the
pipelinq billing-intake path) so the schema-declarative aggregation applies
uniformly regardless of writer, per ADR-031.

@e2e exclude confirms an existing declarative aggregation applies unchanged
— verified by PHPUnit asserting the aggregation fires on a projected row
(REQ-CHE-005)

#### Scenario: An hrmq-projected hour against a WBSO-tagged project auto-tags

- **GIVEN** a `Project` with `wbsoTagId: <SO>` and `activityCodeId: <A001>`
  both set
- **AND** a `TimesheetApprovedEvent` whose `projectId` matches that project
- **WHEN** the deferred projection job upserts the `UrenRegistratie` row
- **THEN** the row's `wbsoTagId` and `activityCodeId` are auto-assigned from
  the project and `tagSource` is `"auto"`, exactly as a manually-entered
  `UrenRegistratie` row against the same project would be

### Requirement: REQ-CHE-004 — Manual `UrenRegistratie` capture MUST remain fully functional as a fallback, never gated or removed

This change MUST NOT remove, disable, or conditionally hide the existing
`UrenRegistratie` create/edit affordances. When hrmq is absent, or for a
period an hrmq-approved timesheet does not cover, manual entry MUST continue
to work exactly as before this change (ADR-081 Decision 7 — a source app
degrading absence MUST never become a silent capability loss for the
consumer). The existing `UrenRegistratie` index page MUST gain a provenance
indicator distinguishing `sourceApp="hrmq"` rows from manually-entered rows,
without altering the page's existing filters, columns, or create/edit
actions.

@e2e consume-hrmq-events::hours-index-renders-projected-rows

#### Scenario: Manual entry still works with hrmq installed and active

- **GIVEN** hrmq is installed and has already projected several
  `UrenRegistratie` rows for the current period
- **WHEN** an operator manually creates a new `UrenRegistratie` row (e.g.
  backfilling a day hrmq has not yet covered)
- **THEN** the row is created exactly as it would be without this change
  installed, with `sourceApp` left unset

#### Scenario: The index page distinguishes hrmq-projected rows from manual ones

- **GIVEN** the `UrenRegistratie` index page with a mix of `sourceApp:
  "hrmq"` rows and rows with no `sourceApp`
- **WHEN** the page renders
- **THEN** hrmq-sourced rows show a provenance indicator; manually-entered
  rows do not; every existing column, filter, and the create/edit action
  remain present and unchanged

### Requirement: REQ-CHE-005 — Wiring MUST be verified by a PHPUnit integration test exercising the full listener→job→upsert path

An integration test MUST exercise `HrmqTimesheetApprovedListener` and its
deferred `HrmqTimesheetProjectionJob` end-to-end against a constructed
`TimesheetApprovedEvent`-shaped payload (or the real hrmq event class when
hrmq is present as a sibling checkout), asserting the resulting
`UrenRegistratie` row for each of the three `period` grains (REQ-CHE-002)
and the dedupe-on-`externalId` behaviour, without requiring a browser.

@e2e exclude backend integration proof, no browser-rendered surface —
PHPUnit `tests/Integration/HrmqTimesheetApprovedListenerIntegrationTest.php`
is the verifying artefact itself

#### Scenario: The integration test proves the full path against a live OR object store

- **GIVEN** the shillinq PHPUnit integration suite running against a real
  OpenRegister-backed test instance
- **WHEN** `HrmqTimesheetApprovedListenerIntegrationTest` runs
- **THEN** it asserts a day-grained, a week-grained, and a month-grained
  `TimesheetApprovedEvent`-shaped payload each produce the expected
  `UrenRegistratie` row, and that redelivering the same `timesheetId`
  updates rather than duplicates
