# Proposal: bookings-availability-rules

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata. No custom service classes or Vue components.

## Summary

Introduce **resource availability rules** for the Shillinq bookings module,
enabling per-resource configuration of working hours, breaks, holidays,
vacation periods, and booking constraints (advance notice, buffer times,
blackout dates, min/max booking windows).

This change declares three new register schemas: `AvailabilityRule`
(header, per-resource), `ResourceBreak` (recurrence rule for breaks),
and `BookingConstraint` (advance notice, buffer times, cancellation
rules). Per ADR-031, lifecycle rules enforce the balance between
availability and booked slots. Schemas are declared in
`lib/Settings/shillinq_register.json` and wired into `src/manifest.json`
per ADR-024.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure and OpenAPI 3.0 register format.

**Depends on:** `bookings-resource-calendar` change (provides the calendar
context and resource definitions that availability rules apply to).

## Motivation

Shillinq's marketplace and resource-scheduling capabilities require
fine-grained control over when bookings can be made. A hairdresser needs
to block off lunch breaks; a consultant needs to enforce 5-day advance
notice; a service provider needs to define working hours and holidays
to prevent over-booking and customer disappointment.

Competitor evidence (17/21 market leaders surveyed):
- **Cal.com**, **Cogsworth**: Min/max booking window (lead time controls)
- **Cogsworth**, **Cal.com**: Pre- and post-buffer times (prep/cleanup)
- **Easy-Appointments**, **Resy**: Advance-booking limits and cancellation periods
- **Easy-Appointments**, **Salonized**: Per-staff working hours and break definitions
- **Resy**: Per-service prep time (customizable booking parameters)
- **Salonized**: Holiday and vacation management

Until availability rules land, no resource-based booking system can
reliably prevent double-booking or enforce business constraints.

## Affected Projects

- [x] Project: shillinq — adds 3 new registers/schemas
  (`AvailabilityRule`, `ResourceBreak`, `BookingConstraint`) to
  `lib/Settings/shillinq_register.json`, adds navigation entries in
  `src/manifest.json`.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (RBAC, `x-openregister-relations`).

## Scope

### In Scope

- One new capability spec (`bookings-availability-rules`).
- Header entity `AvailabilityRule`: per-resource availability configuration
  with status (`active`/`archived`), effective date range, and resource FK.
- Break definition entity `ResourceBreak`: recurrence rules (day-of-week,
  time-of-day) defining lunch, coffee, or other breaks during the week.
- Constraint entity `BookingConstraint`: advance-notice windows
  (min/max lead time), pre/post buffer times (for setup/cleanup),
  cancellation deadlines, and blackout date ranges.
- Status transitions for rules: `draft → active → archived` with
  effective-date support (rule becomes active on a specific date).
- Blackout date management: spans of dates when no bookings allowed
  (holidays, maintenance, vacation).
- Manifest navigation entry (Bookings > Availability Rules) using
  generic index/detail page renderers.
- RBAC consumed from OpenRegister's audit-trail-immutable abstraction
  per ADR-022.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, and tests are deliberately out of scope;
  the task list references them but they land via `opsx-apply`.
- **Booking engine logic** — owned by sibling `bookings-resource-calendar`.
- **Calendar UI and interactions** — owned by `bookings-resource-calendar`.
- **Multi-language booking labels** — Tier 2 concern; Tier 1 defines the
  schemas only.

## Approach

One delta, adding ADDED requirements to a brand-new spec:

**`bookings-availability-rules`** — declares three schemas:
1. `AvailabilityRule` — header per resource with effective-date window
2. `ResourceBreak` — recurrence-rule entity (day, start time, end time)
3. `BookingConstraint` — advance notice, buffer, blackout, and cancellation rules

The spec follows conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-BAR-*` for traceability (Booking Availability Rules).

## New Dependencies

- **`bookings-resource-calendar`** must land first — `AvailabilityRule`
  foreign-keys into `Resource` (defined by that change).

Otherwise none. This change consumes existing OpenRegister abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 schemas
  (`AvailabilityRule`, `ResourceBreak`, `BookingConstraint`); declares
  lifecycle status transitions.
- `src/manifest.json` — adds navigation entry (Bookings > Availability Rules)
  and index/detail page entries.
- No new PHP services.
- No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-relations` and RBAC
  abstractions being stable per ADR-022.

## Risks

### Risk 1: Effective-date transition logic for rules

**Severity**: Low
**Mitigation**: Rules must support "effective from" dates so administrators
can schedule future changes without manual activation. Whether this is
handled by a simple timestamp field + query-time filtering or by a
lifecycle transition depends on system design. The spec is agnostic;
the `opsx-ff` discovery phase confirms the approach.

### Risk 2: Recurring break patterns lock the schema early

**Severity**: Low
**Mitigation**: Recurrence rules (Monday 12:00–13:00) are rigid. Complex
patterns (first Monday of month, Easter-dependent) are not in Tier 1
scope. Acceptance criterion: a SMB resource scheduler can define standard
working weeks and holidays without workarounds.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. No runtime impact because no implementation lands until
`opsx-apply` is run. After implementation, rollback follows the standard
pattern: revert the PR; registers remain queryable but unused; no data
migration risk at spec stage.

## Open Questions

1. **Effective-date support** — confirmed in `opsx-ff` discovery phase.
2. **Recurring vs. single-occurrence breaks** — Tier 1 uses simple
   day-of-week + time; complex recurrence deferred to future tiers.
