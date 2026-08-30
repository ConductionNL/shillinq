# Design — Booking SMS Reminder Channel

## Context

Market intelligence confirms 17/21 competitors offer SMS reminders for
booking confirmations and pre-booking alerts. Dutch SMB booking
operators need configurable SMS reminders without technical
intervention. SMS has higher engagement than email for time-sensitive
reminders (71% open rate vs. 22% email).

Per ADR-031, channel management is declarative (register-based with
lifecycle). Per ADR-022, SMS dispatch consumes OR's notification
engine abstraction. openconnector provides SMS provider abstraction
(MessageBird, Twilio). This change locks those decisions into the spec.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express SMS reminder surface as **declarative metadata** — one SMS
  channel register + lifecycle + provider config + message templating —
  per ADR-031.
- Consume OR's notification engine and openconnector SMS providers —
  per ADR-022. Zero bespoke SMS dispatch service.
- Make the spec a **competent-SMB-operator readable contract** —
  provider selection, phone number management, message customization,
  delivery scheduling — recognisable end-to-end without code access.
- Keep the message template extensible so future booking entities
  (Task, Event, Appointment) can bind custom variables additively.

## Non-Goals

- No SMS designer/WYSIWYG UI — text-only templates; designer roadmap
  for T3.
- No WhatsApp/Telegram channels — SMS only; multi-channel roadmap
  for T3.
- No delivery tracking (DLR status, read receipts) — T3.
- No SMS cost budgeting or spend controls — billing integration
  roadmap for T3.
- No bulk SMS campaigns — reminders only; marketing roadmap for T3.
- No booking integration — this change is channel-only; booking
  lifecycle triggers landing in future change.

## Decisions

### D1 — Single SMS reminder channel register for provider flexibility

One `BookingSmsReminderChannel` register allows operators to manage
SMS provider selection (MessageBird, Twilio, custom), credentials,
phone number formatting, and delivery preferences independently.
Operator can have multiple channels (e.g., fallback provider) or switch
providers without affecting booking logic.

**Alternative considered**: Embed SMS config as fields on Booking
entity. Rejected — separates channel (infrastructure) from booking
data; multiple channels/providers supported; lifecycle independent.

### D2 — Provider configuration via openconnector abstraction

SMS provider selection (MessageBird, Twilio, custom) exposed as
openconnector connector dropdown. Credentials (API key, etc.) stored
per connector; no provider-specific fields in Nextcloud schema.

**Alternative considered**: Hard-coded MessageBird + Twilio adapters
in Nextcloud code. Rejected — violates ADR-022 (notification engine
abstraction); openconnector provides extensibility; credentials
managed centrally.

### D3 — Message template as single text field with character limit

SMS message is a single text field with mustache-style placeholders
(`{{customerName}}`, `{{bookingDate}}`). Template constrained to
≤160 characters (single SMS, no concatenation complexity). Variable
substitution via `x-openregister-calculations`.

**Alternative considered**: Multi-part template (greeting, body,
closing). Rejected — SMS 160-character limit makes segmentation
artificial; single message simpler; long messages concat via provider
logic (handled by openconnector, not Nextcloud).

### D4 — Phone number stored per channel, validated per locale

Each channel declares default/fallback phone number; booking contact
number provided at send time. Phone numbers validated against E.164
standard + NL-specific formatting (starts with +31 or 06).

**Alternative considered**: Phone number on Booking entity only.
Rejected — channel manages fallback number; booking provides primary;
validation per provider/locale.

### D5 — Delivery scheduling via fixed send time before booking

Channel declares `sendMinutesBefore` (e.g., 1440 min = 24h before
booking). OR's notification engine checks schedule; sends at
configured time (with ±15 min tolerance window to avoid race
conditions).

**Alternative considered**: Multiple reminder waves (24h, 2h, 1h before).
Rejected — single cadence covers 80% of use cases; multiple waves
added in future T3 capability if demand emerges.

### D6 — Channel lifecycle is active/inactive/archived

Channels move through three states. Active = SMS enabled and will
dispatch. Inactive = SMS disabled (e.g., paused for maintenance).
Archived = previously active, no longer used (kept for audit/history).

