# Proposal: bookings-email-templates

`kind: config` — declarative email template registry with brand
customization. Template entities (`BookingConfirmationTemplate`,
`BookingReminderTemplate`, `BookingCancellationTemplate`) +
`x-openregister-lifecycle` for template versioning + manifest
entries for template administration. No PHP template service.

## Summary

Introduce branded email template capability for booking confirmations,
reminders, and cancellations. This change declares three email
template registers; template lifecycle with versioning and draft/
published states; branding customization (logo, colors, footer);
template variables (customer name, booking date/time, booking ref);
template activation for different booking scenarios. Templates
integrate with OR's notification engine for dispatch.

The capability materialises a booking-aware notification system
conforming to ADR-031 (declarative template registry) and ADR-022
(notification-engine abstraction).

## Motivation

Market intelligence research (2026-05-20) confirms all 21 analysed
competitors offer branded email templates for booking confirmations,
reminders, and cancellations. Operators need to customize template
text and branding (logo, colors, footer) without technical
intervention. Template versioning enables operator control of
rollout timing.

This is a standalone capability that prepares the notification
surface for booking lifecycle integration (future T2 capability).

## Affected Projects

- [x] Project: nextcloud-booking — adds 1 capability spec
  (`notification-booking-email-templates`); declares 3 new registers
  (`BookingConfirmationTemplate`, `BookingReminderTemplate`,
  `BookingCancellationTemplate`) with lifecycle and branding;
  adds 3 manifest navigation entries (Confirmation Templates,
  Reminder Templates, Cancellation Templates).
- [ ] Project: openregister — no source changes; consumes existing
  notification engine (ADR-022), `x-openregister-lifecycle`.
- [ ] Project: nextcloud-booking-integration — future change;
  activates templates on booking state transitions.

## Scope

### In Scope

- One new capability spec (`notification-booking-email-templates`)
  — see the `specs/` folder.
- The `BookingConfirmationTemplate` register with template subject,
  HTML body, plain-text fallback, branding fields (logo URI, colors,
  footer text), variable bindings (customer name, booking ref, date,
  time, location).
- The `BookingReminderTemplate` register with timing configuration
  (hours before booking), subject, body, branding, variables.
- The `BookingCancellationTemplate` register with subject, body,
  branding, variables, reason customization.
- Template lifecycle (`draft → published → archived`) enabling
  version management and rollout scheduling.
- Template variable substitution system (customer, booking datetime,
  reference, location) — declared as `x-openregister-calculations`
  field.
- Branding configuration per template (logo URL, accent color,
  footer text, sender name/address).
- Template test/preview feature (render template with sample data).

### Out of Scope

- **Booking integration** — this change declares templates only;
  booking lifecycle integration (triggering template dispatch) lives
  in future T2 booking-lifecycle change.
- **SMS/WhatsApp templates** — email only; multi-channel templates
  roadmap item for T3.
- **Template designer UI** — WYSIWYG editor roads map item; this
  change declares the data model and API surface.
- **Delivery tracking** — bounce handling, delivery status — T3
  feature.
- **A/B testing** — template variant analytics — T3 feature.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`notification-booking-email-templates`** — declares the three
template registers, lifecycle (draft → published → archived),
branding customization, variable substitution system, and integration
with OR's notification engine.

Each requirement is prefixed `REQ-BET-*` for traceability.

## New Dependencies

- Consumes OpenRegister abstractions: `x-openregister-lifecycle`,
  `x-openregister-calculations`, notification engine (ADR-022).
- Requires @conduction/nextcloud-vue@^1.0.0-beta.35 (existing
  bumped version).

## Impact

- `lib/Settings/booking_register.json` — adds 3 new schemas
  (`BookingConfirmationTemplate`, `BookingReminderTemplate`,
  `BookingCancellationTemplate`); declares lifecycle, calculations,
  manifest entries.
- `src/manifest.json` — adds 3 navigation entries (Template admin
  pages).
- No PHP template service (per ADR-031, template rendering is
  declarative).
- No bespoke Vue components (uses standard OR template registry UI).

## Cross-Project Dependencies

- **OpenRegister** — depends on notification engine (ADR-022),
  `x-openregister-lifecycle` (ADR-031), `x-openregister-calculations`
  (ADR-031).
- **Future booking-lifecycle** — depends on this change to declare
  templates; booking state-change actions trigger template dispatch.

## Risks

### Risk 1: Email rendering variation across clients

**Severity**: Medium
**Mitigation**: Email templates use conservative HTML (no complex
CSS). Plain-text fallback always available. Template testing/preview
surface enables operator validation before publication. Email client
testing listed in implementation tasks.

### Risk 2: Template variable binding too rigid for future bookings

**Severity**: Low
**Mitigation**: Variable schema is extensible. This change declares
core variables (customer, booking ref, date, time, location); future
booking entities can extend. Field definitions in register allow
additively.

### Risk 3: Branding customization incomplete without asset management

**Severity**: Low
**Mitigation**: Logo/images stored as URI references; asset hosting
(CDN, document store) external. This change declares the reference
fields; future integration with docudesk/file-store roadmap item.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
templates are non-destructive — rendered instances remain but
unreferenced.

## Open Questions

1. **Default branding values** — organization logo, brand colors
   — resolved during implementing cycle (operator-configured in
   admin settings or read from Corporation metadata).
2. **Reminder template frequency multiplicity** — single cadence vs
   multiple reminder waves — resolved during implementing cycle's
   spec review.
3. **Template preview rendering engine** — resolved in discovery
   against OR's notification engine capabilities.
