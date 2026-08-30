# Spec: bookkeeping-subsidie-verantwoording (delta)

## ADDED Requirements

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

<!--
  OUT OF SCOPE (deferred to follow-up): the declarative `isOverdue` calculated
  field + `onOverdue` scheduled rule are NOT part of this narrowed change —
  they need either a new `awardDate` schema field or a string-split calc
  operator OpenRegister does not have (see tasks 3.1/3.2). ⚠️ FOLLOW-UP GAP:
  the bespoke `OverdueVerantwoordingJob` was already deleted on development, so
  overdue-report notification currently has NO implementation (neither the job
  nor the declarative replacement). This must be restored by the follow-up
  change.
-->

