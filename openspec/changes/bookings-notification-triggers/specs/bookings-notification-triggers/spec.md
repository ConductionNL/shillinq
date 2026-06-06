# Spec: bookings-notification-triggers

**Status:** proposed
**Scope:** bookings (Nextcloud Bookings app)
**Tier:** T2 (customer-facing feature)
**Depends on:** none

## ADDED Requirements

### Requirement: REQ-BNT-001 — Four trigger types SHALL be supported for booking lifecycle events

The system SHALL support four trigger types for booking lifecycle events:
`booking.created`, `booking.changed`, `booking.cancelled`, and `booking.reminder`.

Booking notifications are triggered by four events:
- `booking.created` — when a new booking is made
- `booking.changed` — when a booking is modified (time, organizer, details)
- `booking.cancelled` — when a booking is cancelled by customer or organizer
- `booking.reminder` — time-based reminder fires before event (24h, 1h, 15m before)

Each trigger type MUST carry an event payload containing:
- `bookingId` — UUID of the booking
- `organizer` — full name of the booking organizer
- `guestName` — full name of the guest/customer
- `startTime` — ISO-8601 datetime of the appointment
- `duration` — duration in minutes
- `location` — location or description (may be null)
- `status` — booking status (confirmed, cancelled, pending)
- `price` — booking value in EUR (may be 0.00)
- For `booking.changed`: `previousValues` object with fields that changed
- For `booking.reminder`: `hoursUntilEvent` — hours remaining until appointment

#### Scenario: booking.created event fires on new booking

- **GIVEN** a new booking is made in the Bookings app
- **WHEN** the booking is saved and the status transitions to `confirmed`
- **THEN** the `booking.created` event MUST be emitted with all required payload fields

#### Scenario: booking.changed event includes before/after values

- **GIVEN** an organizer reschedules a booking from 2026-06-01 10:00 to 2026-06-01 14:00
- **WHEN** the booking is saved
- **THEN** the `booking.changed` event MUST include `previousValues: { startTime: "2026-06-01T10:00:00Z" }`

#### Scenario: booking.cancelled event fires on cancellation

- **GIVEN** a booking with status `confirmed`
- **WHEN** the booking is cancelled
- **THEN** the `booking.cancelled` event MUST be emitted with status `cancelled`

#### Scenario: booking.reminder event fires on schedule

- **GIVEN** a booking scheduled for 2026-06-01 10:00
- **WHEN** the background job runs at 2026-05-31 09:00 (24 hours before)
- **THEN** the `booking.reminder` event MUST be emitted with `hoursUntilEvent: 24`

### Requirement: REQ-BNT-002 — Notification templates SHALL support variable substitution for booking context

The system SHALL render notification subject and body templates with Twig-style
`{{ variable }}` substitution and SHALL render undefined variables as empty
strings rather than errors.

Notification templates are stored as `BookingNotificationTemplate` register
entities with fields:
- `name` — template identifier (e.g., "Booking Confirmation")
- `trigger` — which event fires this template (`created`, `changed`, `cancelled`, `reminder`)
- `subject` — email subject line with variables
- `body` — email/SMS body text with variables
- `language` — language code (`nl_NL`, `en_US`)
- `active` — whether template is enabled

Variables are substituted using Twig syntax: `{{ variable }}`. Allowed variables:
- `{{ booking.organizer }}` — organizer full name
- `{{ booking.guestName }}` — guest/customer full name
- `{{ booking.startTime }}` — ISO datetime
- `{{ booking.startTime | date('d-m-Y H:i') }}` — formatted date/time
- `{{ booking.duration }}` — duration in minutes
- `{{ booking.location }}` — location string (may be empty)
- `{{ booking.price }}` — price in EUR
- `{{ booking.status }}` — booking status
- `{{ recipient.email }}` — email of notification recipient
- `{{ recipient.name }}` — name of recipient (from contact or preference)
- `{{ system.appName }}` — "Bookings" (for branding)

Variables not present in the booking MUST be rendered as empty string, not error.

#### Scenario: Template renders booking variables correctly

- **GIVEN** a template with subject "Boeking {{booking.organizer}} - {{booking.startTime | date('d M Y')}}"
- **WHEN** the template is rendered for a booking (organizer: "Tandarts Jansen", startTime: "2026-06-15T10:30:00Z")
- **THEN** the subject MUST render as "Boeking Tandarts Jansen - 15 Jun 2026"

#### Scenario: Missing variables render as empty, not error

- **GIVEN** a template with subject "Locatie: {{booking.location}}"
- **WHEN** the template is rendered for a booking where location is null
- **THEN** the subject MUST render as "Locatie: " (empty, not error)

