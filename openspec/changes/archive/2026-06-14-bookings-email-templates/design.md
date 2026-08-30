# Design — Booking Email Templates

## Context

Market intelligence confirms all 21 competitors offer branded email
templates for booking confirmations, reminders, and cancellations.
Dutch SMB booking operators need to customize template text, branding
(logo, colors, footer), and configure reminder timing without
technical expertise.

Per ADR-031, template management is declarative (register-based with
lifecycle). Per ADR-022, template dispatch consumes OR's notification
engine abstraction. This change locks those decisions into the spec.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express email template surface as **declarative metadata** — three
  template registers + lifecycle + branding + variable bindings —
  per ADR-031.
- Consume OR's notification engine — per ADR-022. Zero parallel
  notification table.
- Make the spec a **competent-SMB-operator readable contract** —
  template customization (branding, text, timing) recognisable
  end-to-end without code access.
- Keep the variable schema extensible so future booking entities
  (Task, Event, Appointment) can bind custom variables additively.

## Non-Goals

- No WYSIWYG template designer UI — roadmap item for T3.
- No SMS/WhatsApp templates — email only; multi-channel roadmap
  for T3.
- No delivery tracking (bounce, failed, delivered status) — T3.
- No A/B testing or variant analytics — T3.
- No booking integration — this change is template-only; booking
  lifecycle triggers landing in future change.

## Decisions

### D1 — Three separate template registers for lifecycle control

Three registers (`BookingConfirmationTemplate`,
`BookingReminderTemplate`, `BookingCancellationTemplate`) allow
operators to manage each template's lifecycle (draft → published →
archived) and versions independently. Confirmation published while
Reminder remains draft; later Cancellation archived while others
active.

**Alternative considered**: Single monolithic `BookingTemplate`
with a `type` enum. Rejected — separate registers enable granular
activation/deactivation and clearer permissions (operator may edit
confirmation but not cancellation).

### D2 — Branding as template-level config fields

Logo URI, accent color, footer text stored as fields on each
template. Operator configures once per template; reusable across
instances.

**Alternative considered**: Global branding pulled from Corporation
settings. Rejected — operator may need different logo per template
(e.g., partner co-branding on cancellation) or per booking type.
Template-level overrides give flexibility.

### D3 — Variable substitution as `x-openregister-calculations`

Template body is a string with mustache-style placeholders
(`{{customerName}}`, `{{bookingDate}}`). An `x-openregister-
calculations` field renders template with provided variable map.

**Alternative considered**: Baked-in template rendering service
(PHP `BookingTemplateService::render()`). Rejected per ADR-031 —
transformation from data → string is a calculation, not business
logic.

### D4 — Plain-text fallback required

Every template declares `subjectLine`, `htmlBody`, and `plainTextBody`.
Email clients without HTML rendering fallback to plain-text. Inline
styles only (no external CSS).

**Alternative considered**: HTML-only templates. Rejected — email
client fragmentation (Gmail apps, older Outlook, mobile clients) makes
plain-text fallback essential for delivery reliability.

### D5 — Reminder templates include timing configuration

`BookingReminderTemplate` carries `hoursBeforeBooking` field
(e.g., 24, 2, 1 hour reminders). Operator configures once; booking
system checks schedule and dispatches at configured time.

**Alternative considered**: Multiple reminder template variants
(e.g., `Reminder1HourBefore`, `Reminder24HoursBefore`). Rejected —
single template with configurable timing is simpler and covers the
common case (one reminder per booking).

### D6 — Template lifecycle is simple (draft → published → archived)

Templates move through three states. Draft = not yet active. Published
= actively dispatched on booking events. Archived = previously
published, no longer active (kept for audit/history).

**Alternative considered**: Complex workflow (draft → review → approved
→ scheduled → active → archived). Rejected — Dutch SMB workflows are
simpler; review/approval handled outside template system (via
admin permissions).

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Template lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on all three template registers (draft → published → archived) |
| Email dispatch | OR notification engine (ADR-022) | Templates dispatch via OR's notification abstraction; no bespoke email service |
| Template rendering | OR `x-openregister-calculations` (ADR-031) | Variable substitution is a calculation field; no PHP render service |
| Branding config | New fields per register | Template-level branding (logo, colors, footer); extensible without migration |
| Variable bindings | Declarative map | Customer, booking ref, date, time, location; extensible for future booking types |

**Net new code in implementation cycle**: 3 schema declarations + 3
lifecycle blocks + 3 calculation fields + 3 manifest entry pairs.
No PHP service classes (per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Template lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Variable substitution | Declarative (`x-openregister-calculations`) | Pure data → string transformation |
| Email dispatch | Consumed from OR notification-engine | ADR-022 |
| Branding customization | Template-level config fields | Operator-managed; no service logic |

No service class authored in this envelope.

## Seed Data

#### BookingConfirmationTemplate (example)

```json
{
  "id": "default-confirmation",
  "name": "Default Booking Confirmation",
  "status": "published",
  "subjectLine": "Boeking bevestigd: {{bookingRef}}",
  "htmlBody": "<html><body>...",
  "plainTextBody": "Geachte {{customerName}}, ...",
  "logoUri": "https://example.org/logo.png",
  "accentColor": "#0066cc",
  "footerText": "© 2026 Example Org"
}
```

#### BookingReminderTemplate (example)

```json
{
  "id": "reminder-24h",
  "name": "24-Hour Booking Reminder",
  "status": "published",
  "hoursBeforeBooking": 24,
  "subjectLine": "Herinnering: Uw boeking op {{bookingDate}}",
  "htmlBody": "...",
  "plainTextBody": "..."
}
```

#### BookingCancellationTemplate (example)

```json
{
  "id": "default-cancellation",
  "name": "Default Booking Cancellation",
  "status": "published",
  "subjectLine": "Boeking geannuleerd: {{bookingRef}}",
  "htmlBody": "...",
  "plainTextBody": "..."
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Email rendering variation across clients | Plain-text fallback always included; template testing/preview surface before publication; email client testing in QA tasks |
| Branding assets (images) may not load in email | Logo hosted on reliable CDN or doc-store; URI references only (no embedded base64); fallback to text if image fails |
| Reminder timing misses (race condition on exact hour) | Booking system checks schedule with tolerance window (±15 min); retries on dispatch failure via OR's notification engine |
| Variable binding too rigid for custom booking types | Schema is extensible; future booking entities (Event, Task) add variables without schema migration |
| Operator confusion on template activation | Help text + preview feature clarify which template is active; manifest UI shows lifecycle state prominently |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/booking_register.json` is created with the three
   template schemas (additive).
2. `src/manifest.json` is patched with 3 template admin navigation
   entries (additive).
3. Seed data (3 default templates per language) seeded into OR on
   app installation.

Down-direction: templates are non-destructive — reverting removes
the manifest entries and lifecycle bindings; dispatched emails
remain but templates become unreferenced and editable from history.

## Open Questions

1. **Reminder template frequency** — single cadence or multiple
   reminders — resolved during implementing cycle's UX review.
2. **Default branding source** — operator-supplied in admin settings
   or read from Corporation profile metadata — resolved during
   implementing cycle.
3. **Template preview/test rendering** — resolved in discovery
   against OR's notification engine capabilities.
