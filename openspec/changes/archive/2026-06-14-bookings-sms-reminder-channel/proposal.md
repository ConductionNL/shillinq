# Proposal: bookings-sms-reminder-channel

`kind: config` — declarative SMS reminder channel with configurable
provider (MessageBird, Twilio) via openconnector. SMS channel entity
(`BookingSmsReminderChannel`) + `x-openregister-lifecycle` for
channel management + manifest entries for channel administration.
No dedicated SMS service layer.

## Summary

Introduce SMS reminder capability for bookings with pluggable provider
support (MessageBird, Twilio). This change declares one SMS reminder
channel register; channel lifecycle with versioning and active/inactive
states; provider configuration (API credentials, phone number format);
message templates (customer name, booking date/time, booking ref);
delivery scheduling and retry logic. Channels integrate with OR's
notification engine for dispatch.

The capability materialises a booking-aware SMS notification system
conforming to ADR-031 (declarative template registry) and ADR-022
(notification-engine abstraction).

## Motivation

Market intelligence research (2026-05-20) confirms 17/21 analysed
competitors offer SMS reminders for booking confirmations and
pre-booking reminders. Dutch SMB booking operators need configurable
SMS reminders without building custom integrations. SMS delivery has
higher engagement than email for time-sensitive booking confirmations.

This is a standalone capability that extends the notification surface
beyond email, enabling multi-channel booking reminders (email via
bookings-email-templates, SMS via this change).

## Affected Projects

- [x] Project: nextcloud-booking — adds 1 capability spec
  (`notification-booking-sms-reminders`); declares 1 new register
  (`BookingSmsReminderChannel`) with lifecycle and provider config;
  adds 1 manifest navigation entry (SMS Reminder Channels).
- [ ] Project: openregister — no source changes; consumes existing
  notification engine (ADR-022), `x-openregister-lifecycle`,
  `openconnector` SMS provider abstraction.
- [ ] Project: nextcloud-booking-integration — future change;
  activates SMS channels on booking state transitions.

## Scope

### In Scope

- One new capability spec (`notification-booking-sms-reminders`)
  — see the `specs/` folder.
- The `BookingSmsReminderChannel` register with provider selection
  (MessageBird, Twilio, custom via openconnector), API credentials
  storage, phone number formatting rules, sender ID/name.
- SMS message template with customer name, booking date/time, booking
  reference, location, and booking URL variables.
- Channel lifecycle (`active → inactive → archived`) enabling version
  management and provider switching.
- Delivery scheduling (send time, retry count, retry interval) for
  reminder timing before booking start.
- Message length validation (SMS character limits, concatenation for
  long messages).
- Provider-agnostic configuration via openconnector abstraction.

### Out of Scope

- **Booking integration** — this change declares channels only; booking
  lifecycle integration (triggering SMS dispatch) lives in future T2
  booking-lifecycle change.
- **WhatsApp/Telegram channels** — SMS only; multi-channel roadmap
  item for T3.
- **Delivery tracking** — SMS read status, delivery confirmation (DLR)
  — T3 feature.
- **Bulk SMS campaigns** — reminder-only; marketing SMS roadmap item
  for T3.
- **International number validation** — NL focus; international roadmap
  item for T4.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`notification-booking-sms-reminders`** — declares the SMS reminder
channel register, lifecycle (active → inactive → archived), provider
configuration, message templating, delivery scheduling, and integration
with OR's notification engine.

Each requirement is prefixed `REQ-SMS-*` for traceability.

## New Dependencies

- Consumes OpenRegister abstractions: `x-openregister-lifecycle`,
  `x-openregister-calculations`, notification engine (ADR-022),
  openconnector SMS providers (MessageBird, Twilio).
- Requires @conduction/nextcloud-vue@^1.0.0-beta.35 (existing
  bumped version).

## Impact

- `lib/Settings/booking_register.json` — adds 1 new schema
  (`BookingSmsReminderChannel`); declares lifecycle, provider config,
  manifest entry.
- `src/manifest.json` — adds 1 navigation entry (SMS Reminder Channels
  admin page).
- No dedicated PHP SMS service class (per ADR-031, SMS dispatching
  is declarative via OR's notification engine + openconnector).
- No bespoke Vue components (uses standard OR SMS channel registry UI).

## Cross-Project Dependencies

- **OpenRegister** — depends on notification engine (ADR-022),
  `x-openregister-lifecycle` (ADR-031), openconnector SMS provider
  adapters.
- **openconnector** — consumes SMS provider abstractions (MessageBird,
  Twilio connectors).
- **Future booking-lifecycle** — depends on this change to declare SMS
  channels; booking state-change actions trigger SMS dispatch.

## Risks

### Risk 1: SMS delivery failure due to invalid phone numbers

**Severity**: Medium
**Mitigation**: Phone number validation per locale (NL format, E.164
standard). Fallback to operator-configured default phone on validation
failure. Delivery failure logged for operator review. Future DLR
tracking (T3) will expose delivery status.

### Risk 2: SMS character limits and concatenation complexity

**Severity**: Medium
**Mitigation**: Message template constrained to ≤160 characters (single
SMS). Validation prevents template exceeding limit. Long variable
values (e.g., location name) truncated with ellipsis. Testing with
real-world variable values.

### Risk 3: Provider lock-in with API credentials

**Severity**: Low
**Mitigation**: openconnector abstraction isolates provider-specific
logic. Switching providers requires re-entering credentials (manual
migration). Provider-agnostic API surface.

### Risk 4: SMS cost tracking for billing/cost control

**Severity**: Low
**Mitigation**: SMS send is logged with provider and cost (if available
via openconnector). Billing feature (T3) will integrate with spend
tracking.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
SMS channels are non-destructive — sent messages remain but channels
become unreferenced.

## Open Questions

1. **Provider selection UI** — dropdown with pre-configured connectors
   vs. bring-your-own openconnector — resolved during implementing
   cycle.
2. **Phone number storage** — operator-supplied default, read from
   booking contact, or booking request — resolved during
   implementing cycle.
3. **Retry strategy** — exponential backoff, fixed interval, or
   provider-specific — resolved during discovery against
   openconnector provider capabilities.
4. **SMS cost allocation** — per channel, per send, or aggregated for
   billing — resolved during implementing cycle's cost tracking
   design.
