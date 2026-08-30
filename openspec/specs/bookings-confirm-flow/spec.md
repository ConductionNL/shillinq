---
status: done
---

# Spec: bookings-confirm-flow

**Status:** proposed
**Scope:** nextcloud-bookings
**Tier:** T2 (customer journey completion)
**Depends on:** bookings-create-appointment, bookings-notification-triggers

## Purpose

This specification defines the requirements for bookings confirm flow in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: appointment confirmation portal not yet implemented


### REQ-BCF-001: The system SHALL store confirmation tokens as an OpenRegister-managed `ConfirmationToken` register

The confirmation token data MUST be declared as a register in `lib/Settings/bookings_register.json` per ADR-024, with the `ConfirmationToken` schema as the canonical entity. No custom PHP model, no custom database table. The register is exposed through OpenRegister's generic CRUD HTTP surface at `GET/POST /ocs/v2.php/apps/openregister/api/objects/bookings/ConfirmationToken`.

#### Scenario: Confirmation token is created when appointment is made

- **GIVEN** an appointment with status `pending_confirmation` and confirmationDeadline set to 48 hours after appointment creation
- **WHEN** the appointment is persisted to the register
- **THEN** the system MUST automatically generate a `ConfirmationToken` record with:
  - `appointmentId` referencing the new appointment
  - `tokenString` as a 32-character random URL-safe string
  - `expiresAt` set to 7 days from now
  - `status: "active"`
  - `createdAt` as current timestamp

#### Scenario: System retrieves token details via the OpenRegister API

- **GIVEN** a confirmation token exists
- **WHEN** an admin calls `GET /index.php/ocs/v2.php/apps/openregister/api/objects/bookings/ConfirmationToken/{tokenId}`
- **THEN** the response MUST return the token record with all fields (excluding tokenString for security)

### REQ-BCF-002: The `ConfirmationToken` schema SHALL declare the minimum field set with typed relations

The `ConfirmationToken` schema MUST declare the following fields. Additional fields MAY be added in future tiers (additive only).

| Field | Type | Required | Description |
|---|---|---|---|
| `tokenId` | string (UUID) | Yes | Unique token identifier |
| `appointmentId` | string (FK to Appointment) | Yes | Reference to the appointment being confirmed |
| `tokenString` | string (hash) | Yes | Secure token string (32 chars, URL-safe base62); stored as salted bcrypt hash for validation |
| `expiresAt` | datetime (ISO 8601 UTC) | Yes | Token expiration time (e.g., "2026-05-28T12:30:00Z", typically +7 days) |
| `status` | enum | Yes | One of: `active`, `redeemed`, `expired`, `revoked` |
| `redeemedAt` | datetime | No | ISO 8601 timestamp when token was redeemed (used to confirm appointment) |
| `createdAt` | datetime | Yes (auto) | Token creation timestamp |
| `createdBy` | string | Yes (auto) | User ID who triggered token creation (system for auto-generated; admin/customer for manual resend) |

OpenRegister's built-in fields (`id`, `uuid`, `version`, `owner`, `auditTrail`, `relations`) are not redeclared per `adr-000-data-model.md`'s convention.

#### Scenario: Token object is validated with required fields

- **GIVEN** the ConfirmationToken schema
- **WHEN** a token object is validated with all required fields (`appointmentId`, `tokenString`, `expiresAt`, `status: "active"`)
- **THEN** validation MUST pass

#### Scenario: Token validation rejects missing appointmentId

- **GIVEN** a ConfirmationToken object missing the `appointmentId` field
- **WHEN** validation is performed
- **THEN** validation MUST fail with error "Field 'appointmentId' is required"

### REQ-BCF-003: Confirmation email SHALL include ICS attachment and fallback web link

