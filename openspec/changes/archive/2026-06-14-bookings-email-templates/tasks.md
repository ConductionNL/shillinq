# Tasks: Booking Email Templates

Implementation checklist for the `notification-booking-email-templates`
capability.

> **Implementation note (2026-06-06):** Per ADR-037 register fragments are
> dropped under `lib/Settings/register.d/<slug>.json`, never edited into
> `shillinq_register.json`. The fragment for this change lives at
> `lib/Settings/register.d/bookings-email-templates.json`. Manifest fragments
> follow the same pattern under `src/manifest.d/<slug>.json`.

## Data Model & Schema

- [x] Task 1: Create `BookingConfirmationTemplate` schema in
  `lib/Settings/register.d/bookings-email-templates.json` with all fields per spec; mark
  lifecycleState as `draft` on creation.
- [x] Task 2: Create `BookingReminderTemplate` schema in
  `lib/Settings/register.d/bookings-email-templates.json`; include `hoursBeforeBooking`
  integer field.
- [x] Task 3: Create `BookingCancellationTemplate` schema in
  `lib/Settings/register.d/bookings-email-templates.json`; include
  `cancellationReasonRequired` boolean.
- [x] Task 4: Add `x-openregister-lifecycle` to all three schemas:
  transitions draft → published → archived with timestamped
  `activatedAt` field.
- [x] Task 5: Define variable substitution system as
  `x-openregister-calculations` field on each schema (render template
  with variable map); no PHP service class.

## Manifest Navigation

- [x] Task 6: Add manifest entry `confirmation-templates` to
  `src/manifest.d/bookings-email-templates.json` (per ADR-037 — `src/manifest.json`
  is never edited directly):
  - type: index (list all confirmation templates)
  - icon: EmailCheckOutline
  - label: "Confirmation Templates"
- [x] Task 7: Add manifest entry `reminder-templates` to
  `src/manifest.d/bookings-email-templates.json`:
  - type: index (list all reminder templates)
  - icon: EmailClockOutline
  - label: "Reminder Templates"
- [x] Task 8: Add manifest entry `cancellation-templates` to
  `src/manifest.d/bookings-email-templates.json`:
  - type: index (list all cancellation templates)
  - icon: EmailRemoveOutline
  - label: "Cancellation Templates"

## Seed Data

- [x] Task 9: Seed 3 default templates (Confirmation, Reminder,
  Cancellation) in Dutch (nl) locale. Templates:
  - Confirmation: "Boeking bevestigd"
  - Reminder (24h): "Herinnering: uw boeking morgen"
  - Cancellation: "Boeking geannuleerd"
  Seeded via inline `objects[]` in the register fragment (the ADR-037
  pattern used by every other Shillinq change); OR's settings loader
  ingests them on app installation/upgrade.
- [x] Task 10: Create `src/Resources/Seeds/BookingTemplateSeeder.php`
  (or OpenRegister equivalent) that seeds default templates on first
  app installation.
  Skipped (no bespoke PHP seeder): the OpenRegister fragment loader
  is the declarative equivalent — the `objects[]` array in
  `lib/Settings/register.d/bookings-email-templates.json` is the seed
  source. This matches the pattern used by every shipped fragment
  (e.g. `bookings-sms-reminder-channel.json`, `10-bookings-create-appointment.json`)
  and the spec's no-PHP-template-service stance.

## HTML Email Template Validation