### Requirement: REQ-BNT-003 — Notifications SHALL target recipients based on configurable rules

The system SHALL resolve recipients by evaluating each trigger's ordered rule list
against the booking payload at dispatch time, skipping any rule whose `condition`
expression is false.

Each trigger defines a `recipients` rule list, evaluated in order:
```yaml
recipients:
  - role: customer
    channels: [email, sms]
    condition: "booking.status == 'confirmed'"
  - role: organizer
    channels: [email]
    condition: "true"
  - role: admin_group
    channels: [email]
    condition: "booking.price > 100"
```

Supported roles:
- `customer` — the guest/customer who made the booking (uses booking.guestEmail)
- `organizer` — the organizer/staff member (uses booking.organizerEmail)
- `admin_group` — members of the "admin" group in Nextcloud
- `role:NAME` — users with a specific Nextcloud role (future extension)

Each rule specifies preferred channels in priority order: `[email, sms, chat]`.

Conditions are simple expressions: `booking.price > 100`, `booking.status == 'confirmed'`,
evaluated against the booking object. If condition is false, the rule is skipped.

#### Scenario: Recipient rule evaluated on event fire

- **GIVEN** a `booking.created` trigger with rule (role: customer, channels: [email, sms])
- **WHEN** a booking is created with status `confirmed` and guestEmail `alice@example.com`
- **THEN** a notification MUST be sent to alice@example.com

#### Scenario: Conditional recipient rule skipped if condition false

- **GIVEN** a trigger with rule (role: admin_group, condition: "booking.price > 100")
- **WHEN** a booking with price €50 is created
- **THEN** the admin_group rule MUST be skipped (condition false)

### Requirement: REQ-BNT-004 — Notifications SHALL be routed through openconnector channel adapters

The system SHALL dispatch every notification through openconnector channel
adapters and SHALL fall back to the next configured channel on adapter failure.

When a notification is sent, the dispatcher:
1. Selects the recipient address (email, phone, or chat ID)
2. Iterates through the preferred channels in priority order
3. Calls openconnector's adapter API for each channel
4. Records success/failure and delivery status in audit trail
5. Returns on first success, or logs final failure if all channels fail

Fallback logic: if email adapter is unavailable, try SMS; if SMS unavailable,
try chat. If all fail, log error with reason and alert admin.

openconnector adapter invocation signature (HTTP POST):
```
POST /openconnector/api/notifications/send
{
  "channelAdapter": "email" | "sms" | "chat",
  "recipient": "alice@example.com",
  "subject": "Boeking bevestigd",
  "body": "...",
  "templateId": "uuid-of-template"
}
```

#### Scenario: Email sent via openconnector adapter

- **GIVEN** a notification for recipient alice@example.com, channels [email, sms]
- **WHEN** openconnector's email adapter is available
- **THEN** the notification MUST be sent via email (not fallback to SMS)

#### Scenario: Fallback to SMS if email adapter fails

- **GIVEN** a notification for alice@example.com, channels [email, sms]
- **WHEN** the email adapter returns error (e.g., SMTP unavailable)
- **THEN** the system MUST automatically retry via SMS adapter

### Requirement: REQ-BNT-005 — Every notification dispatch SHALL be recorded in audit trail

The system SHALL record every notification dispatch attempt — sent, failed,
skipped, or queued — as an immutable `NotificationDelivery` audit event.

Per ADR-022, every notification send is recorded as a named audit event in
OpenRegister with fields:
- `objectType` — "NotificationDelivery"
- `triggerName` — name of the trigger (e.g., "Booking Confirmation")
- `triggerType` — trigger event type (created/changed/cancelled/reminder)
- `bookingId` — UUID of the booking
- `recipient` — email or phone (PII, sensitive)
- `channel` — channel used (email, sms, chat)
- `templateName` — template used
- `status` — "sent", "pending", "failed"
- `failureReason` — error message if status == "failed"
- `retryCount` — number of retry attempts
- `sentAt` — ISO-8601 timestamp

The audit record MUST be tamper-evident (hash-chained per OR standard).

#### Scenario: Audit record created on send

- **GIVEN** a notification is sent to alice@example.com via email
- **WHEN** the send completes successfully
- **THEN** an audit event MUST be recorded with status "sent", channel "email", sentAt timestamp

#### Scenario: Audit record logs failure reason

- **GIVEN** a notification send fails (e.g., SMTP error)
- **WHEN** all retry attempts are exhausted
- **THEN** the audit event MUST record status "failed", failureReason: "SMTP connection timeout", retryCount: 3

