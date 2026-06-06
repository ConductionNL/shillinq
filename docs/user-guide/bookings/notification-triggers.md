---
title: Notification triggers
sidebar_position: 4
---

# Notification triggers

Notification triggers automate the messages that go out when a booking
lifecycle event happens. Without triggers, you would have to email or
text customers by hand on every confirmation, change, cancellation and
reminder. With triggers, the Bookings app does it for you — and records
every send in an immutable audit trail so you can prove what was sent,
to whom, when, and through which channel.

This guide walks through the four trigger types, how to configure them
per booking, how to read the admin monitor and how to troubleshoot the
common cases where a notification didn't go out.

## What is a notification trigger?

A **trigger** is a rule that tells the notification engine:

- **When** to fire (`booking.created`, `booking.changed`,
  `booking.cancelled`, `booking.reminder`).
- **Who** to notify (customer, organizer, admin group — optionally
  filtered by a condition like `booking.price > 100`).
- **Through which channels** to send it (email first, SMS as fallback,
  chat / Teams / Slack also supported).
- **At what limits** (no more than 10 per booking per hour, 100 per
  organizer per day, with a 5-minute deduplication window).

A trigger is **enabled** by default. The admin can pause it (status
`disabled`) or retire it (status `archived`).

## The four trigger types

| Trigger | Fires when | Default content |
|---------|------------|-----------------|
| `booking.created` | A booking transitions from pending to confirmed | Confirmation email |
| `booking.changed` | A non-status field changes (start time, organizer, attendee, location) | Change notice email |
| `booking.cancelled` | A booking transitions to cancelled | Cancellation email |
| `booking.reminder` | A configurable number of hours before `bookingStart` | Reminder email + SMS |

`booking.reminder` triggers carry a `Hours before start` setting; the
engine schedules dispatch at `bookingStart − hours` (with a ±15-minute
tolerance window — missed slots, e.g. after a restart, dispatch
immediately).

## Configuring triggers per booking

Every booking detail page shows a **Notifications** button. Click it
to open the configuration modal:

- The modal lists every trigger that applies to this booking — the
  global triggers plus any overrides scoped to this single booking.
- Toggle the checkbox next to the trigger name to enable or disable it
  for this booking only.
- Pick the channels (Email, SMS, Chat, Teams, Slack) — the engine
  tries them in order: if Email fails, it falls back to SMS, etc.
- Click **Save** to persist; the modal closes and the audit trail
  records the change.

> The modal lives in its own `.vue` file under `src/modals/` and is
> isolated from the parent component per ADR-004. If you build a
> custom view, import `BookingNotificationConfigModal` directly — do
> not inline the modal markup into the parent.

## Global trigger administration

For org-wide changes, go to **Communication → Notification Triggers**
in the navigation. You see a table of every trigger with:

- Name
- Event type
- Status (enabled / disabled / archived)
- Channel count
- Recipient-rule count
- Rate-limit summary (e.g. `10/booking/hour · 100/organizer/day · dedupe 5 min`)
- Last sent timestamp

Open any trigger to edit its recipient rules, channels, rate-limit and
deduplication window. The detail page exposes the lifecycle actions
(disable, enable, archive) on the right.

## The notification monitor

Go to **Communication → Notification Monitor** for the live audit
trail. The page polls every 5 minutes and shows:

- Per-status counters (sent / failed / queued / skipped)
- A detail table of every recent NotificationDelivery record
- Drill-down to one delivery for the dispatch group, retry count, and
  failure reason
- Emergency actions:
  - **Disable all triggers** — toggles every enabled trigger to
    disabled (REQ-BNT-008 off-switch).
  - **Reset rate-limit counters** — clears the per-booking-hour and
    per-organizer-day buckets after the cause of a runaway loop has
    been resolved.

Recipients are **masked** in the list view (`a***@example.com`,
`+31***5678`) — auditors with the full-read permission see the raw
value.

## Template variables

Notification templates live in **Communication → Confirmation /
Reminder / Cancellation Templates** (from `bookings-email-templates`).
The notification triggers reuse those templates — no duplicate
template store. Variables you can use anywhere a template renders:

