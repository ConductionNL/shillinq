# Change: migrate-legacy-notification-dialect

> **Scope (narrowed on archive, 2026-07-22):** this change covers the
> **bare-`lifecycle.*`-string dialect bug** on the register fragments consumed
> by OpenRegister's `AnnotationNotificationDispatcher` — ~38 of the 48 rules,
> across the 14 files listed `[x]` in tasks.md, are migrated to the canonical
> dialect and dispatch again. The remaining ~10 rules (5 files) are marked
> **OUT OF SCOPE** in tasks.md and deferred to a follow-up change: they use
> shapes that are *not* the lifecycle-string bug — the custom `booking.*`
> event dialect (`@self`-driven meta-triggers, `selectBy`/`scheduleOffsetHours`,
> outside OR's `VALID_TRIGGERS`), `CcmFinding` rules consumed by shillinq's own
> `CcmRuleEngine` (not OR's dispatcher), freeform `condition` expression
> strings, and the `SubsidieVerantwoording.isOverdue` calc which needs either a
> new `awardDate` schema field or a string-split calc operator that
> OpenRegister does not have. Converting those safely requires OR-engine or
> schema design decisions, not a mechanical migration — see the per-rule
> rationale in tasks.md and the follow-up items.

## Why

19 register fragments under `lib/Settings/register.d/*.json` declare
`x-openregister-notifications` rules using the **OBSOLETE legacy dialect**
(ADR-031 "The `x-openregister-notifications` dialect (canonical)"): a bare
string `"trigger": "lifecycle.submit"` instead of `{"type": "transition",
"action": "submit"}`, and/or a singular `"recipient": {"resolver": ...,
"fallback": ...}` object instead of a `"recipients": [...]` array. 48 rules
are affected across 19 files (worst offender:
`lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`, 7 rules
on `SubsidieVerantwoording` + `AuditorStatement`).

This is not a style nit — **these rules are non-functional against the live
engine**:

- `OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher::dispatchWithSchema()`
  reads `$spec['trigger'] ?? []` and passes it into
  `matches(array $triggerSpec, ...)` (openregister
  `lib/Service/Notification/AnnotationNotificationDispatcher.php:226-227,1167`).
  Under `declare(strict_types=1)` a bare trigger **string** cannot satisfy an
  `array $triggerSpec` parameter.
- The same dispatcher resolves recipients via
  `$this->resolveRecipients(recipientsSpec: ($spec['recipients'] ?? []), ...)`
  (line 285-286) — the **plural** key. A rule that only declares singular
  `recipient` yields `$spec['recipients'] === null ?? []` → an empty
  recipients array → `count($recipients) === 0` short-circuits the whole
  rule (line 304) with **zero notifications sent, silently, forever**.
- `OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator`
  requires `$spec['recipients']` to be a non-empty array of `{kind: ...}`
  objects (`notification-no-recipients` / `notification-recipient-malformed`
  / `notification-bad-recipient-kind`,
  `lib/Service/Notification/NotificationAnnotationValidator.php:511-587`) —
  none of the 19 legacy fragments would pass this validator today.
- The `resolver` values used (`finance-officer`, `subsidie-coordinator`,
  `administration-treasurer`, etc.) have **no PHP implementation anywhere in
  shillinq** (`grep -rl "finance-officer\|subsidie-coordinator" lib
  --include=*.php` → zero hits) — even a canonical
  `{"kind":"expression","resolver": "..."}` rewrite would still need a real
  resolver class to exist.

Concretely: `SubsidieVerantwoording`'s `onSubmitted` / `onApproved` /
`onFinal` rules (REQ-SUBV-003) and `AuditorStatement`'s 4 rules never fire —
the finance officer and grant owner are never notified through the
declarative path. The only working notification for this schema today is
the imperative `lib/BackgroundJob/OverdueVerantwoordingJob.php`
(`dispatchOverdueNotification()`, line 185-216) which hand-rolls
`INotificationManager::createNotification()->notify()` and is paired with a
bespoke `lib/Notification/Notifier.php` (`implements INotifier`) — exactly
the pattern ADR-031 says to avoid ("Do NOT hand-roll a `NotificationService`
/ `IManager::notify()` dispatcher in a leaf app for object-event
notifications — declare the rule"). `lib/AppInfo/Application.php:497`
registers this bespoke `Notifier`.

The fleet mechanical gate that is supposed to catch this
(`hydra-gate-notification-dialect`, backed by
`hydra/scripts/lib/check_notification_dialect.py`) only scans
`lib/Settings/*register*.json` (`run-hydra-gates.sh:958`,
`find lib/Settings -maxdepth 1 ...`) — it never descends into
`lib/Settings/register.d/`, which is where every one of these 19 fragments
actually lives. That is a fleet-tooling gap (reported separately as a
cross-cutting candidate, not fixed here — this change only touches
shillinq's own files).

## What Changes

- Add `lib/Notification/RoleFallbackResolver.php`, an
  `x-openregister-notifications` `recipients[].kind=expression` resolver
  that maps the fleet's existing `resolver` role names
  (`finance-officer`, `subsidie-coordinator`, `administration-treasurer`,
  and the other role names used across the 19 affected files) to Nextcloud
  group members, falling back to the role's configured fallback group when
  the primary is empty — preserving the legacy `resolver` + `fallback`
  *intent* under the canonical `recipients[].kind=expression` shape.
- Migrate all 48 legacy-dialect rules across the 19 listed
  `lib/Settings/register.d/*.json` fragments to the canonical dialect:
  `"trigger": "lifecycle.<action>"` → `{"type": "transition", "action":
  "<action>"}` (or the matching non-lifecycle trigger type where the string
  encodes something else — see tasks.md per-file notes); `"recipient": {...}`
  → `"recipients": [{"kind": "expression", "resolver":
  "OCA\\Shillinq\\Notification\\RoleFallbackResolver::<role>"}]`.
- `SubsidieVerantwoording`: add `x-openregister-calculations.isOverdue`
  (pure function of `status` + `reportingPeriod`/award date + 90-day
  horizon, ports `OverdueVerantwoordingJob::isOverdue()`) and a new
  `onOverdue` declarative rule (`trigger.type: scheduled`, `intervalSec:
  86400`, `filter: {isOverdue: true}`), recipient
  `{"kind":"field","field":"approverUserId"}`.
- Delete `lib/BackgroundJob/OverdueVerantwoordingJob.php` and
  `lib/Notification/Notifier.php` (and their unit tests) — both superseded
  by the declarative rule + OpenRegister's own `AnnotationNotifier`. Remove
  the `registerNotifierService(Notifier::class)` call and `use` statement
  in `lib/AppInfo/Application.php:39,497`.
- **BREAKING**: `subsidie_verantwoording_overdue` in-app notifications
  change their NC notification `subject`/route shape (now emitted by OR's
  `AnnotationNotifier`, not shillinq's bespoke `Notifier`); any client code
  matching on the old subject string must be updated (none found in `src/`
  at time of writing — grep-verify in tasks.md).

## Out of Scope

- Any register fragment NOT in the 19-file list above (i.e. fragments that
  already use `trigger.type` + `recipients[]` correctly are untouched).
- Fixing `hydra-gate-notification-dialect`'s `-maxdepth 1` scan gap —
  that's a hydra-repo change, reported as a cross-cutting candidate.
- Renaming/restructuring lifecycle transitions themselves — only the
  notification rule shape changes.

## Impact

- Affected specs: `bookkeeping-subsidie-verantwoording` (notification
  requirement modified + added).
- Affected code: 19 files under `lib/Settings/register.d/`,
  `lib/Notification/RoleFallbackResolver.php` (new),
  `lib/BackgroundJob/OverdueVerantwoordingJob.php` (deleted),
  `lib/Notification/Notifier.php` (deleted), `lib/AppInfo/Application.php`,
  `tests/Unit/BackgroundJob/OverdueVerantwoordingJobTest.php` (deleted, if
  present), `tests/Unit/Notification/NotifierTest.php` (deleted, if
  present).
