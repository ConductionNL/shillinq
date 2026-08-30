# Proposal: Booking Cancellation Policy

`kind: feature` — implement cancellation policy rules, minimum notice periods, rescheduling windows, and no-show/late-cancellation fees.

## Summary

Introduce the **booking cancellation policy capability** for the Nextcloud Bookings app, enabling operators to define and enforce cancellation policies with business rules around minimum notice periods, cancellation fees, rescheduling windows, and no-show charges. This change extends the existing `Appointment` register (from `bookings-create-appointment`) with cancellation state and policy enforcement, introduces the `CancellationPolicy` register for operator-defined rules, and exposes cancellation operations via:

1. Vue admin interface (policy configuration + appointment cancellation)
2. Customer self-service portal (cancel their own appointments with policy disclosure)
3. REST API (cancel via third-party integrations)

This change conforms to the shared `nextcloud-app` spec for app structure and follows ADR-031 declarative patterns for policy rules.

## Motivation

Competitor analysis (14/21 benchmarks) shows that cancellation policy enforcement is table-stakes:

- **Booksy, Fresha, Mindbody, TheFork, Treatwell** — all implement card-hold protection, late-cancellation fees, and no-show charges
- **Operators need** — revenue protection, no-show deterrence, rescheduling flexibility, customer transparency
- **Customers need** — clear cancellation terms, grace periods, rescheduling windows, fee disclosure at booking time

The Nextcloud Bookings app (`bookings-create-appointment`) currently allows appointment creation but offers no cancellation control, fee structure, or policy enforcement. Operators have no way to:

- Define minimum notice for free cancellations
- Charge fees for late cancellations or no-shows
- Allow rescheduling in defined windows
- Enforce card-hold guarantees for high-value services
- Protect revenue from high-cancellation-rate services

Until cancellation policies are in place, the app cannot serve production use cases where customer commitment and revenue protection matter.

This proposal is **phase 2 of the multi-phase bookings feature set**:

1. `bookings-create-appointment` (phase 1, completed) — core appointment creation
2. `bookings-cancellation-rules` (this change, phase 2) — cancellation policy, minimum notice, fees
3. `bookings-availability-rules` (phase 2, parallel) — availability computation, conflict detection
4. `bookings-reminder-notifications` (phase 3) — email/SMS reminders
5. `bookings-deposit-to-invoice` (phase 3) — invoice generation, payment integration

## Affected Projects

- [x] **Project: nextcloud-bookings** — adds 1 new register (`CancellationPolicy`) to `lib/Settings/bookings_register.json`, extends `Appointment` schema with cancellation fields, adds admin UI in `src/components/CancellationPolicyManager.vue` and `src/components/AppointmentCancel.vue`, adds REST API endpoint `DELETE /ocs/v2.php/apps/bookings/api/v1/appointments/{id}` with policy enforcement, adds test fixtures
- [x] **Project: openregister** — consumes existing OR abstractions (CRUD, validation, relations, lifecycle state machine via ADR-031); no new OR features required

## Scope

### In Scope

- **One new capability spec** (`bookings-cancellation-rules`) defining cancellation policy entities, fee structures, and workflow states
- **CancellationPolicy register schema** — operator-configurable rules with fields: minNoticeDays, rescheduleWindowDays, lateFeeBrackets (percentage/fixed), noShowFee, refundPolicy, cardHoldRequired, policyId, name, description, status
- **Appointment schema extensions** — adds cancellation-related fields: cancelledAt, cancelledReason, cancellationFeeApplied, refundAmount, refundStatus, refundedAt, appliedPolicy (reference to CancellationPolicy)
- **CancellationPolicy type** — service-level or appointment-level policies (e.g., "Yoga Class: 48h notice, 20% late fee after 48h, 100% no-show fee")
- **Admin cancellation interface** — `src/components/AppointmentCancel.vue` showing policy, fee calculation, reason selection, refund confirmation
- **Customer cancellation interface** — self-service cancellation in portal with policy disclosure, fee impact, and refund timing
- **REST API endpoint** — `DELETE /ocs/v2.php/apps/bookings/api/v1/appointments/{id}` with policy enforcement, fee calculation, and refund initiation
- **Cancellation reasons** — enum: customer_request, double_booked, schedule_conflict, payment_issue, no_reason (no-show), other
- **Refund workflow** — refund status tracking (pending, processed, failed) and integration with payment system
- **Audit trail** — all cancellations logged with policy applied, fee calculated, refund initiated

### Out of Scope

- **Payment processing / refunds** — refund initiation is recorded; actual payment reversal is delegated to phase 3 payment integration
- **Deposit / down-payment refund logic** — phase 3 spec `bookings-deposit-to-invoice`
- **Automatic no-show detection** — phase 3 spec (depends on notification + confirmation flow)
- **Custom refund rules per service** — supported by policy linking to services (T2+)
- **Recurring appointment cancellation** — phase 2+ (depends on recurring appointments spec)

