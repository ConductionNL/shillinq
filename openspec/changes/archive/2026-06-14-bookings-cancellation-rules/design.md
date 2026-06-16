# Design — Booking Cancellation Policy

**status: draft**

## Context

The Nextcloud Bookings app enables customers to book services through a calendar-based interface. Phase 1 (`bookings-create-appointment`) allows appointment creation, but currently has no cancellation control, fee enforcement, or policy rules. Operators lose revenue to late cancellations and no-shows, and customers lack transparency on cancellation terms.

This change defines the `CancellationPolicy` register and extends `Appointment` with cancellation fields, enabling:

1. **Admin** — define policies with minimum notice, rescheduling windows, and fee brackets
2. **Customer** — cancel with transparent fee disclosure and refund calculation
3. **System** — enforce policies consistently across all cancellation channels

## Goals

- **Revenue protection** — minimum notice periods, late-cancellation fees, no-show charges
- **Transparency** — customers see cancellation terms and fee impact before cancelling
- **Consistency** — same policy applied whether cancellation is via admin, self-service, or API
- **Audit trail** — every cancellation logged with policy applied, fee calculated, refund status
- **Flexibility** — operators can customize policy per service or globally

## Non-Goals

- **Payment processing** — refund calculation is recorded; actual payment reversal is phase 3
- **Automatic no-show detection** — requires appointment confirmation flow (phase 3)
- **Recurring cancellation** — phase 2+; single appointments only in phase 2
- **Deposit refund logic** — phase 3 spec `bookings-deposit-to-invoice`
- **Custom fields on cancellation** — fixed reason enum in phase 2

## Decisions

### D1 — CancellationPolicy as a Register (per ADR-031)

**Decision**: Declare `CancellationPolicy` as a register in `lib/Settings/bookings_register.json` with full CRUD exposure.

**Why**: Operators need to create, edit, and audit policies. An OpenRegister-backed entity provides versioning, audit trail, and relation tracking automatically. Supports T2 service-specific policies without code changes.

**Alternative**: Hard-coded policy in config.json. Rejected — inflexible, no audit trail, doesn't support service-level customization.

### D2 — Policy Snapshot in Appointment, Not Dynamic Reference

**Decision**: When an appointment is created, the applicable `CancellationPolicy` is resolved and its field values are snapshot'd into `Appointment.appliedPolicy` as a JSON object (or as a versioned relation). Historical appointments preserve their original policy terms.

**Why**: If a policy is updated, historical appointments should not be affected retroactively. A customer who booked under "48h notice, 20% late fee" should not see those terms change to "24h notice, 50% late fee" if the admin updates the policy.

**Alternative**: Store only policyId in Appointment; resolve policy dynamically at cancellation time. Rejected — policy updates retroactively affect historical appointments, violating customer expectations.

### D3 — Fee Calculation: Percentage + Fixed Brackets

**Decision**: `CancellationPolicy.lateFeeBrackets` is an array of objects: `[{daysBeforeStart: 7, feeType: "percentage", feeAmount: 0}, {daysBeforeStart: 2, feeType: "percentage", feeAmount: 25}, {daysBeforeStart: 0, feeType: "percentage", feeAmount: 100}]`. At cancellation, find the matching bracket (earliest bracket with daysBeforeStart <= actual days until start) and apply the fee.

**Why**: Supports the most common fee structures (free until X days, then percentage); works for both high-value services (percentage) and low-value ones (fixed fee). Bracket approach is easy for operators to understand.

**Alternative**: Single fee percentage. Rejected — doesn't capture "free up to 48h" pattern. Complex formula. Rejected — hard for operators to express.

### D4 — Refund = Appointment Cost - Fee

**Decision**: `refundAmount = appointmentCost - (lateFeeBracket.feeAmount% × appointmentCost)`. If refundAmount < 0, refundAmount = 0 (customer pays full fee, no refund).