### Requirement: REQ-BNT-006 — Rate-limiting SHALL prevent notification storms

The system SHALL enforce per-booking and per-organizer rate-limits and SHALL
deduplicate repeated dispatches of the same trigger to the same recipient.

Notifications are rate-limited to prevent bulk sends on misconfiguration:
- Maximum 10 notifications per booking per hour (calendar hour, UTC)
- Maximum 100 notifications per organizer per day
- Deduplication: if the same recipient receives the same trigger type twice
  in 5 minutes, the second is skipped

Rate-limit violations are logged with reason and the excess notification is
queued for manual review.

#### Scenario: Rate-limit enforced per booking

- **GIVEN** 10 notifications have been sent for booking X in the current hour
- **WHEN** a new trigger fires for booking X
- **THEN** the notification MUST be queued (not sent immediately) and flagged for admin review

#### Scenario: Deduplication prevents double-sends

- **GIVEN** a `booking.created` trigger sends email to alice@example.com at 10:00
- **WHEN** the same trigger fires again at 10:02 (duplicate event)
- **THEN** the second notification MUST be skipped (deduplicated within 5 min window)

### Requirement: REQ-BNT-007 — Notification configuration SHALL be accessible via modals on booking detail pages

The booking detail page (type: detail) MUST include a modal launcher for
trigger configuration:
- A "Notifications" button or link that opens a modal
- The modal displays active triggers for this booking
- Organizers can enable/disable triggers and customize recipient rules
- Organizers can select notification channels (email, sms, chat)
- Organizers can override templates per booking (or use global defaults)
- Cancel/Save buttons; Save calls the backend API to persist configuration

The configuration UI modal MUST be implemented per ADR-004:
- Located in `src/modals/BookingNotificationConfigModal.vue` (or similar)
- Imported and launched from the detail page component
- Isolated from the parent component lifecycle

Global notification settings (default templates, rate-limits) are available in
the app settings modal, accessible by admins only.

#### Scenario: Organizer opens notification config modal

- **GIVEN** the booking detail page is displayed
- **WHEN** the organizer clicks the "Notifications" button
- **THEN** the notification config modal MUST open, showing active triggers and options to enable/disable

#### Scenario: Organizer customizes trigger channels

- **GIVEN** the notification config modal is open
- **WHEN** the organizer selects SMS for the `booking.created` trigger
- **WHEN** the organizer clicks Save
- **THEN** the trigger configuration MUST be persisted and the modal MUST close

### Requirement: REQ-BNT-008 — Admin dashboard SHALL monitor trigger activity and allow disable/reset

The system SHALL expose an admin monitor surface that displays delivery counts,
failure alerts, and a global disable-all toggle, and SHALL load within two
seconds.

An admin dashboard surface (Settings > Bookings > Notification Monitor)
displays:
- Total notifications sent today/week/month
- Per-trigger send counts and failure rates
- Alerts on rate-limit violations or repeated failures
- Ability to disable all triggers globally (emergency off-switch)
- Ability to reset rate-limit counters per booking/organizer
- Link to audit trail for full send history

The dashboard MUST load in <2s and refresh data every 5 minutes.

#### Scenario: Admin views trigger dashboard

- **GIVEN** the admin opens Settings > Bookings > Notification Monitor
- **WHEN** the page loads
- **THEN** the dashboard MUST display send counts, failure alerts, and enable the disable-all toggle

### Requirement: REQ-BNT-009 — Recipient preferences and opt-out MUST be checked before send

The system MUST check recipient opt-out preferences before every dispatch and
MUST skip the send (recording it as `skipped (opt-out)` in the audit trail) when
the recipient has opted out of the trigger type or channel.

Before sending a notification:
1. Check if recipient has opted out of notifications globally or by trigger type
2. Check if recipient has opted out of the channel (e.g., SMS-only, no email)
3. If opted out, skip send and log in audit trail as "skipped (opt-out)"

Initial release MUST support admin-level opt-out (admins can disable
notifications for specific recipients). Customer self-service opt-out portal
is reserved for tier-3.

#### Scenario: Notification skipped if recipient opted out

- **GIVEN** alice@example.com has opted out of booking reminders
- **WHEN** a `booking.reminder` trigger fires
- **THEN** the notification MUST NOT be sent and audit trail MUST log "skipped (opt-out)"

## CHANGED Requirements

(None in initial release.)

## Integration Points

- **openconnector** — channel adapter API for email/SMS/chat delivery
- **OpenRegister** — event hooks for booking lifecycle, audit trail recording
- **Nextcloud Background Jobs** — cron scheduling for reminder triggers
- **Nextcloud user/group API** — recipient lookup for admin_group role