## Approach

Three deltas, adding ADDED Requirements to one brand-new spec:

**`bookings-cancellation-rules`** — declares:
1. `CancellationPolicy` register with fee structure, notice periods, rescheduling windows
2. `Appointment` schema extensions (cancelledAt, cancelledReason, appliedPolicy, refundStatus, refundAmount)
3. Cancellation workflow (REQ-BCR-NNN series) with policy enforcement
4. Fee calculation logic (late cancellation, no-show)
5. Refund workflow (pending → processed / failed)
6. Audit trail integration

The spec follows conduction-schema format (RFC 2119, `### REQ-BCR-NNN`, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

**Existing dependencies assumed available**:
- `bookings-create-appointment` — provides `Appointment` register and appointment lifecycle
- `bookings-resource-calendar` — provides `Resource` register
- `bookings-service-catalog` — provides `Service` register

**New OpenRegister abstractions**:
- Lifecycle state machine via ADR-031 `x-openregister-lifecycle` for Appointment cancellation states
- Relation validation for CancellationPolicy → Service linkage

No new external dependencies (payment processors assumed handled in phase 3).

## Impact

- `lib/Settings/bookings_register.json` — adds 1 schema (`CancellationPolicy`); extends `Appointment` schema with 6 new fields; declares relations and lifecycle
- `src/components/CancellationPolicyManager.vue` — new file, admin policy configuration (CRUD)
- `src/components/AppointmentCancel.vue` — new file, appointment cancellation form with policy disclosure and fee calculation
- `src/views/PortalCancellation.vue` — new file, customer self-service cancellation
- `src/api/cancellationApi.js` — new file, REST API client
- `lib/Controller/AppointmentApiController.php` — extends existing controller with DELETE handler for cancellation
- `lib/Service/CancellationService.php` — new file, fee calculation, policy enforcement, refund initiation
- Tests — 15+ unit + integration tests covering policy matching, fee calculation, refund workflow, cancellation reason validation
- `src/manifest.json` — adds 1 new admin section (Cancellation Policies) + cancellation modal action on appointments

## Cross-Project Dependencies

- **OpenRegister** — depends on: register CRUD (existing), audit trail (existing), relation validation (existing), lifecycle state machine via ADR-031 (new usage)

## Risks

### Risk 1: Fee Calculation Under Concurrent Cancellations

**Severity**: Low
**Description**: Two concurrent cancellation requests for the same appointment might both calculate fees from the same initial refund amount, leading to double-charging.
**Mitigation**: Appointment.cancelledAt is checked before fee calculation; if already set, the second request returns a 409 Conflict. Transactional update ensures idempotency.

### Risk 2: Policy Change Does Not Affect Historical Appointments

**Severity**: Low
**Description**: If a cancellation policy is updated, existing appointments with references to that policy should not be affected (historical record).
**Mitigation**: Appointment stores appliedPolicy as a snapshot of the policy state at creation time (schema version + exact fee brackets). Policy updates do not retroactively change historical appointments.

### Risk 3: Refund Amount Precision and Rounding

**Severity**: Low
**Description**: Fee calculation (percentage-based) may result in fractional cents; currency rounding conventions vary by country.
**Mitigation**: All monetary amounts are stored as integers (cents); rounding is done server-side using Dutch banking conventions (round to nearest €0.01). Refund amount is always <= original appointment amount.

### Risk 4: Customer Cancels Too Late (No Refund)

**Severity**: Low
**Description**: Customer cancels after the cancellation deadline; they see zero refund amount and may dispute the fee.
**Mitigation**: Policy is disclosed at booking time and again at cancellation time (clear messaging). Refund amount = appointment cost - (fee % × appointment cost), with refund amount never negative. Audit trail logs all fee calculations for dispute resolution.

## Rollback Strategy

Spec-only change initially. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation:

1. Drop `CancellationPolicy` register from manifest / navigation
2. Mark all appointments as non-cancellable (set appliedPolicy to null)
3. Keep cancellation records queryable (non-destructive)
4. Revert implementation PRs in standard order

No data migration risk at the spec stage.

## Open Questions

1. **Service-level policy linking** — should a CancellationPolicy be linked to a Service (e.g., "Yoga classes have 48h notice") or set globally for all appointments? Decision needed before implementation.
2. **Payment integration timeline** — when should refunds be initiated (immediately on cancellation, or after service date passes)? Depends on phase 3 payment spec.
3. **Refund method** — refund to original payment method (card reversal) or store credit? Product needs to decide.
4. **No-show fee trigger** — is no-show fee automatic (after service start time passes) or manual (operator marks customer as no-show)? Phase 3 decides.
5. **Policy inheritance** — do newly created appointments inherit the current policy, or does each Service have its own policy version? Clarify before implementation.
