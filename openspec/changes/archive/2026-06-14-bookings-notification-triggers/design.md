# Design — Booking Notification Triggers

<!-- status: pr-created -->

## Context

The Bookings app manages appointment scheduling but lacks automated customer
notification capabilities. Users currently send notifications manually via
email or text, creating operational burden and missed reminders. The market
shows 21/21 competitors offer notification triggers; customer demand is P0.

Nextcloud's openconnector provides a stable multi-channel adapter system
(email, SMS, Teams, Slack, Discord, etc.) requiring only event producers
to emit notifications. The gap is bridging the Bookings lifecycle events
(created/changed/cancelled/reminder) to openconnector's notification API.

This change closes that gap with a declarative notification trigger system:
- Event triggers fire on booking state changes
- Templates render notification content with booking context
- Recipient rules target customer, organizer, admin
- Channel routing delegates to openconnector
- Audit trail records every send (per ADR-022)

## Goals

- **Automate booking notifications** across all lifecycle events.
- **Multi-channel delivery** without building channel-specific code in Bookings.
- **Configurable by organizers** — enable/disable triggers, customize templates,
  select recipients (customer, organizer, admin).
- **Observable delivery** — audit trail of every notification sent, delivery
  status, and failure reasons.
- **Prevent runaway notifications** — rate-limit, deduplicate, fallback gracefully.
- **Declarative configuration** — triggers and templates stored as OpenRegister
  schema entities, no PHP service classes for trigger logic.

## Non-Goals

- Push notifications to mobile apps (tier-2 future capability).
- Customer preference portal (unsubscribe, frequency management — tier-3).
- Drag-drop template builder UI (tier-2; JSON/YAML config in tier-1).
- SMS cost management / billing integration (delegated to channel provider).
- Custom webhook templates for third-party integrations (future tier-3).

## Decisions

### D1 — Trigger events are declarative, evaluated by a service listener

Booking lifecycle transitions (`created`, `changed`, `cancelled`) are emitted
by OpenRegister's event system. A service listener subscribes to these events,
evaluates active triggers, and dispatches notifications.

Reminder triggers (time-based: 24h/1h/15m before event) are scheduled via
Nextcloud's Background Job system (cron or async queue) and evaluated hourly.

**Rationale**: Separates trigger condition logic (service) from event emission
(OpenRegister). Follows ADR-031 (schema-declarative) and ADR-030 (journeydoc
audit pattern).

**Alternative considered**: Trigger logic in database procedures. Rejected —
Nextcloud lacks stored procedure support; PHP service is standard.

### D2 — Templates use Twig-style variable substitution, not hardcoded strings

Notification content (subject, body) are stored as templates with variables:
`{{booking.organizer}}`, `{{booking.guestName}}`, `{{booking.startTime}}`,
`{{booking.duration}}`, `{{booking.location}}`, etc.

At send time, a Twig renderer substitutes variables from the booking object.

**Rationale**: Separates content (data) from presentation (template), enabling
admins to customize messaging without code changes. Twig is standard in Nextcloud.

**Alternative considered**: Hardcoded message templates. Rejected — reduces
customization; every new field requires a code deployment.

### D3 — Recipient targeting uses a rule list, not a checkbox matrix

Triggers define recipient rules as an ordered list:
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

**Rationale**: Flexible rule engine supports complex logic (e.g., "notify admin
only if booking value > €100"). Scales to future recipient types (group,
team, custom webhook). Expressed in YAML so organizers can configure without UI.

**Alternative considered**: UI-driven checkbox matrix (customer: □ email ☑ SMS).
Rejected — inflexible; doesn't support conditional targeting or new recipient types.

### D4 — Channel routing is openconnector-first, with explicit fallback

A trigger specifies preferred channels in priority order: `[email, sms, chat]`.
The dispatcher attempts each in order; if email fails, tries SMS; if SMS fails,
tries chat. Logs the success/failure and reason per ADR-022 audit.

**Rationale**: Guarantees notification delivery via best-available channel;
doesn't require admin to pre-provision a backup channel. Integrates seamlessly
with openconnector's adapter pattern.

**Alternative considered**: Store a single channel per recipient. Rejected —
reduces reliability; doesn't adapt to changing channel availability.

### D5 — Audit trail records every trigger evaluation + dispatch

Per ADR-022, every notification is recorded in OpenRegister's audit trail:
- Trigger ID, trigger name, event type
- Recipient (role, address, channel)
- Template used
- Rendered content (subject/body for debugging)
- Send result (success, failure reason, retry count)
- Timestamp

