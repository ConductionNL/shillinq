# Spec: bookkeeping-subsidie-verantwoording (delta)

## MODIFIED Requirements

### Requirement: SubsidieVerantwoording notification rules SHALL use the canonical `x-openregister-notifications` dialect

The `SubsidieVerantwoording` and `AuditorStatement` schemas' notification rules MUST declare `trigger` as a structured object
(`{"type": "transition", "action": "<name>"}`, matching
`OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher`'s
`matches(array $triggerSpec, ...)` contract) — NOT a bare lifecycle string
(`"lifecycle.submit"`). Every rule MUST declare `recipients` as a non-empty
array of `{kind: ...}` objects — NOT a singular `recipient` object. A rule
resolving recipients through a role name (`finance-officer`,
`subsidie-coordinator`, `administration-treasurer`) MUST use
`{"kind": "expression", "resolver": "OCA\\Shillinq\\Notification\\RoleFallbackResolver::<role>"}`,
where `RoleFallbackResolver` implements the original resolver-then-fallback
NC-group semantics in PHP.

@e2e exclude declarative notification config: JSON register fragment + PHP resolver class, asserted via unit test + JSON-schema validation, not UI

#### Scenario: SubsidieVerantwoording notification rules pass OpenRegister's annotation validator

- **GIVEN** the `SubsidieVerantwoording` and `AuditorStatement` schemas in `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`
- **WHEN** `OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator` validates every `x-openregister-notifications` rule on both schemas
- **THEN** validation MUST pass with zero `notification-no-recipients` / `notification-recipient-malformed` / `notification-bad-recipient-kind` errors

#### Scenario: Finance officer is notified when a report is submitted

- **GIVEN** a `SubsidieVerantwoording` object in state `draft`
- **WHEN** an operator transitions it to `submitted` (the `submit` lifecycle action)
- **THEN** `AnnotationNotificationDispatcher` MUST resolve at least one recipient via `RoleFallbackResolver::finance-officer` (falling back to the configured fallback NC group when the primary group has zero members) and dispatch the declared notification — replacing the current silent no-op caused by the missing `recipients` key

## ADDED Requirements

### Requirement: Overdue accountability reports SHALL be flagged via a declarative calculated field and notified via a scheduled rule

`SubsidieVerantwoording` MUST declare `x-openregister-calculations.isOverdue`
as a pure function of `status` (non-final: `draft`/`submitted`/`approved`)
and the elapsed days since the award date (explicit award date, or the
`reportingPeriod` start when absent) exceeding 90 days — porting the exact
rule from the (to-be-deleted) `OverdueVerantwoordingJob::isOverdue()`. The
schema MUST also declare an `onOverdue` notification rule with
`trigger: {type: "scheduled", intervalSec: 86400, filter: {isOverdue:
true}}` and `recipients: [{"kind": "field", "field": "approverUserId"}]`.
The bespoke `lib/BackgroundJob/OverdueVerantwoordingJob.php` and
`lib/Notification/Notifier.php` (a hand-rolled `INotifier` registered via
`registerNotifierService()`) MUST be removed — OpenRegister's own
`AnnotationNotifier` renders the declarative rule instead.

#### Scenario: An overdue report notifies its approver on the daily schedule

- **GIVEN** a `SubsidieVerantwoording` object in state `submitted` whose award date is more than 90 days in the past and whose `approverUserId` field holds a valid Nextcloud uid
- **WHEN** the scheduled notification engine evaluates the `onOverdue` rule on its 24-hour interval
- **THEN** the calculated `isOverdue` field MUST evaluate `true`, the rule's `filter` MUST match, and the engine MUST notify the user identified by `approverUserId` — with no dedicated `OverdueVerantwoordingJob` TimedJob class present in the codebase

#### Scenario: A report with no assigned approver notifies nobody

- **GIVEN** an overdue `SubsidieVerantwoording` object whose `approverUserId` field is empty
- **WHEN** the scheduled `onOverdue` rule evaluates
- **THEN** the `field` recipient kind MUST resolve to zero recipients and no notification MUST be dispatched — preserving the current job's "no assigned approver" skip behaviour
