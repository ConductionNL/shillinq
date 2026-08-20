# Spec: bookings-notification-triggers (delta)

## ADDED Requirements

### Requirement: A single `BookingNotificationService` implementation SHALL exist per ADR-012 deduplication

The booking-notification orchestration (`evaluateEventTrigger`, `dispatchNotification`, `recordAuditTrail` per REQ-BNT-001/004/005) MUST be implemented by exactly one service class:
`OCA\Shillinq\Service\BookingNotificationService` — the implementation
consumed by `lib/BackgroundJob/BookingReminderJob.php` and
`lib/Listener/BookingEventListener.php`. No parallel implementation of the
same three-method contract MAY exist elsewhere in the codebase, wired or
unwired.

@e2e exclude backend deduplication check: asserted via repo-wide grep, not UI

#### Scenario: No duplicate BookingNotificationService implementation remains

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for classes named `BookingNotificationService` across `lib/`
- **THEN** exactly one such class MUST exist (`OCA\Shillinq\Service\BookingNotificationService`), and no `OCA\Shillinq\Service\Notification\*` namespace MUST exist

#### Scenario: The remaining service stays fully wired

- **GIVEN** `lib/BackgroundJob/BookingReminderJob.php` and `lib/Listener/BookingEventListener.php`
- **WHEN** inspected for their `BookingNotificationService` dependency
- **THEN** both MUST continue to reference `OCA\Shillinq\Service\BookingNotificationService` (unchanged) and the build/test suite MUST pass with the parallel `OCA\Shillinq\Service\Notification\*` namespace removed
