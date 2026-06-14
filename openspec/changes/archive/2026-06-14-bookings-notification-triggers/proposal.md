# Proposal: bookings-notification-triggers

`kind: feature` — notification trigger system for booking lifecycle events,
integrated with openconnector channel adapters. Enables automated notifications
on created, modified, cancelled, and reminder events via email, SMS, and chat.

## Summary

Introduce **booking notification triggers** as a declarative notification system
for the Nextcloud Bookings app. This change enables automated notifications when
bookings are created, modified, cancelled, or when time-based reminders fire.
Notifications route through openconnector channel adapters (email, SMS, chat,
Teams, Slack) based on configurable trigger rules and recipient preferences.

The implementation provides:
- Trigger definitions for four booking lifecycle events (created/changed/cancelled/reminder)
- Template system for notification content with booking context variables
- Recipient configuration (customer, organizer, administrator)
- Channel routing via openconnector with fallback handling
- Audit trail of sent notifications per ADR-022

This change conforms to the shared ADR-004 modal isolation pattern for the
notification configuration UI and integrates with OpenRegister's event system
per ADR-031 (schema-declarative business logic).

**Depends on:** none. Notification triggers build on core Bookings functionality
and are compatible with existing Nextcloud installations.

## Motivation

All 21 competitors in the market intelligence report offer notification
capabilities for booking lifecycle events. Customers expect automatic
notifications when:
- A booking is made (confirmation)
- A booking is modified (change alert)
- A booking is cancelled (cancellation notice)
- A reminder fires before the appointment (pre-event reminder)

Without this feature, end-users must manually notify customers, leading to
missed reminders, poor customer experience, and support burden. This is a
P0-must capability for market competitiveness.

The openconnector integration enables users to route notifications through
their preferred channels (email is standard; SMS, chat, Teams, Slack for
premium workflows) without building channel-specific integrations in Bookings.

## Affected Projects

- [x] Project: bookings — declares trigger events, notification templates,
  recipient preferences, and channel routing via openconnector.
- [x] Project: openconnector — consumes notification events and routes to
  registered channel adapters (email/SMS/chat/Teams/Slack/etc.).

## Scope

### In Scope

- Four trigger types: `booking.created`, `booking.changed`, `booking.cancelled`,
  `booking.reminder` (time-based, 24h/1h/15m before event).
- Template system for notification content (subject, body) with booking context
  variables (organizer, guest, start time, duration, location, etc.).
- Recipient targeting: customer, organizer, admin groups.
- Channel selection and fallback (prefer email → SMS → chat if email unavailable).
- Audit trail of sent notifications (actor, timestamp, delivery status, channel).
- Notification configuration UI (modals per ADR-004) for admins and organizers.
- Webhook integration for openconnector channel delivery callback.

### Out of Scope

- Email/SMS/chat platform accounts — assumed to be provisioned by the user
  in openconnector.
- Push notifications to mobile apps — out of scope (future tier-2 capability).
- Notification history UI for customers — initial release for admin/organizer
  configuration only.
- Unsubscribe / preference management portal — reserved for future tier-3.
- Template builder (drag-drop designer) — templates are configured via JSON/YAML.

## Approach

One spec with ADDED/CHANGED Requirements:

**`bookings-notification-triggers`** — declares:
- REQ-BNT-001: Four trigger types with event payloads
- REQ-BNT-002: Template system with variable substitution
- REQ-BNT-003: Recipient targeting rules
- REQ-BNT-004: Channel routing and fallback
- REQ-BNT-005: Audit trail recording per ADR-022
- REQ-BNT-006: Configuration UI modal pattern per ADR-004
- REQ-BNT-007: Webhook callback for delivery status

## New Dependencies

- `openconnector` channel adapter library (already available in Nextcloud)
- `@conduction/nextcloud-vue@^1.0.0-beta.35` (for modal components per ADR-004)

## Impact

- New `BookingNotificationTrigger` entity in register (OpenRegister).
- New `BookingNotificationTemplate` entity in register (OpenRegister).
- New `NotificationDelivery` audit record per trigger fire (OpenRegister).
- `src/manifest.json` — adds notification configuration entries to the booking
  detail page (per ADR-004 modal isolation).
- Webhook endpoint for openconnector delivery status callbacks.
- Service to evaluate trigger conditions and dispatch notifications (per ADR-031).

## Cross-Project Dependencies

- **openconnector** — stable channel adapter API; used for email/SMS/chat routing.
- **OpenRegister** — audit-trail-immutable for delivery recording; event hooks
  for booking lifecycle transitions.

## Risks

### Risk 1: Notification storm (runaway triggers)

**Severity**: High
**Mitigation**: REQ-BNT-008 mandates rate-limiting (max 10 notifications per
booking per hour); deduplication by trigger type + recipient to prevent
duplicate sends; admin dashboard to monitor trigger activity and disable
runaway rules.

### Risk 2: Broken templates cause delivery failures

**Severity**: Medium
**Mitigation**: Template validation at save time (all variables present, syntax
correct); fallback to default template on variable substitution error;
notification delivery status logged with error details for debugging.

### Risk 3: Customer opt-out not respected

**Severity**: High
**Mitigation**: REQ-BNT-009 requires recipient preference checking before send;
unsubscribe link in every notification (future tier-3 for portal); admin
override available with audit trail.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder.
After implementation lands: disable triggers via admin UI, notification records
remain in OR for audit purposes.

## Open Questions

1. **Default templates (Dutch vs. English)** — resolved during design review
   with `/test-persona-janwillem` (SMB owner perspective).
2. **Rate-limiting threshold** — 10/hour or 5/hour? Resolved in implementation
   with support feedback.
3. **Reminder trigger granularity** — 24h/1h/15m or allow custom intervals?
   Resolved in tier-2 enhancement based on adoption metrics.
