# Design: consume-hrmq-events

## 1. Consumption architecture

### 1.1 Why the typed-event pattern, not the webhook shillinq has no receiver for

Three consumption mechanisms exist across the fleet; only one is actually
exercised inside shillinq today.

| Mechanism | Delivery | Used by | shillinq has a receiver? |
|---|---|---|---|
| Typed `OCP\EventDispatcher\Event` subclass, `IEventDispatcher::dispatchTyped()`, same PHP request | in-process, synchronous dispatch | docudesk `FinancialExtractionCompletedEvent`, pipelinq `PosStockMovedEvent`, decidesk `DecisionRequestedEvent`/`DecisionConcludedEvent` — the ADR-041 "already shipped fleet-wide" mechanism | **Yes** — `ExtractionCompletedListener`, `PosStockDecrementListener`, `SigningDelegationRegistration`, all registered via `IRegistrationContext::registerEventListener(event: <ProducerFQCN>::class, listener: …)` in `lib/AppInfo/Application.php` |
| OpenRegister `WebhookService::dispatchEvent()` | admin-registered `Webhook` row (event name + target URL) → async `WebhookDeliveryJob` → outbound **HTTP POST** | hrmq's `TimeEntryEventService` (`nl.conduction.hrmq.timeentry.approved`), pipelinq's `ShillinqWipService` (cited in hrmq's own docblock) | **No** — grepped `lib/Controller/*.php` and `appinfo/routes.php` for any webhook-receiving surface that isn't a payment-gateway callback (`DepositWebhookController`, `PaymentRequestWebhookController`, the retiring `PayrollWebhookController`); none accepts a `nl.conduction.*` CloudEvent body |
| OpenRegister integration-registry leaf | `IntegrationProvider` CRUD, OR-internal | files/mail/calendar/contacts leaves (`integration-leaves-consume`) | N/A — ADR-041 explicitly rules this mechanism out for cross-app *commands* (§"What conflated the issue"); not applicable here regardless |

The middle row is hrmq's actual, verified choice
(`hrmq/lib/Service/TimeEntryEventService::dispatch()` →
`$this->container->get('OCA\OpenRegister\Service\WebhookService')->
dispatchEvent(...)`, read in full — no `IEventDispatcher` call anywhere in
that class or `TimesheetApprovalListener`). It is architecturally sound for
an *external* subscriber (a genuinely separate service, off-instance,
admin-configuring its own webhook URL) but wrong-shaped for a same-instance
sibling app: it requires an operator to hand-register a loopback `Webhook`
row pointing shillinq's own instance at itself, requires shillinq to expose
and authenticate an inbound HTTP endpoint for a call that never leaves the
box, and routes a same-process notification through a cron-polled job queue
for no latency reason (contrast `ListenerDeferralService`, which defers
*handler work*, not the *notification itself* — the notification here would
be deferred twice: once by `WebhookDeliveryJob`, then again by this change's
own `ListenerDeferralService` use inside the receiving controller).

Every existing shillinq cross-app consumer instead uses the top row. This
design follows that established, working precedent rather than building a
receiver for a mechanism nothing else in this repo receives on. Concretely:
hrmq's `TimeEntryEventService` gains a typed
`OCA\Hrmq\Event\TimesheetApprovedEvent` (implements
`OCP\EventDispatcher\Event`) dispatched via `IEventDispatcher::dispatchTyped()`
at the exact same approval edge `maybeDispatchApproved()` already gates —
additive, alongside its existing `WebhookService` call, which keeps serving
genuine external subscribers. This is cross-repo task 1 (proposal.md) and
this change's own listener is written against that event's expected shape.

### 1.2 The listener

```
HrmqTimesheetApprovedListener (lib/Listener/)
  implements IEventListener<Event>
  handle(Event $event):
    if class_exists(OCA\Hrmq\Event\TimesheetApprovedEvent::class) === false: return
    if !($event instanceof that class): return
    defer to ListenerDeferralService (ADR-078 Rule 1 — this is a *ed-shaped
      post-approval notification with no veto surface, exactly the deferral
      target class the ADR names)
```

Registered exactly like `ExtractionCompletedListener` and
`PosStockDecrementListener`:

```php
$context->registerEventListener(
    event: \OCA\Hrmq\Event\TimesheetApprovedEvent::class,
    listener: HrmqTimesheetApprovedListener::class
);
```

in `Application::register()` — **not** `boot()`. ADR-078 Rule 5's `boot()`
requirement is specifically about a `class_exists()` guard evaluated *at
registration time* on an OpenRegister symbol, because `Coordinator::
registerApps()` enables each app's autoloader immediately before calling
its `register()`, so an early-alphabetical app's `register()` sees
`class_exists() === false` for a later-loading OpenRegister class. Here the
registration call passes only a **string** (`::class` on an interface method
parameter is a compile-time string literal, never resolved) to
`registerEventListener()`, exactly as `ExtractionCompletedListener` already
does for docudesk in `register()` today — proof by existing precedent that
this registration shape is safe there. The `class_exists()` check that
*does* matter runs inside `handle()`, at dispatch time, long after every
app's `register()` has completed — matching `ExtractionCompletedListener`'s
own comment ("Registering by the docudesk event FQCN is safe even when the
class is not autoloadable — NC only needs the string key").

### 1.3 The deferred job

Per ADR-078 Rule 1 (post-event listener work is asynchronous by default) and
Rule 6 (a deferred path must carry the acting user), the listener does not
write `UrenRegistratie` inline. It calls:

```php
$this->listenerDeferralService->defer(
    jobClass: HrmqTimesheetProjectionJob::class,
    entry: ['timesheetId' => ..., 'employeeId' => ..., 'period' => ...,
            'hours' => ..., 'billable' => ..., 'projectId' => ...,
            'costCenter' => ..., 'clientRef' => ..., 'approvedBy' => ...,
            'approvedAt' => ...],
    dedupeKey: $timesheetId,
);
```

`HrmqTimesheetProjectionJob extends OCA\OpenRegister\BackgroundJob::
ActorForwardedJob` (the first shillinq adopter of `ListenerDeferralService` —
grepped: no shillinq class references it today, despite ADR-078's own
fleet-adoption table crediting shillinq with 15 pre-existing listener
registrations, none yet migrated; this is new-code adoption, not a
migration of those 15). `runDeferred()` re-establishes the captured actor
(the manager who approved the timesheet in hrmq, if resolvable on this
instance — see §1.4 for the cross-instance identity caveat) and performs the
upsert described in §3.

This is a departure from the docudesk/pipelinq precedents cited in §1.1,
which both write synchronously inside `handle()` (`ExtractionCompletedListener::
apply()`, `PosStockDecrementListener`'s direct delegation to
`SalesDispatchStockIssueService`). That is deliberate, not an inconsistency:
those listeners perform a single bounded `saveObject()`/service delegation
each; ADR-078 Rule 3's `cheap-bounded` category exists precisely for
"one bounded statement… a job row + cron round-trip costs more than the
work." A single `UrenRegistratie` upsert is arguably also `cheap-bounded` —
but ADR-078 was written and gated *after* those two listeners shipped, so
they are not proof of the currently-sanctioned default; this change is
written against the ADR that governs new code today, which defaults new
post-event work to deferred and requires a reason-bearing exception to run
inline. **REQ-CHE-002 is written against the deferred default.** If a future
implementer measures the inline cost as trivially `cheap-bounded` and wants
to skip the job/cron hop, that is a Rule 3 annotation on the handler method
naming the category and the measurement — not a silent choice either way.

### 1.4 Cross-instance identity caveat

hrmq and shillinq are both Nextcloud apps and, per this fleet's deployment
model, run inside the **same** Nextcloud instance (every existing
same-instance-only consumer precedent in §1.1 confirms this — `class_exists()`
on a sibling app's PHP class only ever resolves when both apps share one
autoloader/instance). `ListenerDeferralService::captureActor()` therefore
captures the NC session user active in the request that saved the approval
in hrmq — the manager who clicked "approve" — and `ActorForwardedJob`
re-establishes that same NC user for the projection write. No cross-instance
token exchange is needed; this is exactly the same identity model
`ActorForwardedJob`'s own docblock describes for any OR-object listener,
applied here to a cross-app typed event instead.

## 2. UrenRegistratie's role

### 2.1 What "canonical when hrmq is present" means concretely

`UrenRegistratie` is **not** demoted to a separate, differently-shaped
read-cache schema. Correction 4 (proposal.md) already established that its
existing provenance triple (`externalId`/`sourceApp`/`sourceBatchId`, added
by `time-expense-invoice-intake` for pipelinq) is generic enough to carry
hrmq's identity too. This keeps every existing consumer
(`invoice-from-time-and-expense`'s `InvoiceGenerationService`,
`wbso-uren-tagging-and-export`'s `wbsoAutoTag` aggregation,
`subject-cost-aggregation`'s `HrmqCostRateAdapter` pairing, the
zzp-urencriterium-tracker's `sum(UrenRegistratie.hours …)` aggregation-ref)
working unmodified — none of them branch on `sourceApp`, they all read
`hours`/`personId`/`date` generically.

"Canonical" is therefore a **policy + UI** statement, not a schema fork:

- A row with `sourceApp="hrmq"` is upserted by the projection job, never
  hand-edited (the existing detail page keeps rendering it; if OR's
  RBAC/lifecycle layer needs a read-only guard on hrmq-sourced rows, that is
  a follow-on UX decision, not blocking this change — a hand-edit today
  simply gets overwritten on the next hrmq approval for the same
  `externalId`, which is honest idempotent-upsert behaviour, not silent data
  loss).
- The existing `UrenRegistratie` index page (manifest.json line ~11255,
  `src/manifest.json`) gains a provenance column/badge distinguishing
  `sourceApp="hrmq"` rows from manually-entered ones (`sourceApp` unset).
  This is the only manifest edit this change makes to that page.
- Manual capture (the existing create/edit affordance) is **not removed or
  gated off**. Per ADR-081 Decision 7 ("a source app MUST degrade gracefully
  when the receiver is absent") applied to this consuming direction: when
  hrmq is not installed, or for any period before hrmq's rollout, manual
  entry is the only way hours reach `UrenRegistratie` and must keep working
  exactly as today. This proposal declares manual entry **fallback-only**
  in documentation/UI language ("hrmq-tracked hours project here
  automatically when hrmq is installed; use manual entry only when hrmq is
  absent or for historical backfill") — not a code-level lockout, because a
  hard lockout would itself violate "degrade gracefully" the moment hrmq's
  webhook/event pipeline has a bad day.

### 2.2 Why not a separate projection schema

A leaner, hrmq-specific read-cache schema (e.g. `HrmqTimesheetProjection`)
was considered and rejected: every downstream consumer already reads
`UrenRegistratie` by its existing fields, and introducing a second schema
would require either (a) teaching four existing specs' aggregations/services
to union two schemas, or (b) a second sync step copying the projection into
`UrenRegistratie` anyway — strictly more code for no behavioural gain. The
generic provenance triple already does the job a bespoke schema would add.

## 3. Field mapping and the period-granularity precision loss

| hrmq `TimesheetApprovedEvent` field | `UrenRegistratie` field | Notes |
|---|---|---|
| `timesheetId` | `externalId` | idempotency key together with `sourceApp` |
| — | `sourceApp` | literal `"hrmq"` |
| `employeeId` | `personId` | direct |
| `hours` | `hours` | direct |
| `billable` | — | not persisted; `UrenRegistratie` has no `billable` field today. Not added by this change (no consumer reads it) — flagged as an open question (§5) rather than silently dropped or speculatively added |
| `projectId` | `projectId` | direct |
| `costCenter` | `costProjectId` | best-effort: `costCenter` is a free string on hrmq's side, `costProjectId` is an FK to shillinq's `AnalyticalDimension` (project-flavoured, post-`retire-cost-project`). No resolution/matching service is built by this change — the projection stores the raw value only when it already resolves to an existing `AnalyticalDimension` id; otherwise the field is left null and the raw value is logged, never guessed |
| `clientRef` | — | not persisted; no matching field. Logged, not dropped silently, same as `billable` |
| `description` | `description` | direct — `UrenRegistratie.description` is required, so a blank hrmq description falls back to a fixed string noting the hrmq origin, never blocking the upsert on a required-field validation error |
| `approvedBy` / `approvedAt` | — | not persisted on `UrenRegistratie` itself (no such fields exist); recorded in the projection job's log line for audit traceability, per OR's own audit-trail-immutable capture of the `saveObject()` call (ADR-022 — every write already gets an audit-trail row with actor/timestamp regardless) |
| `period` | `date` | **see below — precision loss** |

### `period` → `date`

hrmq's `period` is `YYYY-MM` (month), `YYYY-Www` (ISO week), or
`YYYY-Wnn-D` (single day) — verified against `hrmq/lib/Settings/
register.d/hr-timesheet.json`. `UrenRegistratie.date` is a single calendar
date, required.

- **`YYYY-Wnn-D` (day grain)**: resolves directly and losslessly to a
  calendar date via the ISO week/weekday. No precision loss.
- **`YYYY-Www` (week grain) / `YYYY-MM` (month grain)**: no single calendar
  date exists. This design uses the **period's last day** (end of the ISO
  week / end of the month) as `UrenRegistratie.date` — matching this
  repo's own period-end convention used elsewhere (e.g. fiscal-period
  closing dates) — and additionally stores the **raw hrmq `period` string**
  in `UrenRegistratie.description`'s trailing provenance note (e.g.
  `"hrmq timesheet — period 2026-05"`) so the coarser grain is visible to a
  human reading the row, not silently disguised as a precise day.

This is an honest approximation, not a fix: a month-grained approved
timesheet folded onto one calendar date will overstate that single day's
hours in the urencriterium daily tally (`zzp-urencriterium-tracker`
REQ-URC-001's "dagelijkse rolling tally") and in WBSO's per-day CSV export
column (`wbso-uren-tagging-and-export` REQ-WBSO-007's `Date (ISO 8601)`
column) — both read `UrenRegistratie.date` per row and have no way to know
the true grain without inspecting the description text. **This is recorded
as an open question and cross-repo task, not silently absorbed**: the real
fix is either hrmq converging on day-grained capture (cross-repo task 3,
proposal.md) or `UrenRegistratie` growing an explicit optional
`sourcePeriodGrain` field a future change can add without breaking this
one (additive, not built here because no consumer needs it yet and
speculative fields are exactly what `time-expense-invoice-intake`'s own
"deliberately does NOT declare a required key" note warns against
over-building).

## 4. WBSO tagging — reference shape, no new mechanism

`UrenRegistratie`'s existing `wbsoAutoTag` aggregation
(`x-openregister-aggregations`, triggered on `create`/`update`) already
fires on **any** write to the schema regardless of writer — it inspects the
parent `Project.wbsoTagId`/`activityCodeId` and auto-assigns
`wbsoTagId`/`activityCodeId`/`tagSource` on the row being written. The
projection job's upsert is a plain `saveObject()` call through the same
`ObjectService` surface every other writer uses (manual entry, the pipelinq
billing-intake path), so this aggregation requires **zero code change** to
apply identically to hrmq-projected rows: if the hrmq-approved timesheet's
`projectId` resolves to a `Project` carrying WBSO metadata, the projected
row is auto-tagged exactly as a manually-entered row against the same
project would be. REQ-CHE-003 specs this as a confirming scenario, not a
new capability.

## 5. Open questions

1. **`billable` and `clientRef`** (§3) — should `UrenRegistratie` grow these
   fields so hrmq-approved billable/client context survives the projection,
   or is `projectId` (already resolvable to a billing context via
   `invoice-from-time-and-expense`) sufficient? No current consumer reads
   either field, so this change does not add them; flagged for whoever
   builds the first consumer that needs them.
2. **`costCenter` string-to-`AnalyticalDimension` matching** (§3) — should
   this change (or a follow-on) build a resolution service, or is
   logging-and-nulling the unmatched case acceptable indefinitely? Depends
   on how often hrmq's free-text `costCenter` actually diverges from
   shillinq's `AnalyticalDimension` codes in practice — unmeasured, because
   no hrmq-projected rows exist yet to measure against.
3. **`period` granularity** — cross-repo task 3 (proposal.md): converge
   hrmq on day-grained capture, or teach `UrenRegistratie` an explicit
   grain marker?
4. **Read-only enforcement on hrmq-sourced rows** — should OR's RBAC/
   lifecycle layer actively prevent a hand-edit of a `sourceApp="hrmq"` row
   (rather than allowing an edit that a later approval silently overwrites)?
   Left as a UX follow-on; not blocking, per §2.1.
5. **`ExpenseClaimEntry`'s eventual fate** — blocked on hrmq shipping
   `nl.conduction.hrmq.expense.approved` (cross-repo task 2) and a product
   decision on whether shillinq's fiscal-flavoured claim workflow is worth
   keeping as a genuinely separate thing even once hrmq's event exists (its
   `settlementMode`/pass-through-markup machinery has no hrmq equivalent at
   all — `hrmq-expenses` has no concept of billing a client through an
   expense). This is a bigger question than a straight retire-and-consume
   and deliberately not answered by this change.

## 6. e2e coverage

Per gate-19 (`hydra-gate-e2e-coverage`), every ADDED scenario in the spec
delta needs either an `@e2e <slug>::<scenario>` tag with a matching
Playwright spec, or a reason-bearing `@e2e exclude`.

1. **PHPUnit integration test** (`tests/Integration/
   HrmqTimesheetApprovedListenerIntegrationTest.php` — this repo's
   `tests/Integration/` directory is a flat directory of
   `*IntegrationTest.php` files, e.g. `Ifrs15RevenueIntegrationTest.php`,
   `SupplierInvoicePeppolIngestionIntegrationTest.php`; matching that
   existing naming/flat-layout precedent rather than `tests/integration/`
   — lower-case, Postman/Newman collections only — or `tests/Unit/
   Integration/`, an unrelated older suite) constructs a
   test double of `TimesheetApprovedEvent`'s expected shape (or, if hrmq's
   cross-repo task 1 has landed and hrmq is available as a sibling checkout
   in CI, the real class), dispatches it through `IEventDispatcher`, and
   asserts the deferred job — run synchronously in the test per this
   repo's usual job-under-test pattern — upserts the expected
   `UrenRegistratie` row, including the dedupe-on-`externalId` and the
   period-grain mapping (§3) for all three grains. `@e2e exclude` —
   event-consumption plumbing has no rendered page; reason matches the
   precedent set by `payroll-leaves-to-hrmq` REQ-PLH-004's identical
   backend-only exclusion.
2. **Playwright** (`tests/e2e/consume-hrmq-events.spec.ts`, new file):
   `consume-hrmq-events::hours-index-renders-projected-rows` — seeds an
   `UrenRegistratie` row with `sourceApp="hrmq"` directly via the OR API
   (not through the listener — this spec asserts the **rendering** contract,
   not the consumption plumbing PHPUnit already covers), opens the hours
   index page, and asserts the row renders with its provenance
   badge/column and that manual-entry affordances are still present and
   functional (fallback-only, not removed — §2.1).

No coverage is written for the expense side (§ "Expenses: gap-flagged" in
proposal.md) — there is no implemented behaviour to test.
