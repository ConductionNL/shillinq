# Design — Appointment Confirmation Flow

**status: draft**

## Context

The Nextcloud Bookings app enables customers to self-book appointments (phase 1, `bookings-create-appointment`). However, self-booked appointments start in `pending_confirmation` state — the customer has expressed intent, but the appointment is not yet confirmed.

Confirmation serves two purposes:
1. **Customer opt-in** — customer reviews appointment details and confirms attendance
2. **Calendar integration** — customer's native calendar (Outlook, Google Calendar, Apple) imports the appointment directly
3. **Operator visibility** — pending confirmations are tracked; unconfirmed appointments can auto-cancel after a deadline

Without confirmation, the booking pipeline is incomplete: no-shows remain unknown, and calendar apps are not aware of the appointment.

This change defines the confirmation workflow, token lifecycle, ICS generation, and email delivery to complete the customer booking journey.

## Goals

- **Frictionless confirmation** — one click (calendar app) or one form submission (web portal) confirms appointment
- **Calendar-native** — customer's calendar app recognizes the appointment without manual sync
- **Email-failsafe** — if customer loses calendar invite, email includes fallback web link
- **Operator control** — confirmation deadline is configurable; unconfirmed appointments auto-cancel
- **Timezone aware** — customer sees appointment in their local timezone; ICS includes timezone metadata
- **Audit trail** — every confirmation action (token generation, email sent, confirmation received) is logged
- **Reusable templates** — confirmation email uses templates from `bookings-notification-triggers` with confirmation-specific variables

## Non-Goals

- **SMS confirmation** — SMS-based OTP; handled by phase 3 `bookings-reminder-notifications`
- **Multi-party confirmation** — group confirmations (multiple attendees); phase 3+
- **Recurring appointments** — confirmation workflows for recurring series; phase 2+
- **Custom token validators** — application-specific business logic (e.g., "confirm only if payment is cleared"); phase 2+ extensibility

## Decisions

### D1 — Confirmation as a State Transition (Lifecycle per ADR-031)

**Decision**: `Appointment.status` transitions from `pending_confirmation` → `confirmed` (or `cancelled` if deadline passes). The transition is guarded by a confirmation token validation.

**Why**: Consistent with OR's `x-openregister-lifecycle` pattern (used in T1 Shillinq changes). Avoids app-local state machines; leverages OR's audit trail for every transition.

**Alternative**: Separate `Confirmation` entity that references `Appointment`. Rejected — adds join complexity; status transitions are simpler in the appointment itself.

### D2 — Confirmation Token as a Separate Register

**Decision**: `ConfirmationToken` is its own register in `lib/Settings/bookings_register.json`, with FK to `Appointment`. Token lifecycle is managed separately (generate, validate, redeem, expire).

**Why**: Tokens are short-lived and numerous (many resends). Keeping them separate allows independent archival/cleanup. Audit trail on `ConfirmationToken` is clearer than mixing token-generation events into `Appointment` history.

**Alternative**: Embed token string in `Appointment` record as a single-use field. Rejected — difficult to support token resends without overwriting history; harder to track multiple confirmation attempts.

### D3 — ICS Generation as a Utility Service (Not a Calculation)

**Decision**: ICS composition happens in a service class (`lib/Service/IcsService.php`), invoked during email template rendering. ICS is attached to the email as a MIME part (`Content-Disposition: attachment; filename="appointment.ics"`).

**Why**: ICS requires business logic (e.g., add LOCATION from Resource.location, add ORGANIZER from Service owner). This is simpler in PHP than in a declarative `x-openregister-calculations` block. Email attachment handling is standard SMTP/MIME; OR-calculated fields are not designed for binary attachment composition.

**Alternative**: Declare ICS as a calculated field on `ConfirmationToken` or `Appointment`. Rejected — adds complexity; ICS is an artifact of email delivery, not a persistent field.

### D4 — Email Delivery via openconnector (Per ADR-022)

**Decision**: Send confirmation email through `openconnector` notification channels (email adapter). Confirmation token + ICS are passed as template variables; openconnector handles SMTP delivery.

**Why**: Per ADR-022, notifications route through shared abstractions. No app-local mail sending. openconnector's email adapter is already available; no new integration needed.

**Alternative**: Direct PHPMailer integration in Bookings. Rejected — violates ADR-022; duplicates openconnector's job.

### D5 — Token Expiration and One-Time Use

**Decision**: Tokens have two constraints:
1. **Expiration** — token is invalid after N hours (e.g., 7 days) from generation
2. **One-time use (optional)** — token can be redeemed once; subsequent attempts fail

Phase 1 implements expiration only (customer can use same token repeatedly). One-time use is phase 2+ (requires re-request button in customer portal).

**Why**: Expiration is essential (prevents eternal confirmation windows). One-time use adds complexity (re-request UX, re-send emails) that belongs in phase 2. Phase 1 prioritizes simplicity.

**Alternative**: One-time use only, no expiration. Rejected — customer loses email and has no way to confirm.

### D6 — Confirmation Deadline (Business Rule)

**Decision**: `Appointment` record includes optional `confirmationDeadline` (datetime). If deadline passes and `status` is still `pending_confirmation`, the appointment is auto-cancelled by a background job.

