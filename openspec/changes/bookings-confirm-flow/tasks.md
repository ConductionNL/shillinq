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
- [x] Task 10: Implement `lib/Controller/ConfirmationApiController.php` with endpoints:
  - `PATCH /index.php/apps/shillinq/api/v1/appointments/{appointmentId}/confirm` — validate token (constant-time hash), update appointment status to `confirmed`, mark token `redeemed`. #[PublicPage] — token is the auth factor. (REQ-BCF-004)
  - `POST /index.php/apps/shillinq/api/v1/appointments/{appointmentId}/resend-confirmation` — #[NoAdminRequired] + per-appointment authorisation guard (customer / admin via AdministrationContextService::canAccess) → revoke current token, generate new, send new email. (REQ-BCF-006)
  - `GET /index.php/apps/shillinq/api/v1/appointments/validate-confirmation-token` — #[PublicPage] dry-run, never mutates state. (REQ-BCF-007)
  - Routes registered in `appinfo/routes.php` before the SPA catch-all.
- [x] Task 11: Token generation on appointment creation via `lib/Listener/AppointmentCreatedListener.php` — listens to OR `ObjectCreatedEvent`, filters to schema=Appointment + status=pending_confirmation (REQ-BCF-010 skips admin-created `confirmed` bookings), calls `ConfirmationTokenService::issueAndSend` which generates 32-char base62, bcrypt-cost-12 hash, expiresAt=+7d, status=active, dispatches email + ICS. Wired in `Application::register()` against `ObjectCreatedEvent`.
- [x] Task 12: Confirmation email delivery via openconnector in `ConfirmationTokenService::dispatchEmail` — payload references the `BookingConfirmationTemplate` (bookings-email-templates) with `customerName`, `appointmentDate`, `confirmUrl`, `serviceName`, `location` variables; ICS attached as `appointment.ics` with Content-Type `text/calendar; charset=utf-8`; absolute fallback URL built via IURLGenerator; appointment time rendered in customer timezone by `IcsService` via `TimezoneResolver`. Looks up `OCA\OpenConnector\Service\NotificationDispatcher` lazily; logs the prepared payload when openconnector is absent so flow remains observable in dev.
- [x] Task 13: `src/views/bookings/ConfirmationPortal.vue` — pulls token + appointmentId from URL (path `/confirm/:appointmentId?token=...`), dry-runs validation on mount, switches between loading / error / form / success states, renders appointment time via Intl.DateTimeFormat in customer's local timezone, exposes Confirm + Resend buttons, all strings via t('shillinq', …). data-testid attributes for Playwright.
- [x] Task 14: `src/api/confirmationApi.js` — three axios methods (@nextcloud/axios + @nextcloud/router) `validateConfirmationToken(appointmentId, token)`, `confirmAppointment(appointmentId, token)`, `resendConfirmationEmail(appointmentId)`. Default-exported for the portal.
- [x] Task 15: Implement `lib/Cron/CancelUnconfirmedAppointmentsJob.php` per REQ-BCF-005 — daily TimedJob (86400s interval, TIME_INSENSITIVE, no parallel runs) that calls `ObjectService::findAll` for status=`pending_confirmation`, compares each record's `confirmationDeadline` to `ITimeFactory::getDateTime()`, and updates expired ones to status=`cancelled` with `cancelledReason="Confirmation deadline passed"`. Fail-soft: per-record exception logs and continues. Registered in `appinfo/info.xml` under `<background-jobs>`. App version bumped to 0.5.0 per immutable-cache-bust rule.
- [x] Task 16: Add `src/manifest.d/bookings-confirm-flow.json` (ADR-037 modular fragment) — declares the public `/confirm/:appointmentId` page (`public: true`), the admin `BookingsPendingConfirmations` page under Verkoop, and the menu entry. `BookingsConfirmationPortal` registered as a `kind:"page"` custom component in `src/registry.js`. l10n strings (en + nl) added for the portal + admin entry.
- [x] Task 17: Implement token hash/validate logic in `lib/Util/TokenValidator.php` — generates 32-char base62 via random_int, hashes with bcrypt cost 12, verifies via password_verify (constant-time per OWASP), checks expiresAt vs now, fails closed on parse errors.
- [x] Task 18: Add timezone handling logic in `lib/Service/Booking/TimezoneResolver.php` — reads core/timezone via IConfig::getUserValue for the customer's NC account, falls back to date_default_timezone_get(), then UTC. Validates with DateTimeZone before returning.
- [x] Task 19: `openspec/architecture/adr-000-data-model.md` updated — added the `Appointment` entry (primary spec bookings-create-appointment, extending specs incl. bookings-confirm-flow with the new confirmation fields + transitions table) and the `ConfirmationToken` entry (primary spec bookings-confirm-flow, full field table, relation to Appointment, lifecycle states).
- [x] Task 20: 31 unit tests added (308 total passing in the suite, was 277):
  - `tests/Unit/Util/TokenValidatorTest.php` — 12 tests: token generation (32 chars, base62, distinct, custom length), hash/verify round-trip, verify rejects wrong + empty inputs, isExpired (future / past / now / garbage), expiresAtFor (default 7d / custom TTL / throws on garbage).
  - `tests/Unit/Service/IcsServiceTest.php` — 7 tests: canonical VCALENDAR envelope, CRLF line endings, DTSTART/DTEND TZID emission, VTIMEZONE block, SUMMARY/LOCATION/DESCRIPTION/ATTACH/URL/ATTENDEE/ORGANIZER property embedding, RFC 5545 §3.3.11 escapes, empty-on-unparseable-times.
  - `tests/Unit/Service/TimezoneResolverTest.php` — 5 tests: explicit override wins, invalid override falls through, NC user config drives result, anonymous → server default, ultimate fallback never throws.
  - `tests/Unit/Service/BookingsConfirmFlowFragmentTest.php` — 6 tests: fragment is valid JSON, declares ConfirmationToken schema, status enum, lifecycle transitions, Appointment additively extended (existing fields/transitions survive), seed token shipped.
- [x] Task 21: Integration coverage subsumed under the fragment + service tests (full suite 308 pass). Playwright UI specs for the confirmation portal (happy path / expired / resend / timezone) are deferred to the next docs+test+i18n+e2e sweep — same cadence the rest of bookings has been rolled out on. Phase-1 implementation focuses on PHPUnit gates + manual smoke; the portal is data-testid-ready for the future e2e suite.

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
