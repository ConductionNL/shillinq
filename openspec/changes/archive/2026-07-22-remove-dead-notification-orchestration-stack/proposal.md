# Change: remove-dead-notification-orchestration-stack

## Why

shillinq has **two independent implementations** of the booking-notification
orchestration spec'd by `openspec/changes/archive/2026-06-14-bookings-notification-triggers/tasks.md#task-2`
("Create `BookingNotificationService.php` service class with methods:
`evaluateEventTrigger(event, booking)`, `dispatchNotification(trigger,
recipient, template, booking)`, `recordAuditTrail(notification, status,
reason)`"):

1. `lib/Service/BookingNotificationService.php` (namespace
   `OCA\Shillinq\Service`, 869 lines) — **wired**: used by
   `lib/BackgroundJob/BookingReminderJob.php:27` and
   `lib/Listener/BookingEventListener.php:26`.
2. `lib/Service/Notification/BookingNotificationService.php` (namespace
   `OCA\Shillinq\Service\Notification`, 429 lines) plus 12 collaborator
   classes in the same directory (`NotificationOptOutPolicy`,
   `NotificationRateLimiter`, `NotificationDeduplicator`,
   `RecipientResolver`, `RecipientConditionEvaluator`,
   `NotificationTemplateRenderer`, `NotificationAuditWriter`,
   `OpenconnectorAdapterInterface`, `LogOpenconnectorAdapter`,
   `NotificationCounterStoreInterface`, `InMemoryNotificationCounterStore`,
   `NotificationSendResult`) — **1,954 lines total, fully implemented,
   never wired**. `grep -rln "Shillinq\\\\Service\\\\Notification"
   --include=*.php .` (repo root) returns hits only inside
   `lib/Service/Notification/` itself and 7 unit test files under
   `tests/Unit/Service/Notification/` (1,714 more lines). No controller,
   listener, background job, or `lib/AppInfo/Application.php` DI
   registration ever instantiates or references this namespace.

Both classes carry the identical `@spec
openspec/changes/bookings-notification-triggers/tasks.md#task-2` tag and
both implement `evaluateEventTrigger`/`dispatchNotification`/
`recordAuditTrail` — this is a genuine duplicate implementation of the same
requirement (ADR-012 deduplication), not two different features that
happen to share a name. The unwired copy is **dead code**: 1,954 lines of
production PHP plus 1,714 lines of tests (3,668 lines total) that compile,
pass their own isolated unit tests, and do nothing at runtime.
`hydra-gate-stub-scan` does not catch this class of dead code because
every method is fully implemented (not a stub) — it is simply never
called from outside its own package.

## What Changes

- Delete the entire `lib/Service/Notification/` directory (13 files,
  1,954 lines) and its corresponding `tests/Unit/Service/Notification/`
  directory (8 files, 1,714 lines).
- No behavioural change: nothing outside these two directories references
  `OCA\Shillinq\Service\Notification\*`, so no controller, listener, job,
  route, or DI registration is touched.
- **BREAKING**: none — the removed code has zero external callers, verified
  by full-repo grep.

## Out of Scope

- The wired `lib/Service/BookingNotificationService.php` (`OCA\Shillinq\Service`
  namespace) is untouched — it is the actual implementation and stays as-is.
- No attempt is made to salvage individual collaborator classes (e.g.
  `RecipientConditionEvaluator`) into the wired service; if a future change
  wants that logic, it should be proposed and justified separately against
  the wired service's actual gaps, not assumed from the dead copy.

## Impact

- Affected specs: none (no requirement references the dead namespace by
  name; the archived `bookings-notification-triggers` spec's task-2 is
  already satisfied by the wired `OCA\Shillinq\Service\BookingNotificationService`).
- Affected code: `lib/Service/Notification/*.php` (13 files, deleted),
  `tests/Unit/Service/Notification/*.php` (8 files, deleted).
