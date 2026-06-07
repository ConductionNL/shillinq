---
sidebar_position: 20
title: Configuring SMS Reminder Channels
description: Operator guide for configuring SMS reminder channels for bookings — provider selection (MessageBird, Twilio), credentials, message templates, send timing, sender ID, testing and troubleshooting.
---

# Configuring SMS Reminder Channels

SMS reminder channels let Shillinq send booking reminders by text message.
Each channel selects an SMS provider, carries a short message template with
booking variables, and schedules a reminder a configurable number of
minutes before the booking starts. Channels are declarative: dispatch and
retries are handled by OpenRegister's notification engine and the
[openconnector](./Integrations) SMS connector — there is no separate SMS
service to operate.

## Outcomes

- One or more SMS reminder channels, each bound to a provider.
- A reminder text that is automatically personalised per booking.
- Reminders that fire a fixed time before each booking.

## Prerequisites

- A configured openconnector SMS connector (MessageBird or Twilio) holding
  the provider API credentials. Credentials live **in the connector**, never
  in Shillinq.
- The `sms-channel-administrator` (or `booking-administrator`) role.

## 1. Open the channel list

In the Shillinq navigation choose **Communication → SMS Reminder Channels**.
The list shows every channel with its provider, send-before time, sender ID
and status (`active`, `inactive`, `archived`). Two example channels ship
with the app: one MessageBird channel (active) and one Twilio channel
(inactive) you can use as a starting point.

## 2. Create a channel

Click **Create** and fill in:

| Field | Notes |
|-------|-------|
| **Name** | Human-readable label, e.g. "SMS Reminders via MessageBird (NL)". |
| **Provider** | `messagebird`, `twilio`, or `custom` (your own connector). |
| **Provider config → connectorId** | The slug of the openconnector connector that holds the API key. The key itself is never shown or stored here. |
| **Message template** | The SMS text, max **160 characters** (one SMS). Use `{{variables}}` (see below). |
| **Send before (minutes)** | Minutes before booking start to send. `1440` = 24h, `120` = 2h. |
| **Fallback phone number** | Used when the booking contact has no number. NL/E.164 format (`+31…` or `06…`). |
| **Sender ID** | Shown to the recipient. Max **11 characters** (MessageBird and Twilio limit). |
| **Retry attempts / interval** | How often and how long apart to retry on a transient failure (defaults 3 / 300s). |

New channels are created `active` and are immediately eligible for dispatch.

### Message variables

Use these placeholders in the template; they are substituted per booking at
send time. Undefined variables render as an empty string.

| Variable | Example |
|----------|---------|
| `{{customerName}}` | Jan van der Berg |
| `{{bookingRef}}` | BK20260521001 |
| `{{bookingDate}}` | 21 mei |
| `{{bookingTime}}` | 14:30 |
| `{{bookingLocation}}` | Kantoor Amsterdam (truncated if longer than 30 chars) |
| `{{organizationName}}` | Example BV (truncated if longer than 20 chars) |
| `{{bookingUrl}}` | https://example.nl/book/ABC |

The detail page shows a **Preview** rendered with sample values and its
**character count** so you can keep the real message within 160 characters.

## 3. Send timing

`sendMinutesBefore` is consumed by OpenRegister's notification engine. When a
booking emits the `booking.reminder-due` event, every **active** channel
renders its template with that booking's variables and dispatches the SMS via
the channel's connector. Dispatch is queued (it does not block the booking
API). On a transient provider error the engine retries per the channel's
retry settings; persistent failures are logged with a masked phone number.

## 4. Opt-out and privacy

Phone numbers are personal data. Every channel ships with **Respect opt-out**
on by default: a recipient who has opted out of SMS reminders (the contact's
`smsOptOut` flag, resolved at dispatch time) is skipped and no message is
sent. Only disable this for a strictly transactional channel where opt-out
does not apply, and document why. Skipped sends are recorded in the
notification history with a PII-free reason (e.g. *recipient opted out of SMS
reminders*); the number itself is never logged in full — logs show a masked
form such as `+31******78`.

The gating, phone-number validation/normalisation (E.164/NL), template
rendering with truncation, and the hand-off to the provider are implemented in
Shillinq's `SmsReminderDispatcher` helper (in `lib/Service/Sms/`), which talks
to an `SmsProviderAdapterInterface`. The wired adapter is the log adapter (no
live gateway in this release); live dispatch is owned by OpenRegister's
notification engine + openconnector. The helper makes the opt-out, validation
and rendering behaviour real and unit-tested without a provider account.

## 5. Test and verify (roadmap)

A "Send Test SMS" action and a "Verify Credentials" check are planned for the
booking-lifecycle release, once a live provider connector is wired in. Until
then, validate a channel by checking the rendered preview and its length on
the detail page, and confirm the connector independently in openconnector.

## 6. Lifecycle

| State | Meaning |
|-------|---------|
| **active** | Reminders are dispatched from this channel. |
| **inactive** | Dispatch paused (e.g. maintenance). Re-activate to resume. |
| **archived** | Retired. Removed from the active list; history kept for audit. Cannot be reactivated — create a new channel instead. |

Editing an active channel applies to **future** sends only. Disabling a
channel stops new sends; previously sent messages are unaffected. Archiving
preserves history.

## SMS dispatch contract

For integrators wiring booking events to channels:

- **Input** — a channel id plus a booking variables map (the keys listed
  under *Message variables*).
- **Output** — a dispatch status: `success`, `pending` (queued/retrying) or
  `failed`.
- **Errors** — invalid/unknown channel, missing required variables, or a
  provider error are logged (phone numbers masked) and surface in the
  notification history; the channel stays active for future sends.

## Provider quirks

- **MessageBird** — alphanumeric sender IDs are capped at 11 characters.
  Delivery reports (DLR) are a roadmap (T3) item.
- **Twilio** — alphanumeric sender IDs are capped at 11 characters; cost
  varies by region. Per-message cost can be logged when `Log SMS cost` is on.
- **Character limits** — keep templates at or under 160 characters. Longer
  messages would split into multiple SMS (extra cost); the editor warns you
  via the preview length.

## Switching providers / migration

To move from one provider to another (e.g. MessageBird → Twilio), point the
channel's `provider` and `providerConfig.connectorId` at the new connector,
or create a new channel and archive the old one. Credentials are re-entered
in the new openconnector connector; nothing sensitive is copied between
channels.

## Troubleshooting

- **Reminders not sent** — confirm the channel is `active` and the connector
  credentials are valid in openconnector.
- **Message looks truncated** — a variable exceeded its limit (location > 30,
  organization > 20 chars) or the template is over 160 characters. Check the
  preview length on the detail page.
- **Wrong recipient / no number** — when a booking has no contact number the
  `fallbackPhoneNumber` is used; if both are missing the send fails and is
  logged.