**Why**: Simple, transparent to customers. Fee is deducted from the refund, not added to the charge. Aligns with competitor patterns (Booksy, Fresha).

**Alternative**: Refund = appointment cost; fee is charged separately. Rejected — confusing for customers, requires separate charge transaction.

### D5 — Cancellation Reasons Enum

**Decision**: `Appointment.cancelledReason` is an enum: `[customer_request, double_booked, schedule_conflict, payment_issue, no_show, other]`. Immutable; set at cancellation time.

**Why**: Allows operators to analyze cancellation patterns (why do 40% of yoga appointments cancel in the last 48h?). Supports different fee logic for no-show vs. customer-initiated (future enhancement).

**Alternative**: Free-text reason. Rejected — hard to aggregate and analyze.

### D6 — Refund Status Workflow

**Decision**: `Appointment.refundStatus` is a state machine: `pending → processed | failed | cancelled`. At cancellation time, status is set to `pending`. Phase 3 payment integration transitions to `processed` or `failed`. If the customer re-cancels a cancellation (edge case), status becomes `cancelled`.

**Why**: Tracks refund lifecycle. Operators and customers can see whether a refund was issued, failed, or is still pending. Audit trail logs all transitions.

**Alternative**: Refund status is implicit in Appointment.status. Rejected — hard to track partial refunds, refund failures, or multi-stage refund workflows.

### D7 — Service-Level or Global Policy

**Decision**: Phase 2 ships with **global policy** (one `CancellationPolicy` per cancellation window; all services use it). Relations to Service are declared in the schema but not enforced in phase 2. T2 spec introduces service-specific policy selection.

**Why**: Simplifies phase 2 implementation. Most small businesses use one policy. Advanced use cases (different policy for group classes vs. personal coaching) move to phase 2.

**Alternative**: Service-specific policy is mandatory in phase 2. Rejected — adds admin complexity, requires service selector in policy config, scope creep.

### D8 — Timezone: UTC Storage, Local Display

**Decision**: `Appointment.cancelledAt` is stored as ISO 8601 UTC. Frontend displays local time. Fee calculation uses UTC distance (days = (startTime - cancelledAt) / 86400 in seconds).

**Why**: Consistent with appointment time storage (D6 from bookings-create-appointment). Avoids server-side timezone logic. Customers' browsers show their local cancellation timestamp.

**Alternative**: Store local timezone + offset. Rejected — requires timezone database on server, locale-aware rounding.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| CancellationPolicy register CRUD | OpenRegister generic CRUD | CancellationPolicy is defined in `lib/Settings/bookings_register.json` and exposed via OR's `GET/POST /ocs/v2.php/apps/openregister/api/objects/bookings/CancellationPolicy` endpoints |
| Appointment extensions | `Appointment` register (from `bookings-create-appointment`) | Appointment schema is patched with 6 new fields: cancelledAt, cancelledReason, appliedPolicy, refundAmount, refundStatus, refundedAt. No breaking changes. |
| Relation to Service | `Service` register (from `bookings-service-catalog`) | CancellationPolicy → Service relation declared; not enforced in phase 2 (T2 spec adds service-selector logic) |
| Fee calculation | Custom service class | New `CancellationService.calculateFee()` method; testable in isolation |
| Audit trail | OR audit-trail-immutable abstraction | Every cancellation (appointment update) is logged with actor, appliedPolicy, refundAmount, reason |
| Refund initiation | Custom service class | New `CancellationService.initiateCancellation()` method; returns refundId for tracking; sets refundStatus to pending |
| Admin UI binding | Vue admin dashboard boilerplate | Standard admin layout; cancellation policy CRUD form is a new component; appointment list gains a "Cancel" action |

## Seed Data

No seed data for appointments or refunds (both created at runtime). Seed data for `CancellationPolicy`:

