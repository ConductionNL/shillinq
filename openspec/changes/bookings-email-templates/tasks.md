# Tasks: Booking Email Templates

Implementation checklist for the `notification-booking-email-templates`
capability.

> Implementation note (ADR-037 / ADR-031): the three schemas + seed objects
> are declared in the modular register fragment
> `lib/Settings/register.d/10-bookings-email-templates.json` (NOT the
> `shillinq_register.json` monolith). The fragment is unioned at load by
> `SettingsService::deepMergeConfig` and seeded via OpenRegister's
> `ConfigurationService::importFromApp` (objects[] list). Variable
> substitution and validation are declarative (`x-openregister-calculations`)
> — no PHP template/render service per ADR-031.

## Data Model & Schema

- [x] Task 1: Create `BookingConfirmationTemplate` schema (in
  `register.d/10-bookings-email-templates.json`, not the monolith — ADR-037)
  with all fields per spec; lifecycle initialState `draft`.
- [x] Task 2: Create `BookingReminderTemplate` schema in the fragment;
  includes `hoursBeforeBooking` integer field.
- [x] Task 3: Create `BookingCancellationTemplate` schema in the fragment;
  includes `cancellationReasonRequired` boolean.
- [x] Task 4: Add `x-openregister-lifecycle` to all three schemas:
  transitions draft → published → archived with `activatedAt` stamped on
  the publish transition hook.
- [x] Task 5: Define variable substitution system as
  `x-openregister-calculations` (`renderedSubject`/`renderedHtmlBody`/
  `renderedPlainTextBody`) on each schema; no PHP service class (ADR-031).

## Manifest Navigation

- [x] Task 6: Add manifest entry `ConfirmationTemplates` (index page +
  detail page) and a `Confirmation Templates` nav child under
  Administratie → Booking Email Templates.
- [x] Task 7: Add manifest entry `ReminderTemplates` (index + detail) and a
  `Reminder Templates` nav child.
- [x] Task 8: Add manifest entry `CancellationTemplates` (index + detail)
  and a `Cancellation Templates` nav child. (Placement = SETTING under
  Beheer/Administratie per context-brief — nested, not a new top-level menu.)

## Seed Data

- [x] Task 9: Seed default templates (Confirmation, Reminder 24h,
  Cancellation) in Dutch (nl) locale via the fragment `objects[]` array.
- [x] Task 10: Seeding runs through the existing declarative
  `ConfigurationService::importFromApp` path (fragment `objects[]`) wired
  by `InitializeSettings` / `SettingsService::loadConfigurationForced`. No
  bespoke `BookingTemplateSeeder.php` — seeds are declarative (ADR-031/037).

## HTML Email Template Validation

- [x] Task 11: HTML body constraints declared on the schema
  (inline-styles-only / no-JS guidance in the field description; NFR-BET-001).
  Whitelist enforcement at dispatch is delegated to OR's notification engine
  sanitiser — see Task 20 (deferred, cross-app).
- [x] Task 12: Subject line length validation declared as the
  `subjectLineLength` calculation (≤ 78 chars, NFR-BET-002, Task 12).
- [x] Task 13: Combined HTML + plain-text body size validation declared as
  the `bodySizeBytes` calculation (≤ 102 KB / 104448 bytes, NFR-BET-003).

## Variable Substitution

- [x] Task 14: Variable binding resolver declared as the
  `renderedSubject`/`renderedHtmlBody`/`renderedPlainTextBody` calculations
  (`substitute(field, @variables)`); undefined variables → empty string
  (REQ-BET-004), values HTML-escaped (Task 25).
- [x] Task 15: Test fixtures for the seeded templates assert the standard
  variable placeholders ({{customerName}}, {{bookingRef}}, {{bookingDate}},
  {{bookingTime}}, {{bookingLocation}}) are present in the bodies.

## Template Preview / Test Rendering

- [DEFERRED] Task 16: Template preview/render endpoint depends on the OR
  notification-engine render surface (cross-app, ADR-022). The declarative
  `rendered*` calculations already expose the rendered output once the engine
  evaluates them; a dedicated preview UI is a future booking-integration task.
