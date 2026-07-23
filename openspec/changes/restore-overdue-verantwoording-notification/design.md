# Design: restore-overdue-verantwoording-notification

## Why option (a), not a calc-engine string-split op

Issue #505 named two options: (a) add `awardDate`, or (b) extend
`CalculationAnnotationValidator`/`CalculationEvaluator` with a
substring/split operator so `isOverdue` could parse the start date out of
`reportingPeriod`'s composite string (`"2024-01-01 to 2024-12-31"`).

(b) is rejected here:
- It's an OpenRegister platform change (a new operator in a shared,
  cross-app engine), not a shillinq-local fix — out of proportion to one
  schema's gap and risks affecting every other schema's calc validation.
- `reportingPeriod` is itself derived ("auto-calculated from grant award
  date to report date", per its own schema description) — parsing a
  derived composite string back apart is more fragile than storing the
  award date directly, and every other date-bearing field on this schema
  (`reportDate`, `submittedDate`, `approvalDate`) is already a first-class
  field, not embedded in a composite string.
- `awardedAmount` already established the exact precedent: a plain
  snapshot field copied from the `Subsidie` grant at verantwoording
  creation time, specifically to avoid a cross-schema join. `awardDate`
  is the same pattern for the same reason.

## Recovering the original rule exactly

`OverdueVerantwoordingJob::isOverdue()` (recovered from git history,
`git show 23d9d014:lib/BackgroundJob/OverdueVerantwoordingJob.php`):

```php
public function isOverdue(array $verantwoording, DateTimeImmutable $now, ?string $awardDate = null): bool
{
    $status = (string) ($verantwoording['status'] ?? '');
    if (in_array($status, self::NON_FINAL_STATES, true) === false) {
        return false;
    }
    $reference = $awardDate;
    if ($reference === null || $reference === '') {
        $period = (string) ($verantwoording['reportingPeriod'] ?? '');
        $reference = trim((string) (explode(' to ', $period)[0] ?? ''));
    }
    if ($reference === '') {
        return false;
    }
    try {
        $awardInstant = new DateTimeImmutable($reference);
    } catch (Throwable) {
        return false;
    }
    $ageDays = (int) $awardInstant->diff($now)->days;
    return $now > $awardInstant && $ageDays > self::OVERDUE_DAYS;
}
```

Two rules survive the port: **non-final status** (`self::NON_FINAL_STATES
= ['draft', 'submitted', 'approved']`) and **>90 elapsed days from a
reference date**. Because this schema's `status` enum is exactly
`[draft, submitted, approved, final]`, "non-final" ⟺ `status != 'final'` —
no `in`/list-membership operator is needed (and none exists in
`VALID_OPS`), a plain `ne` suffices.

The `run()` method's actual call site — `isOverdue(verantwoording: $record,
now: $now)`, **never supplying `$awardDate`** — means in production every
real invocation always took the `reportingPeriod`-split branch; the
explicit-`$awardDate` parameter was dead code path in the shipped job. This
change inverts that: `awardDate` becomes the primary, always-supplied
reference (a real field, not an optional override), and the
`reportingPeriod`-split fallback is dropped entirely (no calc op supports
it) rather than ported as dead code a second time. A null `awardDate` — the
new schema field's default state on any record created before this change,
or if the `Subsidie`-side backfill data is unavailable — resolves
`isOverdue` to `false` (never overdue), which is documented as the
accepted, honest tradeoff rather than a silent behavioural guess.

## Calculation expression trees

```json
"x-openregister-calculations": {
  "daysSinceAward": {
    "expression": { "diffDays": [ { "now": [] }, { "prop": "awardDate" } ] },
    "type": "integer",
    "materialise": true
  },
  "isOverdue": {
    "expression": {
      "and": [
        { "ne": [ { "prop": "status" }, { "lit": "final" } ] },
        { "gt": [ { "prop": "daysSinceAward" }, { "lit": 90 } ] }
      ]
    },
    "type": "boolean",
    "materialise": true
  }
}
```

Every operator (`prop`, `lit`, `now`, `diffDays`, `and`, `ne`, `gt`) is
verified present in `CalculationAnnotationValidator::VALID_OPS` (29-op
vocabulary, openregister
`lib/Service/Calculation/CalculationAnnotationValidator.php:61-94`).
`isOverdue`'s `{"prop": "daysSinceAward"}` is a cross-calc reference
(pointing at a sibling calculation, not a schema property) — the validator
explicitly supports this ("Cross-calculation: Cycle detection across
`{prop:calcA, prop:calcB}` dependency graph") and
`CalculationOnSaveListener::process()` evaluates calcs **in declaration
order**, materialising each into the shared payload before the next runs —
`daysSinceAward` is declared first, so `isOverdue`'s `prop` reference
resolves against its already-computed value, exactly like a regular field.

## Why `scheduled` + `filter`, not `calculatedChange`

OpenRegister's notification dialect offers both a `scheduled` trigger
(periodic scan + filter match) and a `calculatedChange` trigger (fires once
on a calc's value crossing a threshold, driven off the update event a
recompute produces). The retired job's actual behaviour was a **daily
re-check that re-notifies every day a report remains overdue** — no
"notify once on crossing" semantics, no dedupe. `scheduled` +
`intervalSec: 86400` + `filter: {isOverdue: true}` is the literal, faithful
port of that cadence: `ScheduledNotificationJob` (60s TimedJob) fires this
rule once per `intervalSec` and re-dispatches to every currently-matching
object, unconditionally, exactly like the original job's `run()` did. A
`calculatedChange` trigger would fire once on the crossing and then go
silent for a report that stays overdue for months — a *different*,
arguably better, but *not* 1:1-faithful behaviour change this issue did not
ask for.

## Live wiring, end to end

1. Any save (create/update/transition) on a `SubsidieVerantwoording`
   triggers `CalculationOnSaveListener`, which materialises `daysSinceAward`
   then `isOverdue` into the object's stored data.
2. `TemporalCalculationSweepJob` (hourly) re-evaluates the same two calcs
   for every object whose lifecycle state is not in `x-openregister-lifecycle.final`
   (now `["final"]`), even if nobody touches the object — because
   `daysSinceAward`'s expression contains `{"now": []}`, this schema is
   flagged temporal by `hasTemporalCalculation()`. Objects whose recomputed
   `isOverdue`/`daysSinceAward` changed are re-saved through the normal
   `ObjectService::saveObject()` path.
3. `ScheduledNotificationJob` (60s TimedJob) evaluates `onOverdue`'s
   `filter: {isOverdue: true}` against every `SubsidieVerantwoording`'s
   stored data once per `intervalSec` (86400s) and dispatches through
   `AnnotationNotificationDispatcher` to the `approverUserId` field
   recipient — zero new notification machinery, per ADR-031.

## Backfill

Pre-existing records (created before this change) have no `awardDate` and
therefore `isOverdue: null`/absent until the hourly sweep (step 2 above)
first runs post-deploy and materialises both calcs (`isOverdue` resolving
to `false` for a null `awardDate`, as designed). No bespoke repair step is
added — see tasks.md §6.
