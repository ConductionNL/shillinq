---
sidebar_position: 22
title: Booking Email Templates — API Contract
description: Developer-facing API contract for booking email template dispatch — inputs, outputs, error handling, integration with OR's notification engine.
---

# Booking Email Templates — API Contract

This page documents the declarative API contract for dispatching booking
emails through OpenRegister's notification engine using the
`BookingConfirmationTemplate`, `BookingReminderTemplate`, and
`BookingCancellationTemplate` schemas.

There is no PHP service class — rendering, dispatch, retry, and observability
are consumed from OR per ADR-022. The contract below is what the booking
system (or any caller) needs to know.

## Dispatch trigger

The booking system emits one of three events. The notification engine
listens for them, selects the active template, renders it, and dispatches.

| Event | Schema consulted | Lifecycle filter |
|---|---|---|
| `booking.confirmed` | `BookingConfirmationTemplate` | `status: published` |
| `booking.reminder-due` | `BookingReminderTemplate` | `status: published` |
| `booking.cancelled` | `BookingCancellationTemplate` | `status: published` |

Multiple `BookingReminderTemplate` records may be published at the same time
(for example `hoursBeforeBooking: 24` and `hoursBeforeBooking: 2`). The
engine schedules one dispatch per published reminder per booking.

## Inputs (variable map)

Every event carries a variable map. The engine substitutes `{{variable}}`
placeholders in the template's `subjectLine`, `htmlBody`, and
`plainTextBody` with these values.

### Standard variables (all events)

| Variable | Type | Example |
|---|---|---|
| `customerName` | string | `"Femke Jansen"` |
| `bookingRef` | string | `"BK20260521042"` |
| `bookingDate` | date (locale-formatted string) | `"22 mei 2026"` |
| `bookingTime` | time (locale-formatted string) | `"10:00"` |
| `bookingLocation` | string | `"Kantoor Amsterdam, Kamer 3"` |
| `organizationName` | string | `"Example BV"` |

### Reminder-only

| Variable | Type | Example |
|---|---|---|
| `hoursUntilBooking` | integer | `24` |

### Cancellation-only (when `cancellationReasonRequired: true`)

| Variable | Type | Example |
|---|---|---|
| `cancellationReason` | string | `"Customer requested cancellation"` |

## Output (rendered email)

The engine produces a normalised payload and hands it to the configured
email provider:

```json
{
  "from": {
    "name": "<senderName | global default>",
    "address": "<senderAddress | global default>"
  },
  "to": {
    "address": "<recipient.email>"
  },
  "subject": "<rendered subjectLine>",
  "htmlBody": "<rendered htmlBody>",
  "plainTextBody": "<rendered plainTextBody>"
}
```

Both the HTML and plain-text bodies are sent in every message as a
multipart/alternative MIME envelope. Email clients without HTML rendering
display the plain-text body per REQ-BET-006.

## Locale selection (NFR-BET-005)

The engine picks one template per (event, recipient) pair using:

1. `appliesWhen: status in ['published']` — filter to published templates.
2. `selectBy.matchLocale: recipient.languagePreference` — pick the
   template whose `locale` matches the customer's language preference.
3. Fall back to `nl` if no match is found.

If multiple templates share the same locale and event (operator misuse),
the engine picks the most-recently activated one and logs a warning.

## Variable substitution rules (T14)

- Placeholder syntax is `{{variableName}}` — case sensitive, no spaces.
- Undefined variables render as empty string — never `undefined` or the
  literal `{{variableName}}`.
- Values are HTML-escaped when substituted into the HTML body (prevents
  injection per T25).
- Date/time values are formatted by the engine using the recipient locale
  before substitution.

## Error handling (T21)

The contract distinguishes three failure modes:

| Failure | Behaviour |
|---|---|
| Template not found (no `published` template matches the event and locale) | Engine falls back to a generic plain-text email (`organizationName` + `bookingRef`) and logs `template-not-found` for operator review. |
| Render failure (placeholder syntax error, calculation throw) | Engine falls back to dispatching only the plain-text body of the matched template (see `fallback.mode: "plain-text"` on the schema's `x-openregister-notifications`) and logs `render-failure`. |
| Dispatch failure (transient provider error) | Engine retries per OR's notification-engine retry policy. Permanent failures log `dispatch-failure` with the provider response. |

Operators see all three failure modes in the OR notification log.

## Validation (declarative, T11–T13)

Templates are validated by the schema's `x-openregister-calculations`:

| Calculation field | Rule | Reference |
|---|---|---|
| `subjectLineLength` | ≤ 78 after sample substitution | NFR-BET-002 / T12 |
| `totalBodySizeBytes` | `strlen(htmlBody) + strlen(plainTextBody) ≤ 102 400` | NFR-BET-003 / T13 |
| `htmlBodySafe` | HTML body contains only whitelisted elements; no inline `<style>`, `<script>`, `<link>`, or external CSS | NFR-BET-001 / T11 |

A template with `htmlBodySafe: false` or `subjectLineLength > 78` should
not be published — the admin UI surfaces the warning on the detail view.

## Permissions

See the [operator guide §10](./booking-email-templates#10-permissions) for
the permission slugs and role mapping.

## See also

- [Operator guide: Customizing Booking Email Templates](./booking-email-templates)
- [SMS Reminder Channels](./sms-reminder-channels)
- Specs: `openspec/changes/bookings-email-templates/specs/notification-booking-email-templates/spec.md`
