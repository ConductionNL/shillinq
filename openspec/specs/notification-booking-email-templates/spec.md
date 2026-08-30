---
status: done
---

# Spec: Booking Email Templates

**Scope:** notification-booking-email-templates
**Tier:** T2 — capability
**Status:** draft
**Applies to:** Nextcloud Booking

## Purpose

Declared templates for automated booking emails: confirmation,
reminder, cancellation. Operators customize branding (logo, colors,
footer) and template text without code access. Templates integrate
with OR's notification engine for dispatch on booking lifecycle
events.

## Data Model

@e2e exclude unbuilt UI: email template management pages not yet implemented


### BookingConfirmationTemplate

Email template sent immediately upon booking creation.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique template identifier |
| name | string | Yes | Human-readable template name |
| status | enum | Yes | `draft` \| `published` \| `archived` |
| subjectLine | string | Yes | Email subject; supports {{variables}} |
| htmlBody | string | Yes | HTML email body (inline styles only) |
| plainTextBody | string | Yes | Plain-text fallback |
| logoUri | string | No | URL to organization logo image |
| accentColor | string | No | Hex color code for branding (e.g., #0066cc) |
| footerText | string | No | Custom footer text (address, contact, legal) |
| createdAt | datetime | Yes | Template creation timestamp |
| updatedAt | datetime | Yes | Last modification timestamp |
| activatedAt | datetime | No | When template was first published |

**Relations:**
- Contains variable bindings (implicit via {{}} placeholders in text)

### BookingReminderTemplate

Email template sent N hours before booking start.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique template identifier |
| name | string | Yes | Human-readable template name |
| status | enum | Yes | `draft` \| `published` \| `archived` |
| hoursBeforeBooking | integer | Yes | Hours before start to send reminder (e.g., 24, 2, 1) |
| subjectLine | string | Yes | Email subject; supports {{variables}} |
| htmlBody | string | Yes | HTML email body (inline styles only) |
| plainTextBody | string | Yes | Plain-text fallback |
| logoUri | string | No | URL to organization logo image |
| accentColor | string | No | Hex color code for branding |
| footerText | string | No | Custom footer text |
| createdAt | datetime | Yes | Template creation timestamp |
| updatedAt | datetime | Yes | Last modification timestamp |
| activatedAt | datetime | No | When template was first published |

**Relations:**
- Contains variable bindings (implicit)

### BookingCancellationTemplate

Email template sent when booking is cancelled.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string | Yes | Unique template identifier |
| name | string | Yes | Human-readable template name |
| status | enum | Yes | `draft` \| `published` \| `archived` |
| subjectLine | string | Yes | Email subject; supports {{variables}} |
| htmlBody | string | Yes | HTML email body (inline styles only) |
| plainTextBody | string | Yes | Plain-text fallback |
| logoUri | string | No | URL to organization logo image |
| accentColor | string | No | Hex color code for branding |
| footerText | string | No | Custom footer text |
| cancellationReasonRequired | boolean | No | Whether booking cancellation reason is included in template variables |
| createdAt | datetime | Yes | Template creation timestamp |
| updatedAt | datetime | Yes | Last modification timestamp |
| activatedAt | datetime | No | When template was first published |

**Relations:**
- Contains variable bindings (implicit)

## Template Variables

All templates support the following variable substitutions via
`{{variableName}}` syntax in subject and body fields:

### Standard Variables (all templates)

| Variable | Type | Example | Description |
|----------|------|---------|-------------|
| `{{customerName}}` | string | "Jan van der Berg" | Customer full name |
| `{{bookingRef}}` | string | "BK20260521001" | Booking reference/ID |
| `{{bookingDate}}` | date | "21 mei 2026" | Booking date in locale format |
| `{{bookingTime}}` | time | "14:30" | Booking start time in locale format |
| `{{bookingLocation}}` | string | "Kantoor Amsterdam, Kamer 3" | Physical or virtual location |
| `{{organizationName}}` | string | "Example BV" | Organization/business name |

### Reminder-Specific Variables

| Variable | Type | Example | Description |
|----------|------|---------|-------------|
| `{{hoursUntilBooking}}` | integer | "24" | Hours until booking start (calculated) |

### Cancellation-Specific Variables (if reason included)

| Variable | Type | Example | Description |
|----------|------|---------|-------------|
| `{{cancellationReason}}` | string | "Operator requested cancellation" | Reason for cancellation (optional) |

Variables are substituted at dispatch time by OR's notification
engine. Undefined variables are rendered as empty string.

## Requirements

### REQ-BET-001: Template CRUD operations

The system SHALL satisfy this requirement: Template CRUD operations.

#### Scenario: Create a new confirmation template

**GIVEN** an operator accessing the Confirmation Templates admin
page,
**WHEN** the operator clicks "Create New Template" and fills in:
- Template name: "Welcome Booking Confirmation"
- Subject: "Boeking bevestigd: {{bookingRef}}"
- HTML body: `<h1>Hallo {{customerName}}</h1>...`
- Plain-text body: "Hallo {{customerName}}..."
- Logo URI: "https://cdn.example.org/logo.png"
- Accent color: "#0066cc"
- Footer: "© 2026 Example BV, Postbus 123, Amsterdam"
**THEN** the template is saved with status `draft` and shows in the
template list.

### REQ-BET-002: Template lifecycle — draft to published

The system SHALL satisfy this requirement: Template lifecycle — draft to published.

#### Scenario: Publish a template from draft

**GIVEN** a template in status `draft`,
**WHEN** the operator clicks "Publish",
**THEN** the template status changes to `published` and
`activatedAt` is set to current timestamp. Published templates are
available for dispatch.

### REQ-BET-003: Template lifecycle — published to archived

The system SHALL satisfy this requirement: Template lifecycle — published to archived.

#### Scenario: Archive an active template

**GIVEN** a template in status `published`,
**WHEN** the operator clicks "Archive",
**THEN** the template status changes to `archived`. New emails are
not dispatched using archived templates; existing instances remain
visible in history.

### REQ-BET-004: Variable rendering in email subject and body

The system SHALL satisfy this requirement: Variable rendering in email subject and body.

#### Scenario: Dispatch confirmation with variables substituted

**GIVEN** a published `BookingConfirmationTemplate` with:
- Subject: `"Boeking bevestigd: {{bookingRef}}"`
- Body: `"Hallo {{customerName}}, uw boeking op {{bookingDate}} om {{bookingTime}} is geconfirmeerd."`
**WHEN** the template is rendered with variables:
  - `customerName`: "Femke Jansen"
  - `bookingRef`: "BK20260521042"
  - `bookingDate`: "22 mei 2026"
  - `bookingTime`: "10:00"
**THEN** the rendered email subject is:
  `"Boeking bevestigd: BK20260521042"`
and the body begins:
  `"Hallo Femke Jansen, uw boeking op 22 mei 2026 om 10:00 is geconfirmeerd."`

### REQ-BET-005: Reminder template timing configuration

The system SHALL satisfy this requirement: Reminder template timing configuration.

#### Scenario: Send reminder 24 hours before booking

**GIVEN** a published `BookingReminderTemplate` with `hoursBeforeBooking: 24`,
**WHEN** a booking is scheduled for "2026-05-22 14:00" and the system
time is "2026-05-21 14:05",
**THEN** the reminder email is queued for dispatch at "2026-05-21 14:00"
(24 hours before). If queued time has already passed, dispatch
immediately.

### REQ-BET-006: Plain-text fallback for email clients

The system SHALL satisfy this requirement: Plain-text fallback for email clients.

#### Scenario: Email client without HTML rendering falls back to plain-text

**GIVEN** a template with:
- HTML body: `"<h1>Hallo {{customerName}}</h1><p>Uw boeking is bevestigd.</p>"`
- Plain-text body: `"Hallo {{customerName}}\nUw boeking is bevestigd."`
**WHEN** dispatched to an email client that does not support HTML
(or HTML rendering disabled),
**THEN** the plain-text body is displayed instead.

### REQ-BET-007: Branding customization per template

The system SHALL satisfy this requirement: Branding customization per template.

#### Scenario: Apply different logos and colors to different template types

**GIVEN** three templates: Confirmation, Reminder, Cancellation,
**WHEN** the Confirmation template has:
- Logo: "https://cdn.example.org/logo-standard.png"
- Accent color: "#0066cc"
and the Cancellation template has:
- Logo: "https://cdn.example.org/logo-partner.png"
- Accent color: "#cc0000"
**THEN** rendered emails show the respective logos and colors without
requiring three separate organizational branding profiles.

### REQ-BET-008: Template version management (draft vs published)

The system SHALL satisfy this requirement: Template version management (draft vs published).

#### Scenario: Update a published template as draft; keep current live

**GIVEN** a published `BookingConfirmationTemplate` (v1),
**WHEN** the operator creates a new version (clones to draft) and
modifies text and branding,
**THEN** the new draft version is independent; the original published
v1 remains active for dispatch until the new version is published.
At publication, the system uses the new version for future dispatches.

### REQ-BET-009: Cancellation template with optional reason

The system SHALL satisfy this requirement: Cancellation template with optional reason.

#### Scenario: Include booking cancellation reason in email

**GIVEN** a `BookingCancellationTemplate` with:
- `cancellationReasonRequired: true`
- Body: `"Uw boeking {{bookingRef}} is geannuleerd.\nReden: {{cancellationReason}}"`
**WHEN** a booking is cancelled with reason "Customer requested",
**THEN** the email shows:
`"Uw boeking BK20260521042 is geannuleerd.\nReden: Customer requested"`

### REQ-BET-010: Integration with OR notification engine

The system SHALL satisfy this requirement: Integration with OR notification engine.

#### Scenario: Template dispatch via OR notification abstraction

**GIVEN** a published template and a booking event trigger,
**WHEN** the booking system emits a "booking.confirmed" event,
**THEN** OR's notification engine consults the active
`BookingConfirmationTemplate`, renders it with booking variables,
and dispatches the email to the customer via configured email
provider (no direct SMTP calls from app code).

## Non-Functional Requirements

### NFR-BET-001: Email rendering

Email templates use conservative HTML: inline styles only, no
external CSS, no JavaScript. Supported HTML elements limited to
commonly rendered subset (div, p, h1-h6, a, img, table, ul, ol, li,
br, span, strong, em).

### NFR-BET-002: Subject line length

Email subject lines are limited to 78 characters (including variable
substitutions) for cross-client compatibility.

### NFR-BET-003: Template body size

Email body (HTML + plain-text) is limited to 102 KB to comply with
SMTP size limits and reduce bounce risk.

### NFR-BET-004: Branding asset loading

Logo images are loaded from CDN or document-store URI; inline
base64-encoded images are not used (to keep email size small and
support updates without resending).

### NFR-BET-005: Locale-aware variable formatting

Date/time variables ({{bookingDate}}, {{bookingTime}}) are
formatted according to the system locale or customer language
preference (resolved during implementing cycle).

## Open Questions

1. **Reminder template multiplicity** — can an operator configure
   multiple reminder templates (2h, 24h, 1h before)?
2. **Template preview/test render** — how operators test templates
   before publication (mock variables).
3. **Email provider abstraction** — OR's notification engine details
   (SMTP vs service abstraction).