When a confirmation token is generated, the system MUST send an email to the customer containing:
1. Confirmation details (appointment date/time, service name, provider name)
2. ICS (iCalendar, RFC 5545) file as MIME attachment (`Content-Type: text/calendar; charset=utf-8`)
3. Fallback web link for token-based confirmation (URL: `/index.php/apps/bookings/confirm/{tokenString}`)
4. Timezone information (customer's local timezone in email body; ICS includes TZID + VTIMEZONE block)

The email is sent via openconnector email channel using templates from `bookings-notification-triggers`.

#### Scenario: Confirmation email is sent on appointment creation with pending status

- **GIVEN** an appointment created with status `pending_confirmation` and customer email "jan@example.nl"
- **AND** openconnector email channel is configured
- **WHEN** the appointment is persisted
- **THEN** the system MUST send an email to "jan@example.nl" with:
  - Subject: "[Bookings] Confirmation needed: {serviceName} on {appointmentDate}"
  - Body: Confirmation details + web link "https://myserver.nl/index.php/apps/bookings/confirm/{tokenString}"
  - Attachment: `appointment.ics` (ICS calendar file)

#### Scenario: ICS attachment is RFC 5545 compliant with TZID

- **GIVEN** an appointment on 2026-05-22T14:30:00 (Amsterdam timezone UTC+2)
- **WHEN** the ICS file is generated
- **THEN** the ICS MUST contain:
  - `VEVENT` block with `DTSTART;TZID=Europe/Amsterdam:20260522T143000`
  - `DTEND;TZID=Europe/Amsterdam:20260522T150000` (assuming 30-min duration)
  - `VTIMEZONE` block with full DAYLIGHT/STANDARD rules for Europe/Amsterdam
  - `METHOD: REQUEST`
  - `SUMMARY: {serviceName}`
  - `LOCATION: {resourceLocation}`
  - `DESCRIPTION: {appointmentNotes}` (if present)
  - `ATTACH;FMTTYPE=text/calendar` property with ICS file reference

### REQ-BCF-004: Appointment SHALL transition from `pending_confirmation` to `confirmed` on token validation

When a customer redeems a confirmation token (via web link or calendar app), the appointment's status MUST transition from `pending_confirmation` to `confirmed`. The transition is guarded by token validation (expiration check, status check).

#### Scenario: Customer confirms appointment via web link

- **GIVEN** a confirmation token with status `active` and expiresAt in the future
- **AND** an appointment with status `pending_confirmation` and appointmentId matching the token
- **WHEN** a POST request is made to `PATCH /ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}/confirm?token={tokenString}`
- **THEN** the system MUST:
  1. Validate token (check expiration, status, hash against stored token)
  2. Update appointment.status to `confirmed`
  3. Update appointment.confirmedAt to current timestamp
  4. Update token.status to `redeemed`
  5. Update token.redeemedAt to current timestamp
  6. Log the confirmation in the appointment's auditTrail
  7. Return HTTP 200 with updated appointment object

#### Scenario: Token validation fails for expired token

- **GIVEN** a confirmation token with expiresAt in the past (status: `active`)
- **WHEN** a customer attempts to confirm using this token
- **THEN** the system MUST:
  1. Reject the confirmation attempt
  2. Return HTTP 403 Forbidden with error "Token has expired"
  3. NOT update the appointment status

#### Scenario: Token validation fails for wrong token string

- **GIVEN** a confirmation token with tokenString = "correct-token-hash"
- **WHEN** a customer attempts to confirm with tokenString = "wrong-token-hash"
- **THEN** the system MUST:
  1. Reject the confirmation attempt
  2. Return HTTP 401 Unauthorized with error "Invalid token"
  3. NOT update the appointment status

#### Scenario: Token with status `redeemed` cannot be reused

- **GIVEN** a confirmation token that has already been redeemed (status: `redeemed`)
- **WHEN** a customer attempts to confirm again with the same token
- **THEN** the system MUST:
  1. Check token.status
  2. Reject the confirmation attempt with HTTP 403 Forbidden
  3. Respond with error "Token has already been used"

### REQ-BCF-005: Appointment SHALL auto-cancel if confirmation deadline passes

Appointments in `pending_confirmation` status with confirmationDeadline in the past MUST be automatically cancelled by a background job. The transition is:
`pending_confirmation` → `cancelled` (with reason "Confirmation deadline passed").

#### Scenario: Background job cancels expired pending appointments

- **GIVEN** an appointment with:
  - status: `pending_confirmation`
  - confirmationDeadline: 2026-05-20T23:59:59Z (in the past)
- **WHEN** the background job `CancelUnconfirmedAppointments` runs at 2026-05-21T01:00:00Z
- **THEN** the system MUST:
  1. Query appointments with status `pending_confirmation` and confirmationDeadline < now
  2. Update each to status `cancelled`
  3. Set cancelledReason to "Confirmation deadline passed"
  4. Log the cancellation in auditTrail (actor: system)
  5. Send optional notification to customer (template: `appointment.cancelled.confirmation_deadline`)

#### Scenario: Confirmed appointments are not cancelled by deadline

- **GIVEN** an appointment with:
  - status: `confirmed`
  - confirmationDeadline: 2026-05-20T23:59:59Z (in the past)
- **WHEN** the background job runs
- **THEN** the appointment MUST remain `confirmed` (no change)

### REQ-BCF-006: Confirmation token resend MUST generate a new token with fresh expiration

When a customer requests a new confirmation email (e.g., "resend confirmation link"), the system MUST:
1. Generate a new `ConfirmationToken` record
2. Revoke or invalidate the previous token (status: `revoked`)
3. Send a new confirmation email with the new token
4. Log the resend action in the appointment's auditTrail

#### Scenario: Customer requests confirmation email resend

- **GIVEN** an appointment with status `pending_confirmation` and a valid (but unacted) confirmation token
- **AND** customer endpoint `POST /ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}/resend-confirmation` is called
- **WHEN** the request is authenticated as the appointment's customer
- **THEN** the system MUST:
  1. Query the current confirmation token for the appointment
  2. Update token.status to `revoked`
  3. Create a new `ConfirmationToken` with fresh expiresAt (+7 days)
  4. Send confirmation email with new token string
  5. Return HTTP 200 with message "Confirmation email resent"

#### Scenario: Revoked token is rejected on confirmation attempt

- **GIVEN** a confirmation token with status `revoked`
- **WHEN** a customer attempts to confirm using this token
- **THEN** the system MUST reject with HTTP 403 Forbidden and error "Token is no longer valid; request a new confirmation email"

### REQ-BCF-007: Web confirmation portal SHALL validate token and display appointment details

The web confirmation portal (`src/views/ConfirmationPortal.vue`) MUST:
1. Accept token via URL query parameter (`?token={tokenString}`)
2. Validate token (call confirmation endpoint with dry-run flag to avoid confirming)
3. Display appointment details (date, time, service name, provider, location, notes)
4. Display timezone (customer's local timezone)
5. Provide a "Confirm Appointment" button that POSTs the token to the confirmation endpoint
6. Handle error states (expired token, invalid token, already confirmed)

#### Scenario: Token is validated on portal load (dry-run)

- **GIVEN** a customer visits `https://myserver.nl/index.php/apps/bookings/confirm?token=abc123def456`
- **WHEN** the portal loads
- **THEN** the system MUST:
  1. Call `GET /ocs/v2.php/apps/bookings/api/v1/appointments/validate-confirmation-token?token={tokenString}` (dry-run, no side effects)
  2. If valid: display appointment details
  3. If expired/invalid: display error message "This confirmation link is no longer valid. Please request a new one."

#### Scenario: Portal displays appointment in customer's timezone

- **GIVEN** an appointment startTime: "2026-05-22T14:30:00Z" (UTC) with customer timezone "Europe/Amsterdam"
- **WHEN** the confirmation portal loads
- **THEN** the system MUST display:
  - Time: "14:30 (UTC+2)" or "2:30 PM Amsterdam time"
  - Timezone hint: "Your time zone: Europe/Amsterdam"

#### Scenario: Customer confirms appointment from portal

- **GIVEN** the portal is loaded with a valid token for pending_confirmation appointment
- **WHEN** customer clicks "Confirm Appointment" button
- **THEN** the system MUST:
  1. POST to `PATCH /ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}/confirm?token={tokenString}`
  2. Display confirmation message "Appointment confirmed!"
  3. Redirect to success page or close modal after 2 seconds

### REQ-BCF-008: Audit trail SHALL record all confirmation events

Every confirmation action (token generation, email sent, confirmation attempt, token expiration, cancellation) MUST be logged in the appointment's auditTrail (per OpenRegister ADR-022).

Audit entry format:
- `actor`: User ID (system for auto-generated) or "anonymous" for unauthenticated token validation
- `action`: `token_generated`, `confirmation_email_sent`, `appointment_confirmed`, `confirmation_email_resent`, `token_revoked`, `appointment_auto_cancelled`
- `timestamp`: ISO 8601 UTC
- `details`: JSON object with relevant context (e.g., `{"tokenId": "xyz", "expiresAt": "...", "emailAddress": "customer@example.nl"}`)

#### Scenario: Confirmation event is logged in appointment auditTrail

- **GIVEN** an appointment with an empty auditTrail
- **WHEN** a confirmation token is generated
- **THEN** the appointment's auditTrail MUST contain an entry:
  - `action: "token_generated"`
  - `actor: "system"`
  - `details: {"tokenId": "...", "expiresAt": "...", "emailChannel": "email"}`

#### Scenario: Token validation attempt is logged even on failure

- **GIVEN** a customer attempts to confirm with an expired token
- **WHEN** the validation fails
- **THEN** the appointment's auditTrail MUST contain an entry:
  - `action: "confirmation_failed"`
  - `details: {"reason": "Token expired", "attemptedTokenId": "..."}`

### REQ-BCF-009: Timezone MUST be preserved in email and ICS

The customer's timezone (derived from Nextcloud user account locale or GeoIP) MUST be used in:
1. ICS TZID and VTIMEZONE block (RFC 5545 compliant)
2. Email body display (human-readable local time)

Timezone identifiers MUST use IANA timezone names (e.g., "Europe/Amsterdam", "America/New_York", "Asia/Tokyo").

#### Scenario: ICS includes VTIMEZONE for customer timezone

- **GIVEN** a customer in timezone "Europe/Amsterdam" (UTC+1 in winter, UTC+2 in summer)
- **AND** an appointment on 2026-05-22 (summer, UTC+2)
- **WHEN** ICS is generated
- **THEN** the ICS MUST:
  - Include `VTIMEZONE` block with TZID=Europe/Amsterdam
  - Contain DAYLIGHT and STANDARD rules with correct offsets and transition dates
  - Use `DTSTART;TZID=Europe/Amsterdam:20260522T143000` (not UTC)

#### Scenario: Email displays appointment in customer timezone

- **GIVEN** appointment startTime: "2026-05-22T14:30:00Z" (UTC), customer timezone: "America/New_York" (UTC-4)
- **WHEN** confirmation email is sent
- **THEN** email body MUST display:
  - "May 22, 2026 at 10:30 AM Eastern Time (UTC-4)"
  - Not: "2:30 PM UTC"

### REQ-BCF-010: Confirmation shall work for both admin-created and customer self-booked appointments

Appointments created by admin (initial status: `confirmed`) do not require confirmation. Appointments created by customers via self-service (initial status: `pending_confirmation`) MUST be confirmed before they are considered active.

#### Scenario: Admin-created appointment skips confirmation flow

- **GIVEN** an admin creates an appointment directly with role=admin (from `bookings-create-appointment` REQ-BCA-005)
- **WHEN** the appointment is created with status `confirmed`
- **THEN** no confirmation token MUST be generated
- **AND** the appointmentId MUST NOT appear in any confirmation email

#### Scenario: Customer self-booked appointment enters confirmation flow

- **GIVEN** a customer creates an appointment via self-service portal with role=customer (from `bookings-create-appointment` REQ-BCA-005)
- **WHEN** the appointment is created with status `pending_confirmation`
- **THEN** a confirmation token MUST be generated automatically
- **AND** a confirmation email MUST be sent to the customer

## Summary of State Transitions

### Appointment Lifecycle with Confirmation

```
admin creates
    ↓
[confirmed] → [completed] → archived
    ↓ (cancellation)
   [cancelled]

customer self-books
    ↓
[pending_confirmation] → [confirmed] → [completed] → archived
    ↓ (deadline passes or manual cancel)
   [cancelled]
```

### ConfirmationToken Lifecycle

```
[active] → [redeemed] (customer confirms)
    ↓
[revoked] (customer requests resend)

[active] → [expired] (7 days pass)
```