**Rationale**: Accountability for regulatory compliance (AVG/GDPR —
recipients have right to know when contact was made). Debugging aid for support.

### D6 — Configuration UI uses modals per ADR-004

Trigger and template configuration surfaces in:
- Booking detail page: modal to edit this booking's trigger overrides
- App settings: global modal to manage default templates and rate-limits

No new top-level nav entry; triggers are scoped to booking context. Each modal
is its own `.vue` file in `src/modals/` or `src/dialogs/`.

**Rationale**: Follows ADR-004 modal isolation pattern. Reduces visual clutter
on detail pages. Scoping configuration to booking context (vs. global) is
organizer-friendly.

## Reuse Analysis

| Need | What exists | Strategy |
|---|---|---|
| Event emission | OpenRegister lifecycle hooks | Subscribe to booking `created`, `changed`, `cancelled` events |
| Multi-channel delivery | openconnector adapter system | Emit notifications via openconnector's event API; openconnector routes to email/SMS/chat/Teams/Slack |
| Template rendering | Twig engine (standard Nextcloud) | Use Twig in-app for variable substitution |
| Time-based triggers | Nextcloud Background Jobs (cron/queue) | Schedule reminder jobs at booking create time; cron evaluates hourly |
| Audit recording | OpenRegister audit-trail-immutable | Each notification dispatch recorded as a named event per ADR-022 |
| UI components | Nextcloud vue / conduction/nextcloud-vue | Modal components for trigger/template config (ADR-004) |
| Rate-limiting | Middleware pattern (standard Laravel) | Artisan command or DB-backed rate-limiter per recipient+trigger |

**Net new code**: One service class (`BookingNotificationService`) to manage
trigger evaluation and dispatch. One modal component pair for trigger config.
No parallel notification tables — all state stored in OpenRegister.

## Seed Data

Three default notification templates (created, changed, cancelled):

### Template 1: booking.created
```json
{
  "name": "New Booking Confirmation",
  "trigger": "created",
  "subject": "Bevestiging boeking {{booking.organizer}} - {{booking.startTime | date('d M Y')}}",
  "body": "Hallo {{booking.guestName}},\n\nUw boeking is bevestigd.\n\n...",
  "language": "nl_NL"
}
```

### Template 2: booking.changed
```json
{
  "name": "Booking Rescheduled",
  "trigger": "changed",
  "subject": "Wijziging boeking — {{booking.startTime | date('d M Y')}}",
  "body": "Hallo {{booking.guestName}},\n\nUw boeking is gewijzigd.\n\n...",
  "language": "nl_NL"
}
```

### Template 3: booking.cancelled
```json
{
  "name": "Booking Cancelled",
  "trigger": "cancelled",
  "subject": "Annulering bevestigd",
  "body": "Hallo {{booking.guestName}},\n\nUw boeking is geannuleerd.\n\n...",
  "language": "nl_NL"
}
```

## Risks / Trade-offs

| Risk | Severity | Mitigation |
|---|---|---|
| Notification storm (rate-limit breach) | High | REQ-BNT-008: rate-limit 10/booking/hour; admin dashboard |
| Broken templates render garbage | Medium | Validate templates at save; fallback to default on error |
| Customer opt-out ignored | High | REQ-BNT-009: check recipient prefs before send; audit trail |
| openconnector adapter down → silent failure | Medium | Retry logic + audit trail; admin alert on repeated failures |
| Template variable naming conflicts | Low | Namespace variables: `booking.*`, `recipient.*`, `system.*` |

## Migration Plan

1. **Initial load** (this change): Deploy OpenRegister entities for triggers +
   templates. Seed three default templates. Enable trigger service listener.
2. **Configuration phase**: Admins/organizers enable triggers and customize
   templates via modal UI.
3. **Observability phase** (tier-2): Launch notification history UI for
   customers and admins to verify delivery.
4. **Preference management** (tier-3): Customer portal to manage frequency,
   channel, opt-out.

Backward compatible — no breaking changes to existing booking schema or APIs.

## Open Questions

1. **Reminder trigger granularity** — allow organizers to set custom intervals
   (e.g., 2h before) or fixed options (24h/1h/15m only)? Resolved in tier-2
   based on usage data.
2. **Default notification language** — auto-detect from user locale or always
   Dutch (nl_NL)? Resolved in design review with `/test-persona-janwillem`.
3. **Admin dashboard for trigger monitoring** — standalone report or embedded
   in Bookings settings? Resolved in implementation with UX review.
