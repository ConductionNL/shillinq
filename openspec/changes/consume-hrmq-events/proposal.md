# Change: consume-hrmq-events

## Why

Wave 2 of the shillinq↔hrmq boundary: hrmq owns hours capture (`Timesheet`,
submit→approve lifecycle, `NoSelfApprovalGuard`) and expense-claim
capture/approval (`Expense`, the same lifecycle shape). hrmq already emits
`nl.conduction.hrmq.timeentry.approved` on the approval edge
(`time-entry-capture` REQ-TEC-002/003) — **this event currently has no
consumer anywhere in the fleet.** Meanwhile shillinq carries its own
`UrenRegistratie` capture register with no submit/approve lifecycle at all (a
flat log, verified against `lib/Settings/shillinq_register.json`), and a full
second capture-plus-approval workflow for expenses
(`ExpenseClaimEntry.approvalState`, `draft→submitted→approved→posted→
reimbursed`, `lib/Lifecycle/ExpenseClaimGuard.php`) that duplicates the shape
hrmq's own `Expense` lifecycle now owns.

Per hydra ADR-081 (`money-and-effort-ownership`), the split is fixed: hrmq
supplies the wage base and owns effort capture (§6, "Effort is recorded
against the domain object and costed by hrmq"), shillinq aggregates and owns
the ledger. `UrenRegistratie` should be the read-side landing zone for
hrmq-approved hours, not a second place to type them in.

## ⚠️ Reality check — five corrections against the assumed shape, re-verified against code

1. **hrmq's checkout is on `chore/openspec-archive`, not
   `test/local-integration`** as the triage brief stated (`git branch
   --show-current` in
   `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/hrmq`,
   2026-08-20). Working tree clean; only an untracked
   `tests/fixtures/manifest-baseline/` present. hrmq's `development` branch
   was not separately checked out — this proposal reads hrmq's specs and
   `lib/` from whatever `chore/openspec-archive` has checked out, which is the
   only hrmq state available in this environment. If `development` has since
   diverged (e.g. the events described in `time-entry-capture` were reverted
   or reshaped there), the cross-repo tasks in this proposal must be
   re-verified against it before implementation.

2. **hrmq's event delivery mechanism is HTTP webhooks, not the in-process
   typed-event pattern shillinq already uses to consume every other
   cross-app producer.** `hrmq/lib/Service/TimeEntryEventService::dispatch()`
   calls `OCA\OpenRegister\Service\WebhookService::dispatchEvent()`, which
   (read in full at
   `openregister/lib/Service/WebhookService.php:634` and
   `openregister/lib/BackgroundJob/WebhookDeliveryJob.php`) looks up
   admin-registered `Webhook` rows matching the event name and enqueues an
   **outbound HTTP POST** per match — there is no in-process
   `IEventDispatcher::dispatchTyped()` call anywhere in that path. Every
   existing shillinq consumer of a sibling app's event
   (`ExtractionCompletedListener` for docudesk's
   `FinancialExtractionCompletedEvent`, `PosStockDecrementListener` for
   pipelinq's `PosStockMovedEvent`, both registered via
   `IRegistrationContext::registerEventListener(event: <ProducerFQCN>::class,
   …)` in `lib/AppInfo/Application.php`) instead consumes a **typed
   `OCP\EventDispatcher\Event` subclass** the producer dispatches
   same-process — the ADR-041-sanctioned "already shipped fleet-wide"
   mechanism (decidesk/docudesk). hrmq's `Timesheet` approval currently has
   no such typed event, only the webhook. **Shillinq cannot consume
   `nl.conduction.hrmq.timeentry.approved` via its own house idiom until
   hrmq adds one** — see cross-repo task 1 below. This proposal specs the
   consuming side against the typed event hrmq needs to add (mirroring
   `TimeEntryEventService::buildApprovedEvent()`'s existing payload,
   additive, alongside — not instead of — the existing webhook dispatch for
   genuine external subscribers), not against a same-instance HTTP round
   trip nothing in this codebase does today.

3. **hrmq's `Timesheet.period` is polymorphic-grain
   (`YYYY-MM` | `YYYY-Www` | `YYYY-Wnn-D`), not a calendar date** — verified
   against `hrmq/lib/Settings/register.d/hr-timesheet.json`. `UrenRegistratie`
   requires a single `date` (day grain). A month- or week-grained approved
   timesheet cannot honestly become one dated hour row without inventing a
   date. design.md §3 covers the mapping and the precision loss this implies
   for WBSO's per-day CSV export and the urencriterium daily tally.

4. **`UrenRegistratie` already has a generic provenance shape built for
   exactly this purpose** — `externalId` / `sourceApp` / `sourceBatchId`,
   added by `time-expense-invoice-intake` for pipelinq's billing-intake batch
   (`lib/Settings/register.d/time-expense-invoice-intake.json`). Their
   descriptions name pipelinq as the example, not the constraint: `sourceApp`
   is a free string. **No new schema fields are needed to key hrmq-sourced
   rows** — `sourceApp: "hrmq"` + `externalId: <Timesheet uuid>` is the
   idempotency key, following the identical pattern already proven for
   pipelinq. This lowers this change's schema footprint from "new fields" to
   "one new accepted value for an existing field."

5. **hrmq's `hrmq-expenses` capability has no approval event at all** —
   grep of hrmq's `lib/`, `openspec/specs/hrmq-expenses/spec.md`, and every
   `CloudEvent`/`nl.conduction.hrmq.expense` mention returns zero matches
   outside `time-entry-capture`. `hrmq-expenses` REQ-1 explicitly states
   *"The pipelinq-specific shillinq accounts-payable sync (fields, service,
   listener, controller) MUST NOT be ported"* — hrmq deliberately did not
   build a shillinq hand-off for expenses when it built `Expense`. There is
   therefore **no event to consume for expenses today**, symmetric to
   `payroll-leaves-to-hrmq`'s `SalarisFeed` gap (design.md §4 there: retire
   verdict recorded, deletion gated on a cross-repo resolution). This
   proposal's expense scope is correspondingly gap-flagged, not implemented —
   see "Coordination with `payroll-leaves-to-hrmq`" below.