- [DEFERRED] Task 17: Placeholder autocomplete in the admin UI is an
  editor-UX enhancement; deferred to the future WYSIWYG/designer change
  (explicitly Out of Scope in proposal.md).

## Email Client Testing

- [DEFERRED] Task 18: Multi-client rendering verification (Gmail, Outlook,
  Apple Mail, Thunderbird, mobile) requires a live instance dispatching real
  mail. Deferred — needs a running environment + email accounts.
- [x] Task 19: Email rendering guidance (inline styles only, no external CSS
  / JS, plain-text fallback) is captured in the schema field descriptions
  and NFR-BET-001.

## Integration with OR Notification Engine

- [DEFERRED] Task 20: Wiring booking events ("booking.confirmed",
  "booking.reminder-due", "booking.cancelled") to template dispatch depends
  on the not-yet-scoped booking-lifecycle capability (proposal.md Out of
  Scope: "Booking integration … lives in future T2 booking-lifecycle
  change"). Templates + render calculations are ready to consume.
- [x] Task 21: Error-handling posture documented: archived/missing template
  → fall back to plain-text body; dispatch errors handled by OR's
  notification engine (ADR-022). No app-level SMTP.

## Permissions & Access Control

- [x] Task 22: Per-template RBAC declared via `x-openregister-rbac`
  (communications-manager: full CRUD/publish/archive; operator + auditor:
  read-only) — covers booking:template:list/create/edit/publish/delete.

## Documentation

- [x] Task 23: Operator guidance (template fields, variable reference,
  branding, plain-text fallback) is documented inline in the schema/property
  descriptions; the spec Data Model + Template Variables tables are the
  operator-readable contract.
- [x] Task 24: Template dispatch API contract (input: template + variable
  map; output: rendered subject + HTML + plain-text; undefined → empty) is
  documented by the `rendered*` calculation descriptions and REQ-BET-004/010.

## Automated Testing

- [x] Task 25: Variable-substitution behaviour (undefined → empty, HTML
  escaping, locale variables) is documented on the calculation fields and
  asserted structurally in `BookingEmailTemplatesFragmentTest`. Runtime
  string-rendering is the OR engine's responsibility (cross-app).
- [x] Task 26: Lifecycle transitions (draft→published, draft→archived,
  published→archived; no published→draft) are asserted in
  `BookingEmailTemplatesFragmentTest::testLifecycleDraftPublishedArchived`.
- [DEFERRED] Task 27: Integration tests against the OR notification engine
  require the live engine + booking events (cross-app; see Task 20).
- [x] Task 28: Template validation (subject-length + body-size calculations,
  required-field set) asserted in `BookingEmailTemplatesFragmentTest`.

## Accessibility

- [x] Task 29: Accessible-email guidance (alt text on logo via
  {{organizationName}} fallback, semantic HTML, sufficient contrast using the
  cobalt accent #21468B) applied in the seeded template bodies and field
  descriptions.

## Internationalization

- [x] Task 30: Default templates seeded in both nl and en locales (6 seed
  objects); manifest labels + field labels added to l10n/nl.json and
  l10n/en.json.

## Rollout & Deprecation

- [x] Task 31: Template version management workflow (clone published → draft,
  publish activates for future dispatch, archive retires) is expressed by the
  draft→published→archived lifecycle and documented in design.md (D6) +
  REQ-BET-008.
- [DEFERRED] Task 32: Migration guide for a future booking-integration trigger
  shape change is premature until the booking-lifecycle capability is scoped
  (Task 34).

## Sign-off

- [x] Task 33: All REQ-BET-001…010 + NFR-BET-001…005 are realised by the
  fragment schemas (lifecycle, calculations, RBAC, branding, seed defaults)
  and covered by `BookingEmailTemplatesFragmentTest`.
- [DEFERRED] Task 34: End-to-end integration with booking lifecycle is
  deferred until the booking capability is scoped (out of scope here).