**Why**: Operators need control over how long an appointment can be "pending" before it's abandoned. 48–72 hours is typical (customer has 2–3 days to confirm). Expiring unconfirmed slots back to availability.

**Alternative**: Fixed global policy (all appointments must confirm within 48h). Rejected — different services have different requirements (urgent services might require 24h; vacation rentals 30 days).

### D7 — ICS METHOD and ATTACH Strategy

**Decision**: ICS includes `METHOD: REQUEST` (not PUBLISH). The VEVENT block includes an ATTACH property with `FMTTYPE: text/calendar`, pointing to the ICS file itself (self-referential). The email body includes a fallback web-link for calendar apps that don't auto-import.

**Why**: REQUEST signals to calendar apps that customer action is required (tentative acceptance). ATTACH allows calendar apps to open/import directly. Web link is the fail-safe for email-only clients.

**Alternative**: METHOD: PUBLISH (calendar app auto-adds as tentative without confirmation). Rejected — loses customer intent signal; no explicit opt-in.

### D8 — Timezone Handling: TZID with VTIMEZONE Block

**Decision**: ICS includes a VTIMEZONE block with full DAYLIGHT / STANDARD rules for the customer's timezone (derived from their account locale or IP GeoIP lookup). VEVENT.DTSTART uses TZID reference (e.g., `DTSTART;TZID=Europe/Amsterdam:20260522T143000`).

**Why**: Calendar apps need timezone definitions to display the appointment correctly. Including VTIMEZONE ensures the appointment displays at the correct local time on the customer's device, even if the customer later moves to a different timezone.

**Alternative**: All times in UTC (e.g., `DTSTART:20260522T123000Z`). Rejected — calendar apps display UTC times, confusing the customer ("my 2:30 PM appointment shows as 12:30 UTC").

## Entities & Relationships

### `ConfirmationToken` (new register)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `tokenId` | string (UUID) | Yes | Unique token identifier |
| `appointmentId` | string (FK) | Yes | Reference to `Appointment` |
| `tokenString` | string (secure hash) | Yes | The actual token string (e.g., 32-char random); stored as salted hash for security |
| `expiresAt` | datetime | Yes | Token expiration time (e.g., +7 days from creation) |
| `status` | enum | Yes | `active`, `redeemed`, `expired`, `revoked` |
| `redeemedAt` | datetime | No | Timestamp when token was used to confirm appointment |
| `createdAt` | datetime | Yes (auto) | Token generation timestamp |
| `createdBy` | string | Yes (auto) | User ID who initiated confirmation (auto-system for customer self-service) |

### `Appointment` (extended)

**CHANGED fields** (from `bookings-create-appointment`):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `confirmationDeadline` | datetime | No | Latest time customer can confirm before auto-cancel |
| `confirmedAt` | datetime | No | Timestamp when appointment was confirmed (moved from pending_confirmation to confirmed) |
| `confirmationTokenId` | string (FK) | No | Reference to the current valid `ConfirmationToken` |

**New lifecycle transitions** (see REQ-BCF-004 below):
- `pending_confirmation` → `confirmed` (on token redemption)
- `pending_confirmation` → `cancelled` (on deadline expiry or manual cancellation)

### Relationships

- `ConfirmationToken.appointmentId` → `Appointment` (many-to-one; one appointment may have multiple tokens across time if customer requests resend)
- `Appointment.confirmationTokenId` → `ConfirmationToken` (optional FK to the "current" token; for efficient queries like "show pending confirmations with token expiring soon")

## Reuse Analysis

| Component | Source | Reuse | Notes |
|-----------|--------|-------|-------|
| Email template system | `bookings-notification-triggers` | Yes | Confirmation email uses templates with variables (appointmentTime, confirmLink, icsAttachment, timezone) |
| Notification delivery | `openconnector` email channel | Yes | Email sending is delegated; no custom SMTP |
| State machine | OR `x-openregister-lifecycle` | Yes | Confirmation transition is managed by OR lifecycle if engine supports conditional guards |
| Audit trail | OpenRegister | Yes | Token generation, confirmation, expiration all logged automatically |
| Customer entity | `bookings-create-appointment` | Yes | `Appointment.customerId` references existing `Customer` |
| Service entity | `bookings-service-catalog` | Yes | `Appointment.serviceId` references existing `Service` |
| ICS standard | RFC 5545 | Yes | Leverages IETF standard; no custom format |

## Test Data

Seed three example confirmations (Dutch, UTC+1 timezone):

1. **Pending appointment** — startTime: 2026-05-23T14:30:00+02:00 (Amsterdam), duration 30 min, confirmationDeadline: 2026-05-21T23:59:59Z (48h before start), token expires in 3 days
2. **Expired token** — startTime: 2026-05-20T10:00:00+02:00, token.expiresAt in the past, status: `expired`, appointment.status: `cancelled`
3. **Confirmed appointment** — startTime: 2026-05-25T16:00:00+02:00, confirmationToken.status: `redeemed`, confirmedAt: 2026-05-20T09:15:00Z (customer confirmed 5 days early)