## What Changes

1. **Wire the orphan hours event** (once hrmq adds the typed-event
   counterpart — cross-repo task 1): a new shillinq listener consumes
   hrmq's approved-timesheet notification and projects it into
   `UrenRegistratie` as an idempotent upsert keyed on
   `(sourceApp="hrmq", externalId=<Timesheet uuid>)`, deferred per ADR-078
   via OpenRegister's `ListenerDeferralService` (the sanctioned mechanism —
   no shillinq listener currently uses it, so this is the first adopter, not
   a migration of existing code).
2. **`UrenRegistratie` becomes hrmq's projection surface, not a second
   capture UI, when hrmq is present** — additive: no fields are removed, no
   existing manual-entry page is deleted. hrmq-sourced rows are
   distinguished by `sourceApp="hrmq"`; the existing pages gain a read-only
   provenance badge and (per ADR-081 rule 7, "degrade gracefully") shillinq's
   own manual capture keeps working unchanged when hrmq is absent or a
   period predates hrmq's rollout — declared explicitly as fallback-only,
   not silently deprecated.
3. **WBSO tagging stays shillinq's** (fiscal classification), applied to
   hrmq-owned hours exactly as it is applied to manually-entered hours today
   — `UrenRegistratie`'s existing `wbsoAutoTag` aggregation
   (`x-openregister-aggregations`, triggered on create/update regardless of
   writer) requires no change; this proposal only confirms and specs that it
   fires identically for hrmq-projected rows.
4. **Expenses: gap-flagged, not implemented.** `payroll-leaves-to-hrmq`
   (sibling change, read in full) does not touch `ExpenseClaimEntry` at all —
   it only confirms `ExpenseSettlementClassifier` /
   `ReimbursementPolicy` / `PassThroughMarkupRule` (the fiscal
   tax-classification layer, `expense-reimbursement-or-passthrough`) as
   KEEP, unchanged. `ExpenseClaimEntry`'s own capture+approval duplication of
   hrmq's `Expense` lifecycle is this change's to own — but hrmq emits no
   approval event for it (correction 5), so there is nothing to consume yet.
   This proposal records the duplication, defers `ExpenseClaimEntry`'s fate
   to the same gap-flagged treatment `SalarisFeed` got, and hands hrmq's
   missing `nl.conduction.hrmq.expense.approved` event back as cross-repo
   task 2.
5. **e2e**: one PHPUnit integration test exercising the listener against a
   simulated approved-timesheet payload end-to-end into a projected
   `UrenRegistratie` row (reason-bearing `@e2e exclude` — event-consumption
   plumbing, not a rendered page), plus one Playwright assertion that the
   hours index page still renders and shows hrmq-projected rows correctly
   provenance-badged.
6. **Cross-repo tasks handed back**, not implemented here (see "Cross-repo
   tasks").

## Coordination with `payroll-leaves-to-hrmq`

Read in full (`openspec/changes/payroll-leaves-to-hrmq/{proposal,design,
tasks}.md` and its two spec deltas) before scoping this change's expense
item. Findings:

- It does **not** reference `ExpenseClaimEntry`, `ExpenseClaimGuard`, or any
  expense-capture retirement anywhere in its text. Its only expense-adjacent
  action is a **relocation-target edit**: `ExpenseSettlement`'s
  `menu-layout.json` group moves from the disappearing `Payroll` group to
  `Bookkeeping` (design.md §9), because `ExpenseSettlementClassifier` /
  `ReimbursementPolicy` / `PassThroughMarkupRule` are explicitly **KEEP,
  unchanged** (design.md §1 table) — the fiscal markup/classification layer,
  not the capture-and-approval workflow.
- It therefore does **not** claim expense-capture retirement in any form.
  Per this task's own instruction, this means `consume-hrmq-events` is free
  to own `ExpenseClaimEntry`'s eventual retirement/demotion — it does not
  need to narrow its own scope to hours-only to avoid double-covering
  ground `payroll-leaves-to-hrmq` already claimed.
- **What this change actually does with that freedom is limited by hrmq's
  own gap (correction 5 above)**: with no `nl.conduction.hrmq.expense.*`
  event to consume, there is nothing to wire. This proposal therefore
  scopes itself to **hours** for the wired, implemented half, and records
  the expense duplication + the missing hrmq event as a named, explicit
  gap — the same "retire verdict recorded, deletion gated on cross-repo
  resolution" treatment `payroll-leaves-to-hrmq` gives `SalarisFeed` (its
  design.md §4), not a silent scope-out and not a forced implementation
  against an event that does not exist.