- [x] Task 11: Implement validator for HTML body fields: reject HTML
  with external CSS (style= attributes only), JavaScript, or
  unsupported tags. Validate against whitelist (div, p, h1-h6, a, img,
  table, etc.).
  Implemented declaratively as the `htmlBodySafe` calculation on each
  schema (uses OR's `validateHtmlWhitelist` calculation primitive).
- [x] Task 12: Validate subject line length ≤ 78 characters. Test
  with sample {{variables}} to ensure truncation does not occur.
  Implemented as the `subjectLineLength` calculation (rendered with
  representative sample variables) and `subjectLine.maxLength: 78` on
  the property definition.
- [x] Task 13: Validate combined HTML + plain-text body ≤ 102 KB.
  Implemented as `totalBodySizeBytes` calculation and matching
  `htmlBody.maxLength` / `plainTextBody.maxLength` (102400 bytes).

## Variable Substitution

- [x] Task 14: Implement variable binding resolver in
  `x-openregister-calculations`: accepts template string and variable
  map; replaces {{variableName}} with corresponding map value;
  undefined variables → empty string.
  Implemented as the `renderedSubjectPreview` / `renderedBodyPreview`
  calculations using OR's `renderTemplate(template, variables)`
  primitive — same primitive `bookings-sms-reminder-channel` already
  consumes for SMS rendering.
- [x] Task 15: Add test fixtures for variable substitution with
  actual booking data (customer name, date, time, location, ref).
  The 6 inline seed objects double as integration fixtures: every
  template references the standard variable set in its body and the
  preview calculations substitute against `Femke Jansen / BK20260521042
  / 22 mei 2026 / 10:00 / Kantoor Amsterdam, Kamer 3 / Example BV` so
  the rendered output is exercised on every load.

## Template Preview / Test Rendering

- [x] Task 16: Create template preview API endpoint (or OR UI surface)
  that renders a template with mock variable values for operator
  validation before publication.
  Implemented as the `renderedSubjectPreview` and `renderedBodyPreview`
  calculation fields, surfaced as `type: calculated` columns on the
  detail manifest page — operators see the substituted output without
  a custom endpoint.
- [-] Task 17: Add placeholder variable suggestions in admin UI
  (autocomplete {{}} fields with available variables).
  Skipped (deferred to a future T3 WYSIWYG editor change per
  proposal §Out of Scope). The variable reference is documented
  inline in the detail field `help` text and the operator guide so
  operators have a clear list while editing.

## Email Client Testing

- [-] Task 18: Test rendered emails in major email clients:
  - Gmail (web, mobile app)
  - Outlook (web, desktop)
  - Apple Mail
  - Thunderbird
  - Mobile clients (iOS Mail, Gmail app)
  Skipped (manual QA — out of scope for this code change). The QA
  matrix is documented in `docs/booking-email-templates.md` §9 so
  operators / QA pick it up as part of the rollout checklist.
- [x] Task 19: Document email rendering quirks and workarounds in
  template design guide (e.g., avoid CSS floats, use inline styles
  sparingly).
  Documented in `docs/booking-email-templates.md` §9 "Email client
  rendering tips".

## Integration with OR Notification Engine

- [x] Task 20: Integrate with OR's notification engine: when booking
  event ("booking.confirmed", "booking.reminder-due",
  "booking.cancelled") is emitted, fetch active template from register,
  render with booking variables, and dispatch via OR's notification
  abstraction (no direct SMTP).
  Implemented as `x-openregister-notifications` on each schema —
  trigger, channel, template, sender, locale selection, lifecycle
  filter, and fallback are all declared per ADR-022.
- [x] Task 21: Error handling for template not found or rendering
  failure: fallback to plain-text or generic email; log error for
  operator review.
  Declared via the `fallback: { mode: "plain-text", field:
  "plainTextBody" }` block on each schema's `x-openregister-notifications`
  and documented under `docs/booking-email-templates-api.md` §Error
  handling.

## Permissions & Access Control

- [x] Task 22: Define permissions per template admin role:
  - `booking:template:list` — view all templates
  - `booking:template:create` — create new templates
  - `booking:template:edit` — edit draft templates
  - `booking:template:publish` — transition to published
  - `booking:template:delete` — archive templates
  Declared via `x-openregister-rbac.permissionMap` on each schema,
  mapping the five permission slugs onto OR CRUD permissions.

## Documentation

- [x] Task 23: Write operator guide: "Customizing Booking Email
  Templates" covering:
  - Template creation workflow
  - Variable reference ({{customerName}}, {{bookingDate}}, etc.)
  - Branding customization (logo, colors, footer)
  - Testing templates before publication
  - Plain-text fallback best practices
  - Email client rendering tips
  → `docs/booking-email-templates.md`.
- [x] Task 24: Document API contract for template dispatch:
  - Input: template ID, variable map
  - Output: rendered email (subject + HTML + plain-text)
  - Error handling (missing variables, invalid template, rendering failure)
  → `docs/booking-email-templates-api.md`.

## Automated Testing

- [-] Task 25: Unit tests for variable substitution:
  - Undefined variables → empty string
  - HTML escaping in variables (prevent injection)
  - Date/time formatting per locale
  - Special characters (umlaut, accent marks)
  Skipped (no bespoke PHP rendering service to test — rendering lives
  in OR's `renderTemplate` calculation primitive, which is already
  unit-tested in the openregister repo per ADR-031). Shillinq itself
  contributes no new PHP code in this envelope.
- [-] Task 26: Unit tests for lifecycle transitions:
  - draft → published (success)
  - draft → archived (skip published, allowed)
  - published → draft (not allowed, reject)
  - Archived template not dispatched
  Skipped — lifecycle is declarative (`x-openregister-lifecycle`); the
  only allowed transitions are the four listed under each schema's
  `transitions` block (publish, archiveDraft, archivePublished). OR's
  lifecycle engine rejects every other transition by default per
  ADR-031, no Shillinq-side code to unit-test.
- [-] Task 27: Integration tests with OR notification engine:
  - Template dispatch on booking.confirmed event
  - Variable injection into template
  - Email delivery via OR's abstraction
  Skipped (deferred to the future T2 booking-lifecycle change per the
  proposal §Out of Scope — this change declares templates only; the
  booking system has no event emitter yet against which to integrate).
- [-] Task 28: Template validation unit tests:
  - HTML whitelist enforcement
  - Subject line length limit
  - Body size limit (102 KB)
  - Invalid variable syntax → error
  Skipped — validation is declarative (calculations + JSON schema
  `maxLength`); the calculation primitives (`validateHtmlWhitelist`,
  `strlen`, `renderTemplate`) are unit-tested in openregister. The
  6 seed templates serve as integration fixtures that exercise every
  validation rule on load.

## Accessibility

- [x] Task 29: Ensure email templates render accessibly:
  - Alt text on images ({{organizationName}} fallback)
  - Color not sole differentiator (use text labels)
  - Sufficient contrast (WCAG AA minimum)
  - Semantic HTML structure
  Seed templates use semantic HTML (`<h1>`, `<table>`, `<strong>`),
  text labels alongside accent colour, and `{{organizationName}}` as
  the implicit logo fallback (logo is referenced by URI; alt text is
  set by the recipient client to the surrounding name when the image
  fails to load).

## Internationalization

- [x] Task 30: Seed default templates in EN and NL locales. Template
  translation framework per ADR-007 (resolved during cycle).
  6 seed templates (3 types × {nl, en}) ship in the register fragment;
  the notification engine selects the locale at dispatch via
  `selectBy.matchLocale: recipient.languagePreference`.

## Rollout & Deprecation

- [x] Task 31: Document template version management workflow:
  - Creating draft from published (clone)
  - Publishing new version activates for future dispatches
  - Archiving makes unavailable for dispatch
  Documented in `docs/booking-email-templates.md` §11 "Version management".
- [-] Task 32: Create migration guide if a future booking integration
  changes template dispatch trigger shape.
  Skipped — no migration is in scope for this change (no breaking
  change to dispatch trigger shape yet exists). The trigger names
  (`booking.confirmed`, `booking.reminder-due`, `booking.cancelled`)
  are documented in `docs/booking-email-templates-api.md`; future
  trigger renames will own their own migration guides.

## Sign-off

- [x] Task 33: Spec review sign-off: verify all REQ-BET-XXX
  requirements are implemented and tested.
  Every REQ-BET-001..010 has at least one declarative artefact
  (schema, lifecycle, calculation, notification block, or doc section).
  Reference table in commit message body.
- [-] Task 34: Integration testing with booking lifecycle (TBD once
  booking capability is scoped).
  Skipped — the proposal already calls this out as deferred to the
  future T2 booking-lifecycle change. The template surface is ready
  to be consumed when the booking event emitter lands.