| Variable | Value |
|----------|-------|
| `{{ booking.organizer }}` | Resource staff full name |
| `{{ booking.guestName }}` / `{{ booking.customerName }}` | Customer name |
| `{{ booking.startTime }}` | ISO datetime |
| `{{ booking.startTime \| date('d-m-Y H:i') }}` | Formatted date/time |
| `{{ booking.duration }}` | Minutes |
| `{{ booking.location }}` | Free text (may be empty) |
| `{{ booking.price }}` | Amount in EUR |
| `{{ booking.status }}` | Booking status |
| `{{ recipient.email }}` | Recipient email |
| `{{ recipient.name }}` | Recipient display name |
| `{{ system.appName }}` | "Bookings" branding |

Variables that are not present on the booking render as the empty
string — never as an error — so a missing field never blocks delivery.

## Recipient targeting

Each trigger carries an **ordered recipient rule list**. The engine
walks the list, evaluates each rule's optional condition and emits one
notification per surviving rule. Example:

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

- `customer` — the booking attendee, resolved against `booking.customerEmail`.
- `organizer` — the resource staff member, resolved against `booking.organizerEmail`.
- `admin_group` — fans out to every member of the Nextcloud `admin`
  group at runtime.

Supported condition operators: `==`, `!=`, `>`, `<`, `>=`, `<=`
against dotted paths (`booking.price`, `booking.status`, `booking.duration`)
and bare literals (numbers, single-quoted strings, `true` / `false`).
Malformed conditions fail closed — the rule is skipped (audited as
`condition-false`).

## Rate-limit, dedupe, opt-out

Three independent gates prevent notification storms (REQ-BNT-006/009):

| Gate | Default | Outcome on hit |
|------|---------|----------------|
| Per-booking-hour rate-limit | 10 | `status=queued`, `skipReason=rate-limit-booking` |
| Per-organizer-day rate-limit | 100 | `status=queued`, `skipReason=rate-limit-organizer` |
| Recipient + triggerType + bookingId dedupe | 5 min | `status=skipped`, `skipReason=deduplicated` |
| Recipient opt-out (per trigger type or `all`) | respect = on | `status=skipped`, `skipReason=opt-out` |
| Per-channel opt-out (e.g. SMS only) | respect = on | `status=skipped`, advances to next channel |

Queued records show up in the admin monitor; they are not dispatched
until an admin resets the counter or the calendar window rolls over.

## Troubleshooting

> **Why didn't I get a notification?**
>
> 1. Go to the **Notification Monitor** and search for the booking ID.
> 2. Each NotificationDelivery row shows the dispatch outcome:
>    - `status=skipped` + `skipReason=opt-out` — the recipient opted
>      out. Check their preferences (or override `respectOptOut` on
>      the trigger if it's a strictly transactional channel).
>    - `status=skipped` + `skipReason=deduplicated` — the same
>      notification was already sent in the last 5 minutes.
>    - `status=queued` + `skipReason=rate-limit-*` — the cap was hit.
>      Wait for the window to roll over or reset the counter.
>    - `status=failed` + `skipReason=adapter-unavailable` — the
>      openconnector adapter for the channel is down. The engine
>      falls back to the next channel automatically; check the
>      `dispatchGroupId` for the matching fallback record.
>    - `status=failed` + `skipReason=template-render-error` —
>      `failureReason` carries the renderer error. Fix the template
>      and try again.
> 3. If nothing matches, check the trigger status (enabled?), the
>    recipient rule conditions, and the booking payload (does
>    `booking.customerEmail` actually have a value?).

> **The engine is flooding everybody — how do I stop it?**
>
> Click **Disable all triggers** in the Notification Monitor.
> Every enabled trigger flips to disabled in one transaction. Then
> fix the underlying config (e.g. a runaway recipient rule) and
> selectively re-enable.

> **A customer says they receive duplicates.**
>
> The dedupe window is 5 minutes by default. If lifecycle events
> are firing in bursts (e.g. a buggy integration), the second dispatch
> within the window is automatically skipped. If you see real
> duplicates more than 5 minutes apart, increase
> `deduplicationWindowMinutes` on the trigger.

## Related ADRs

- **ADR-022** — notification engine and audit-trail-immutable contract.
- **ADR-031** — schema-declarative business logic.
- **ADR-004** — modal isolation (the per-booking configuration UI).
- **ADR-037** — modular register fragments (the trigger config never
  edits the monolithic register file).
