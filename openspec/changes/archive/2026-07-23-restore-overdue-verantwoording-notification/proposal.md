# Restore the overdue SubsidieVerantwoording notification

## Why

Issue #505 (surfaced while narrowing/archiving
`migrate-legacy-notification-dialect`): the imperative
`OverdueVerantwoordingJob` was deleted on `development`, but its declarative
replacement was never built — `migrate-legacy-notification-dialect` tasks
3.1/3.2 deferred it because `SubsidieVerantwoording` had no `awardDate`
field and OpenRegister's `CalculationAnnotationValidator` has no
string-split operator to extract the `reportingPeriod` start date from the
composite `"2024-01-01 to 2024-12-31"` string.

**Net effect: overdue accountability-report notifications currently do not
fire at all.** A finance officer or subsidie-coordinator with a report
stuck in `draft`/`submitted`/`approved` for months past the grant award date
gets no reminder — a real regression from the imperative job's daily
24h re-check.

## What changes

This is issue #505's option **(a)**, the honest fix: add a real field
instead of extending the calc engine.

- **`SubsidieVerantwoording.awardDate`** (new, nullable `string`/`format:
  date`): a grant-award-date snapshot, following the exact same pattern
  already used for `awardedAmount` ("snapshot copied from the Subsidie
  grant... without a cross-schema join"). Not required — existing rows are
  not backfilled, and the null case is handled explicitly (see below).
- **`x-openregister-calculations.daysSinceAward`** (materialised, integer):
  `{"diffDays": [{"now": []}, {"prop": "awardDate"}]}` — elapsed whole days
  since award. `diffDays` returns `null` when `awardDate` is null/unparseable
  (verified against `CalculationEvaluator::diffDays()`).
- **`x-openregister-calculations.isOverdue`** (materialised, boolean):
  `{"and": [{"ne": [status, "final"]}, {"gt": [daysSinceAward, 90]}]}` — a
  1:1 port of the retired `OverdueVerantwoordingJob::isOverdue()`'s two
  conditions (non-final status; >90 days elapsed). `gt` is null-safe
  (`CalculationEvaluator::compare()`), so a null `daysSinceAward` (i.e. a
  null `awardDate`) resolves `isOverdue` to `false` — documented, not
  guessed. No reportingPeriod-split fallback is implemented (no calc op
  exists for it; adding one is explicitly out of scope for this change).
- **`x-openregister-notifications.onOverdue`**: `trigger: {type: scheduled,
  intervalSec: 86400, filter: {isOverdue: true}}`, recipient `{kind: field,
  field: approverUserId}` — restores the retired job's daily cadence and
  exact recipient resolution (the job read `record['approverUserId']`
  directly; the `field` recipient kind is the declarative equivalent,
  including the "empty field → zero recipients" fail-closed behaviour the
  job also had).
- **`x-openregister-lifecycle.final: ["final"]`** — declares the terminal
  state so OpenRegister's `TemporalCalculationSweepJob` (the generic
  mechanism that keeps `now`-dependent materialised calcs live without an
  object write) skips already-finalised records instead of rewriting them
  every sweep.
- **Bug fix alongside the restore**: every notification rule on this schema
  (`SubsidieVerantwoording` + `AuditorStatement`, 7 occurrences) declared
  `channels: ["in-app", ...]`. `"in-app"` is not a recognised channel —
  `NotificationAnnotationValidator::VALID_CHANNELS` and
  `AnnotationNotificationDispatcher` both only recognise the literal
  `"nc-notification"` — so none of these rules ever actually rendered an
  in-app Nextcloud notification (only the `email` channel, where present,
  worked). Fixed to `"nc-notification"` in the same file this change
  touches; the same bug exists in 2 other register.d files, out of scope
  here (follow-up issue filed).

## How isOverdue stays live without an object write

`daysSinceAward`'s expression references `{"now": []}`, so
`TemporalCalculationSweepService::hasTemporalCalculation()` flags this
schema as temporal and its hourly sweep re-evaluates + re-saves every
non-`final` `SubsidieVerantwoording` whose derived value changed — the same
generic mechanism the DSAR deadline-escalation work built. `ScheduledNotificationJob`
(60s TimedJob) then evaluates the `onOverdue` rule's `filter:
{isOverdue: true}` against each schema's stored objects every `intervalSec`
(86400s here) and dispatches through the existing
`AnnotationNotificationDispatcher` — zero new notification machinery.

Pre-existing records self-heal via the hourly sweep (default interval
3600s) the first time it runs after this change deploys; no bespoke repair
step is added (`RematerialiseConvertedCalculations` is scoped by name and
docblock to the `revive-declarative-calc-layer` migration and is not
repurposed here).

## Impact

- `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`:
  +1 property, +1 lifecycle key, +1 calculations block (2 calcs), +1
  notification rule, 7 channel-string fixes.
- Tests: `tests/Unit/Service/OverdueVerantwoordingNotificationTest.php`
  (new) — schema-shape assertions (calc ops ⊆ engine vocabulary, prop refs
  resolve, notification rule shape, channel regression lock) + a functional
  mirror-evaluator exercising the exact declared expression trees against
  the retired job's original test matrix.
- No PHP class changes — purely declarative (ADR-031).