- Sequencing: independent. Neither change's tasks.md edits a file the other
  touches (`payroll-leaves-to-hrmq` never touches `UrenRegistratie`,
  `ExpenseClaimEntry`, `ExpenseClaimGuard`, or any hours/expense manifest
  page; this change never touches the `Payroll`/`Loonadministratie`
  registers or nav group). Either may land first.

## Impact

- **Affected specs**: new capability `consume-hrmq-events`
  (`specs/consume-hrmq-events/spec.md`). No delta to
  `wbso-uren-tagging-and-export`, `invoice-from-time-and-expense`,
  `subject-cost-aggregation`, or `zzp-urencriterium-tracker` — all four
  consume `UrenRegistratie` by id/personId/hours without caring which writer
  produced a row, so their normative text is unaffected by this change
  (verified: none reference `sourceApp` or assume manual-entry-only).
- **Affected code (shillinq)**: `lib/Settings/register.d/` — one new
  fragment declaring `UrenRegistratie.sourceApp` enum note extension (docs
  only — the field already accepts any string) is unnecessary; the schema
  itself needs no edit. New: `lib/Listener/HrmqTimesheetApprovedListener.php`,
  `lib/BackgroundJob/HrmqTimesheetProjectionJob.php` (extends OR's
  `ActorForwardedJob`), registration in `lib/AppInfo/Application.php`
  (`register()`, mirroring `ExtractionCompletedListener`'s registration
  site — see design.md §2 for why `boot()` is not required here).
- **Affected code (hrmq, cross-repo, not implemented by this change)**: a
  typed `OCA\Hrmq\Event\TimesheetApprovedEvent` dispatched via
  `IEventDispatcher::dispatchTyped()` from `TimeEntryEventService`,
  additive alongside the existing `WebhookService` dispatch.
- **Byte budget**: no new manifest page; the existing `UrenRegistratie` index
  page gains a provenance column (small, additive UI change, not a new
  page — no material byte-budget impact; implementer still runs
  `node tests/check-manifest-budget.js` per house convention).
- **Cross-repo, explicitly flagged**, handed to the orchestrator: see
  "Cross-repo tasks" below.

## Cross-repo tasks

1. **hrmq: add a typed `TimesheetApprovedEvent`** dispatched via
   `IEventDispatcher::dispatchTyped()` at the same approval edge
   `TimeEntryEventService::maybeDispatchApproved()` already gates, carrying
   the same fields `buildApprovedEvent()` already computes. Additive — the
   existing `WebhookService` CloudEvent dispatch is unrelated and untouched
   (it serves genuinely external/admin-configured subscribers; the typed
   event serves same-instance sibling apps, per ADR-041). **Blocks this
   change's own listener from being wired** — REQ-CHE-001 is written against
   the typed event's expected shape and cannot be implemented until it
   exists.
2. **hrmq: `nl.conduction.hrmq.expense.approved` gap** — no event exists for
   `Expense` approval today (correction 5). Until hrmq adds one (matching
   the timesheet precedent, once corrected per task 1 to be a typed event),
   `ExpenseClaimEntry`'s retirement/demotion cannot be implemented — same
   treatment as `payroll-leaves-to-hrmq`'s `SalarisFeed` gap. Requires a
   product decision first: does shillinq's `ExpenseClaimEntry`
   capture+approval get retired in favour of hrmq's `Expense` once the event
   exists, or does it stay as a genuinely separate fiscal-first workflow?
   This proposal does not answer that — it only establishes that the
   question is blocked on the event existing at all.
3. **hrmq: `Timesheet.period` granularity** — confirm whether hrmq's product
   direction is to converge on day-grained (`YYYY-Wnn-D`) capture as the
   default (matching shillinq's day-grained `UrenRegistratie` and most other
   fleet time-tracking), or whether month/week-grained timesheets are a
   permanent, intended capability. This change's mapping (design.md §3)
   works either way but the precision loss for month/week grain is real and
   worth a product answer, not just an engineering workaround.
4. **hrmq: re-verify against `development`** — this proposal was written
   against hrmq's `chore/openspec-archive` checkout (correction 1); the
   implementer must re-read `time-entry-capture`'s spec and
   `TimeEntryEventService`/`TimesheetApprovalListener` against hrmq's actual
   `development` HEAD before building, in case either branch has diverged.