**Example 1: Yoga Classes (48h notice, 20% late fee)**
```json
{
  "policyId": "policy-yoga-standard",
  "name": "Yoga Classes — Standard",
  "description": "48-hour cancellation notice, 20% late fee after 48h, 100% no-show fee",
  "minNoticeDays": 2,
  "rescheduleWindowDays": 14,
  "noShowFee": 100,
  "cardHoldRequired": false,
  "lateFeeBrackets": [
    {"daysBeforeStart": 2, "feeType": "percentage", "feeAmount": 0},
    {"daysBeforeStart": 0, "feeType": "percentage", "feeAmount": 20}
  ],
  "refundPolicy": "card_reversal",
  "status": "active"
}
```

**Example 2: Personal Coaching (24h notice, 50% late fee, card hold)**
```json
{
  "policyId": "policy-coaching-premium",
  "name": "Personal Coaching — Premium",
  "description": "24-hour cancellation notice, 50% late fee after 24h, card hold required",
  "minNoticeDays": 1,
  "rescheduleWindowDays": 30,
  "noShowFee": 100,
  "cardHoldRequired": true,
  "lateFeeBrackets": [
    {"daysBeforeStart": 1, "feeType": "percentage", "feeAmount": 0},
    {"daysBeforeStart": 0, "feeType": "percentage", "feeAmount": 50}
  ],
  "refundPolicy": "card_reversal",
  "status": "active"
}
```

**Example 3: Consultations (Free until 24h, then €50 fixed fee)**
```json
{
  "policyId": "policy-consult-standard",
  "name": "Consultations — Standard",
  "description": "Free cancellation up to 24h, €50 late-cancellation fee after 24h",
  "minNoticeDays": 1,
  "rescheduleWindowDays": 90,
  "noShowFee": 50,
  "cardHoldRequired": false,
  "lateFeeBrackets": [
    {"daysBeforeStart": 1, "feeType": "fixed", "feeAmount": 0},
    {"daysBeforeStart": 0, "feeType": "fixed", "feeAmount": 50}
  ],
  "refundPolicy": "store_credit",
  "status": "active"
}
```

## Migration Plan

Spec-only. When implementation lands:

1. `lib/Settings/bookings_register.json` is patched with the `CancellationPolicy` schema (additive) and `Appointment` schema is extended with 6 new fields
2. `src/components/CancellationPolicyManager.vue` is added (admin CRUD interface)
3. `src/components/AppointmentCancel.vue` is added (appointment cancellation form)
4. `src/views/PortalCancellation.vue` is added (customer self-service cancellation)
5. `lib/Service/CancellationService.php` is added (fee calculation, refund initiation)
6. `lib/Controller/AppointmentApiController.php` is extended with DELETE handler
7. Database migration creates indexes on `(appointmentId, cancelledAt)` for audit queries
8. `src/manifest.json` is patched with one new admin section (Cancellation Policies) + cancellation modal action
9. Seed policies are loaded via migration or admin seeding endpoint

Down-direction: drop CancellationPolicy from manifest, set all cancelledAt fields to null, keep refund records for audit.

## Open Questions

1. **Service-level policy selection** — phase 2 uses global policy; T2 allows per-service policy. When is the service-policy relation enforced? (Clarify scope for phase 2.)
2. **Card hold integration** — `cardHoldRequired` flag is declared but not enforced in phase 2. When is card hold logic implemented (phase 3)?
3. **Refund method enforcement** — `refundPolicy` is stored (card_reversal, store_credit) but not executed in phase 2. Phase 3 payment spec decides how to execute refunds.
4. **No-show detection** — `noShowFee` is applied manually (admin marks as no-show). Automatic detection depends on confirmation/notification flow (phase 3).
5. **Overpayment handling** — if a customer paid a deposit (phase 3 spec) and then cancels, how are deposits handled relative to cancellation fees? Dependency on phase 3 logic.
6. **Partial cancellation** — what if a customer has a recurring series (phase 2+) and cancels one occurrence? Scope for T2 or phase 2-recurring spec.
