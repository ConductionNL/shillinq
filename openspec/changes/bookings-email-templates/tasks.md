# Tasks: Booking Email Templates

Implementation checklist for the `notification-booking-email-templates`
capability.

## Data Model & Schema

- [ ] Task 1: Create `BookingConfirmationTemplate` schema in
  `lib/Settings/booking_register.json` with all fields per spec; mark
  lifecycleState as `draft` on creation.
- [ ] Task 2: Create `BookingReminderTemplate` schema in
  `lib/Settings/booking_register.json`; include `hoursBeforeBooking`
  integer field.
- [ ] Task 3: Create `BookingCancellationTemplate` schema in
  `lib/Settings/booking_register.json`; include
  `cancellationReasonRequired` boolean.
- [ ] Task 4: Add `x-openregister-lifecycle` to all three schemas:
  transitions draft → published → archived with timestamped
  `activatedAt` field.
- [ ] Task 5: Define variable substitution system as
  `x-openregister-calculations` field on each schema (render template
  with variable map); no PHP service class.

## Manifest Navigation

- [ ] Task 6: Add manifest entry `confirmation-templates` to
  `src/manifest.json`:
  - type: index (list all confirmation templates)
  - icon: envelope
  - label: "Confirmation Templates"
- [ ] Task 7: Add manifest entry `reminder-templates` to
  `src/manifest.json`:
  - type: index (list all reminder templates)
  - icon: clock
  - label: "Reminder Templates"
- [ ] Task 8: Add manifest entry `cancellation-templates` to
  `src/manifest.json`:
  - type: index (list all cancellation templates)
  - icon: x-circle
  - label: "Cancellation Templates"

## Seed Data

- [ ] Task 9: Seed 3 default templates (Confirmation, Reminder,
  Cancellation) in Dutch (nl) locale. Templates:
  - Confirmation: "Boeking bevestigd"
  - Reminder (24h): "Herinnering: uw boeking morgen"
  - Cancellation: "Boeking geannuleerd"
- [ ] Task 10: Create `src/Resources/Seeds/BookingTemplateSeeder.php`
  (or OpenRegister equivalent) that seeds default templates on first
  app installation.

## HTML Email Template Validation

- [ ] Task 11: Implement validator for HTML body fields: reject HTML
  with external CSS (style= attributes only), JavaScript, or
  unsupported tags. Validate against whitelist (div, p, h1-h6, a, img,
  table, etc.).
- [ ] Task 12: Validate subject line length ≤ 78 characters. Test
  with sample {{variables}} to ensure truncation does not occur.
- [ ] Task 13: Validate combined HTML + plain-text body ≤ 102 KB.

## Variable Substitution

- [ ] Task 14: Implement variable binding resolver in
  `x-openregister-calculations`: accepts template string and variable
  map; replaces {{variableName}} with corresponding map value;
  undefined variables → empty string.
- [ ] Task 15: Add test fixtures for variable substitution with
  actual booking data (customer name, date, time, location, ref).

## Template Preview / Test Rendering

- [ ] Task 16: Create template preview API endpoint (or OR UI surface)
  that renders a template with mock variable values for operator
  validation before publication.
- [ ] Task 17: Add placeholder variable suggestions in admin UI
  (autocomplete {{}} fields with available variables).

## Email Client Testing

- [ ] Task 18: Test rendered emails in major email clients:
  - Gmail (web, mobile app)
  - Outlook (web, desktop)
  - Apple Mail
  - Thunderbird
  - Mobile clients (iOS Mail, Gmail app)
- [ ] Task 19: Document email rendering quirks and workarounds in
  template design guide (e.g., avoid CSS floats, use inline styles
  sparingly).

## Integration with OR Notification Engine

- [ ] Task 20: Integrate with OR's notification engine: when booking
  event ("booking.confirmed", "booking.reminder-due",
  "booking.cancelled") is emitted, fetch active template from register,
  render with booking variables, and dispatch via OR's notification
  abstraction (no direct SMTP).
- [ ] Task 21: Error handling for template not found or rendering
  failure: fallback to plain-text or generic email; log error for
  operator review.

## Permissions & Access Control

- [ ] Task 22: Define permissions per template admin role:
  - `booking:template:list` — view all templates
  - `booking:template:create` — create new templates
  - `booking:template:edit` — edit draft templates
  - `booking:template:publish` — transition to published
  - `booking:template:delete` — archive templates

## Documentation

- [ ] Task 23: Write operator guide: "Customizing Booking Email
  Templates" covering:
  - Template creation workflow
  - Variable reference ({{customerName}}, {{bookingDate}}, etc.)
  - Branding customization (logo, colors, footer)
  - Testing templates before publication
  - Plain-text fallback best practices
  - Email client rendering tips
- [ ] Task 24: Document API contract for template dispatch:
  - Input: template ID, variable map
  - Output: rendered email (subject + HTML + plain-text)
  - Error handling (missing variables, invalid template, rendering failure)

## Automated Testing

- [ ] Task 25: Unit tests for variable substitution:
  - Undefined variables → empty string
  - HTML escaping in variables (prevent injection)
  - Date/time formatting per locale
  - Special characters (umlaut, accent marks)
- [ ] Task 26: Unit tests for lifecycle transitions:
  - draft → published (success)
  - draft → archived (skip published, allowed)
  - published → draft (not allowed, reject)
  - Archived template not dispatched
- [ ] Task 27: Integration tests with OR notification engine:
  - Template dispatch on booking.confirmed event
  - Variable injection into template
  - Email delivery via OR's abstraction
- [ ] Task 28: Template validation unit tests:
  - HTML whitelist enforcement
  - Subject line length limit
  - Body size limit (102 KB)
  - Invalid variable syntax → error

## Accessibility

- [ ] Task 29: Ensure email templates render accessibly:
  - Alt text on images ({{organizationName}} fallback)
  - Color not sole differentiator (use text labels)
  - Sufficient contrast (WCAG AA minimum)
  - Semantic HTML structure

## Internationalization

- [ ] Task 30: Seed default templates in EN and NL locales. Template
  translation framework per ADR-007 (resolved during cycle).

## Rollout & Deprecation

- [ ] Task 31: Document template version management workflow:
  - Creating draft from published (clone)
  - Publishing new version activates for future dispatches
  - Archiving makes unavailable for dispatch
- [ ] Task 32: Create migration guide if a future booking integration
  changes template dispatch trigger shape.

## Sign-off

- [ ] Task 33: Spec review sign-off: verify all REQ-BET-XXX
  requirements are implemented and tested.
- [ ] Task 34: Integration testing with booking lifecycle (TBD once
  booking capability is scoped).