**Alternative considered**: Complex workflow (draft → scheduled →
active → inactive → archived). Rejected — Dutch SMB workflows simpler;
channel activation is binary toggle; archival for history.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Channel lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on SMS channel register (active → inactive → archived) |
| SMS dispatch | OR notification engine (ADR-022) | Channels dispatch via OR's notification abstraction; no bespoke service |
| Provider abstraction | openconnector SMS connectors | Provider selection via openconnector; credentials managed centrally |
| Message rendering | OR `x-openregister-calculations` (ADR-031) | Variable substitution is a calculation field; no PHP template service |
| Provider config | New fields per register | Channel-level provider config (API key, phone, formatting rules); extensible |
| Variable bindings | Declarative map | Customer, booking ref, date, time, location, URL; extensible for future types |

**Net new code in implementation cycle**: 1 schema declaration + 1
lifecycle block + 1 calculation field + 1 manifest entry pair. No PHP
service classes (per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Channel lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Message rendering | Declarative (`x-openregister-calculations`) | Pure data → string transformation |
| SMS dispatch | Consumed from OR notification-engine | ADR-022 |
| Provider config | Declarative fields + openconnector | Provider abstraction; credentials managed centrally |
| Phone number validation | Calculated field | E.164 + locale validation as calculation |

No service class authored in this envelope.

## Seed Data

#### BookingSmsReminderChannel (example 1: MessageBird)

```json
{
  "id": "sms-messagebird-nl",
  "name": "SMS Reminders via MessageBird (NL)",
  "status": "active",
  "provider": "messagebird",
  "sendMinutesBefore": 1440,
  "messageTemplate": "Hallo {{customerName}}, herinnering: uw boeking op {{bookingDate}} om {{bookingTime}}. Ref: {{bookingRef}}",
  "fallbackPhoneNumber": "+31123456789",
  "senderId": "Bookings",
  "retryCount": 3,
  "retryIntervalSeconds": 300
}
```

#### BookingSmsReminderChannel (example 2: Twilio)

```json
{
  "id": "sms-twilio-nl",
  "name": "SMS Reminders via Twilio (NL)",
  "status": "inactive",
  "provider": "twilio",
  "sendMinutesBefore": 120,
  "messageTemplate": "Boeking {{bookingRef}} over 2 uur om {{bookingTime}} in {{bookingLocation}}. Meer info: {{bookingUrl}}",
  "fallbackPhoneNumber": "+31987654321",
  "senderId": "BookingApp",
  "retryCount": 2,
  "retryIntervalSeconds": 600
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Invalid phone numbers cause SMS delivery failure | Phone number validation per E.164 + NL format; fallback to channel default; log failures for operator review; future DLR tracking (T3) exposes delivery status |
| Message length exceeds 160 chars, triggering multi-part SMS costs | Template constrained to ≤160 chars validation; long variables (e.g., location) truncated with ellipsis; testing with real-world variable values |
| SMS provider unavailable (API down) causes reminders to miss | OR notification engine retry logic + exponential backoff; operator notified of dispatch failures; future fallback channel support (T3) |
| SMS cost unpredictable without provider cost data | SMS sends logged with provider + cost (if available from openconnector); future billing/cost control integration (T3) |
| Operator confusion on active vs. inactive channels | Help text + admin UI show channel status prominently; "Test Send" feature for validation |
| Provider credentials exposed in logs or backups | Credentials stored per openconnector connector (centralized, not in Nextcloud schema); rotation policy TBD during implementation |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/booking_register.json` is created with the SMS
   channel schema (additive).
2. `src/manifest.json` is patched with 1 SMS channel admin navigation
   entry (additive).
3. Seed data (1-2 example channels per language/provider) seeded into
   OR on app installation (operator configures credentials post-install).

Down-direction: channels are non-destructive — reverting removes the
manifest entry and lifecycle bindings; sent messages remain but channel
becomes unreferenced.

## Open Questions

1. **Provider selection UI** — dropdown with pre-configured MessageBird
   + Twilio connectors or bring-your-own openconnector connector —
   resolved during implementing cycle.
2. **Phone number source priority** — operator-supplied fallback,
   booking contact number, or booking request — resolved during
   implementing cycle's booking integration design.
3. **Retry strategy** — exponential backoff, fixed interval, or
   provider-specific queue logic — resolved during discovery against
   openconnector SMS connector capabilities.
4. **SMS cost per provider** — MessageBird €0.04/SMS, Twilio €0.0075/SMS
   — cost allocation and billing integration (T3) — resolved during
   implementing cycle.
5. **Delivery time window** — send immediately or constrain to business
   hours (8am–6pm NL time) — resolved during operator feedback phase.
