# Spec: bookkeeping-subsidie-verantwoording (delta)

## ADDED Requirements

### Requirement: SubsidieVerantwoording SHALL declare a real awardDate field and a declarative isOverdue calculation

`SubsidieVerantwoording` MUST declare a nullable `awardDate` (`string`,
`format: date`) property — a grant-award-date snapshot copied from the
`Subsidie` grant, following the same pattern as `awardedAmount`. The schema
MUST declare `x-openregister-calculations.daysSinceAward` (materialised,
integer, `{"diffDays": [{"now": []}, {"prop": "awardDate"}]}`) and
`x-openregister-calculations.isOverdue` (materialised, boolean,
`{"and": [{"ne": [status, "final"]}, {"gt": [daysSinceAward, 90]}]}`),
restoring the 90-day accountability deadline rule (REQ-SUBV-010) the
retired `OverdueVerantwoordingJob::isOverdue()` implemented imperatively.
Every operator used MUST be present in
`OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator::VALID_OPS`.
A null or unparseable `awardDate` MUST resolve `isOverdue` to `false`
(never overdue) — no reportingPeriod-string-split fallback is implemented.
`x-openregister-lifecycle.final` MUST declare `["final"]` so
`TemporalCalculationSweepJob`'s hourly re-evaluation (required to keep the
`now`-dependent `isOverdue` live without an object write) excludes
already-finalised records.

@e2e exclude declarative schema + calc config: JSON register fragment, asserted via unit test (schema-shape assertions + a functional mirror-evaluator against the retired job's original test matrix), not UI

#### Scenario: A non-final report more than 90 days past award is flagged overdue

- **GIVEN** a `SubsidieVerantwoording` object with `status: draft` (or `submitted`/`approved`) and `awardDate` more than 90 days before the evaluation instant
- **WHEN** `x-openregister-calculations.daysSinceAward` and `.isOverdue` are evaluated (at save time by `CalculationOnSaveListener`, or by `TemporalCalculationSweepJob`'s hourly sweep for an untouched object)
- **THEN** `isOverdue` MUST materialise to `true`

#### Scenario: A final report is never overdue regardless of age

- **GIVEN** a `SubsidieVerantwoording` object with `status: final` and any `awardDate`, however old
- **WHEN** `isOverdue` is evaluated
- **THEN** `isOverdue` MUST materialise to `false`

#### Scenario: A record with no awardDate is never overdue

- **GIVEN** a `SubsidieVerantwoording` object with `awardDate` null or absent (e.g. a pre-existing record created before this field existed)
- **WHEN** `isOverdue` is evaluated
- **THEN** `daysSinceAward` MUST resolve to `null` and `isOverdue` MUST materialise to `false`, with no attempt to derive a reference date from `reportingPeriod`

### Requirement: SubsidieVerantwoording SHALL fire a daily overdue reminder to the assigned approver

The schema MUST declare `x-openregister-notifications.onOverdue` with
`trigger: {type: scheduled, intervalSec: 86400, filter: {isOverdue: true}}`
and `recipients: [{kind: field, field: approverUserId}]`, restoring the
retired `OverdueVerantwoordingJob`'s daily (24h) re-check cadence and
recipient resolution declaratively (ADR-031). A record with no assigned
`approverUserId` MUST resolve to zero recipients (matching the job's
original fail-closed behaviour), not an error.

@e2e exclude declarative notification config: JSON register fragment, asserted via unit test (schema-shape assertions against NotificationAnnotationValidator's documented shape rules), not UI

#### Scenario: The assigned approver is reminded daily while a report remains overdue

- **GIVEN** a `SubsidieVerantwoording` object with `isOverdue: true` and a non-empty `approverUserId`
- **WHEN** `ScheduledNotificationJob` evaluates the `onOverdue` rule's `filter` against the object's stored data
- **THEN** `AnnotationNotificationDispatcher` MUST dispatch the notification to the `approverUserId` user, re-firing again after each subsequent `intervalSec` while the object still matches the filter

#### Scenario: An overdue record with no assigned approver receives no notification

- **GIVEN** a `SubsidieVerantwoording` object with `isOverdue: true` and an empty/absent `approverUserId`
- **WHEN** `ScheduledNotificationJob` evaluates the `onOverdue` rule
- **THEN** zero recipients are resolved and no notification is dispatched for that object

### Requirement: SubsidieVerantwoording and AuditorStatement notification rules SHALL declare only validator-recognised channels

Every `x-openregister-notifications` rule on both schemas in this fragment MUST declare `channels` using
only values in `NotificationAnnotationValidator::VALID_CHANNELS` (`nc-notification, email,
activity, webhook, talk, web-push`). The literal `"in-app"` MUST NOT
appear as a channel value anywhere in this fragment — it is not recognised
by `NotificationAnnotationValidator` or `AnnotationNotificationDispatcher`,
so a rule declaring it never renders an in-app Nextcloud notification.

@e2e exclude declarative notification config: JSON register fragment, asserted via unit test (regression lock grepping the raw fragment + validating every declared channel), not UI

#### Scenario: No notification rule in this fragment uses the invalid "in-app" channel string

- **GIVEN** every `x-openregister-notifications` rule declared on `SubsidieVerantwoording` and `AuditorStatement` in `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`
- **WHEN** each rule's `channels` array is checked against `VALID_CHANNELS`
- **THEN** every declared channel MUST be one of `nc-notification`, `email`, `activity`, `webhook`, `talk`, `web-push`, and none MUST be the string `"in-app"`
