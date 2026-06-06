# Tasks — Appointment Confirmation Flow

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookings-confirm-flow` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are all
> visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookings-confirm-flow` capability spec already exists, no `ConfirmationToken` schema is declared, and no `lib/Service/IcsService.php` or confirmation controller PHP classes are present
- [x] Task 2: Author `specs/bookings-confirm-flow/spec.md` with `Status: proposed` / `Scope: nextcloud-bookings` / `Tier: T2` / `Depends on: bookings-create-appointment, bookings-notification-triggers` header, `REQ-BCF-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; explicitly address confirmation workflow completion and timezone handling
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (ICS calendar app variation, token expiration UX, confirmation deadline automation, timezone TZID) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (confirmation as state transition), D2 (ConfirmationToken as separate register), D3 (ICS as utility service), D4 (openconnector for email), D5 (token expiration + one-time use phases), D6 (confirmation deadline), D7 (ICS METHOD REQUEST with ATTACH), D8 (TZID with VTIMEZONE)
- [x] Task 5: Declare the `ConfirmationToken` schema in `lib/Settings/register.d/bookings-confirm-flow.json` with all REQ-BCF-002 fields (tokenId, appointmentId, tokenString, expiresAt, status, redeemedAt, createdAt, createdBy) with FK relation to `Appointment` (ADR-037 modular fragment)
- [x] Task 6: Extend the `Appointment` schema via the same fragment with CHANGED fields (confirmationDeadline, confirmedAt, confirmationTokenId) — merged additively by SettingsService::deepMergeConfig
- [x] Task 7: Add `x-openregister-lifecycle.transitions` extension to `Appointment` schema declaring `confirmViaToken` (pending_confirmation → confirmed) and `autoCancelExpired` (pending_confirmation → cancelled) — the four base transitions stay owned by bookings-create-appointment
- [x] Task 8: Add `x-openregister-lifecycle` block to `ConfirmationToken` schema declaring token status transitions:
  - `active` → `redeemed` (on token validation)
  - `active` → `revoked` (on resend request)
  - `active` → `expired` (on expiration check, auto-computed from expiresAt)
- [x] Task 9: Implement `lib/Service/IcsService.php` with method `generateIcs(array $appointment, array $customer, string $confirmUrl, array $context): string` per REQ-BCF-003 — emits CRLF-terminated VCALENDAR/VTIMEZONE/VEVENT with METHOD:REQUEST, DTSTART;TZID + DTEND;TZID via TimezoneResolver, SUMMARY/LOCATION/DESCRIPTION/ATTACH/URL/ATTENDEE/ORGANIZER. No file I/O.
- [ ] Task 10: Implement `lib/Controller/ConfirmationApiController.php` with endpoints:
  - `PATCH /ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}/confirm` — validate token, update appointment status to `confirmed`, update token status to `redeemed` (REQ-BCF-004)
  - `POST /ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}/resend-confirmation` — revoke current token, generate new token, send new email (REQ-BCF-006)
  - `GET /ocs/v2.php/apps/bookings/api/v1/appointments/validate-confirmation-token` — dry-run token validation for portal load (no side effects) (REQ-BCF-007)
  - Include error handling for expired tokens, invalid tokens, already-confirmed appointments
- [ ] Task 11: Implement token generation logic on appointment creation (likely in `AppointmentApiController` or a service listener) that:
  - On appointment.create with status=`pending_confirmation`, automatically create `ConfirmationToken` (REQ-BCF-001)
  - Generate 32-char random URL-safe token string
  - Hash token with bcrypt for secure storage
  - Set expiresAt to +7 days
  - Set status to `active`
  - Log token generation in appointment auditTrail
- [ ] Task 12: Implement confirmation email delivery (via openconnector) that:
  - Uses templates from `bookings-notification-triggers` with confirmation-specific variables
  - Calls `IcsService::generateIcs()` to compose ICS content
  - Attaches ICS as MIME part (Content-Type: text/calendar; charset=utf-8)
  - Includes fallback web link `/index.php/apps/bookings/confirm?token={tokenString}`
  - Displays appointment time in customer's local timezone
  - Sends via openconnector email channel (REQ-BCF-003)
- [ ] Task 13: Create `src/views/ConfirmationPortal.vue` per REQ-BCF-007 that:
  - Accepts token via URL query parameter (`?token=...`)
  - On mount, validates token with dry-run endpoint (no confirmation yet)
  - Displays appointment details (date, time, service name, provider, location, notes, timezone)
  - Displays clear error messages for expired/invalid tokens
  - Provides "Confirm Appointment" button that calls confirmation endpoint with token
  - On success, displays "Appointment confirmed!" and redirects or closes after 2 seconds
  - Handles loading states and error states
- [ ] Task 14: Create `src/api/confirmationApi.js` with client methods:
  - `validateConfirmationToken(token: string): Promise<Appointment>` — dry-run validation
  - `confirmAppointment(appointmentId: string, token: string): Promise<Appointment>` — confirm endpoint
  - `resendConfirmationEmail(appointmentId: string): Promise<{message}>` — resend endpoint
- [ ] Task 15: Implement `lib/BackgroundJob/CancelUnconfirmedAppointments.php` per REQ-BCF-005 that:
  - Runs daily (configurable in `CronJob` registration)
  - Queries appointments with status=`pending_confirmation` and confirmationDeadline < now
  - Updates each to status=`cancelled`, sets cancelledReason="Confirmation deadline passed"
  - Logs cancellation in auditTrail (actor: system)
  - Optionally sends cancellation notification to customer (if template exists)
- [ ] Task 16: Extend `src/manifest.json` per REQ-BCF-007:
  - Add navigation entry `Confirmations` (admin-only view showing pending confirmations with expiration warning)
  - Add modal action from appointment detail page: "Resend confirmation email" button
  - Update `nextcloud-app` type and routes
- [x] Task 17: Implement token hash/validate logic in `lib/Util/TokenValidator.php` — generates 32-char base62 via random_int, hashes with bcrypt cost 12, verifies via password_verify (constant-time per OWASP), checks expiresAt vs now, fails closed on parse errors.
- [x] Task 18: Add timezone handling logic in `lib/Service/Booking/TimezoneResolver.php` — reads core/timezone via IConfig::getUserValue for the customer's NC account, falls back to date_default_timezone_get(), then UTC. Validates with DateTimeZone before returning.
- [ ] Task 19: Update `openspec/architecture/adr-000-data-model.md` with `ConfirmationToken` and updated `Appointment` entries, reconciling against any existing token/confirmation data-model entries
- [ ] Task 20: Add 10+ unit tests covering:
  - Token generation (correct hash, expiration, status)
  - Token validation (valid token, expired token, invalid hash, already redeemed, revoked)
  - Appointment status transitions (pending → confirmed, pending → cancelled)
  - ICS generation (RFC 5545 compliance, TZID, VTIMEZONE block, VEVENT fields)
  - Confirmation deadline auto-cancel (background job queries correct records, updates status)
  - Email delivery (openconnector called with correct template, ICS attached)
  - Timezone handling (customer timezone retrieved, used in ICS and email)
- [ ] Task 21: Add 5+ integration tests covering:
  - Happy path: customer receives email, clicks confirmation link, appointment confirms
  - Token resend: customer requests new email, old token revoked, new token sent
  - Expired token: customer tries to confirm after deadline, appointment auto-cancelled
  - Web portal: customer loads ConfirmationPortal with valid token, sees appointment details, confirms
  - Timezone accuracy: appointment in UTC, portal and email show correct local time

## Verification

`openspec validate` must exit clean on the change folder. Bookings-persona peer review (e.g., `/test-persona-priya` for small-business operator) confirms the confirmation flow matches Dutch SMB calendar expectations (email with calendar attachment, fallback web link, timezone display). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (no app-local approval table; ICS is a utility service; lifecycle declarative or properly exception-annotated; manifest carries confirmation portal navigation). No source code changes outside `openspec/changes/bookings-confirm-flow/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:
- PHPUnit unit tests for token generation/validation, ICS generation RFC 5545 compliance, timezone handling, state transitions
- PHPUnit integration tests for appointment confirmation flow (token → email → portal → confirmed state)
- Playwright MCP browser tests for ConfirmationPortal (token validation on load, appointment display, confirm button, success message)
- `composer test` green at the implementing PR's CI gate
- ICS schema validation against RFC 5545 (openssl or similar parser)
- Email MIME validation (ICS attachment present, Content-Type correct, multipart structure valid)

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/bookings/appointment-confirmation.md` per ADR-030 journeydoc convention
- Screenshot: email with ICS attachment
- Screenshot: web confirmation portal with appointment details
- Timezone explanation (how Nextcloud retrieves user timezone, how calendar apps display local time)
- Confirmation deadline explanation (how appointments are auto-cancelled after deadline)

## i18n (company-wide ADR-025)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
- Email subject: "Confirmation needed: {serviceName} on {appointmentDate}"
- Email body: "Please confirm your appointment"
- Web portal title: "Confirm Your Appointment"
- Button: "Confirm Appointment"
- Error: "This confirmation link is no longer valid"
- Error: "Token has already been used"
- Error: "Token has expired"
- Success: "Appointment confirmed!"
- Message: "Your appointment is confirmed"
- Calendar text: "Timezone: {timezone}"
- Admin: "Pending Confirmations"
- Admin action: "Resend confirmation email"
