# Spec: Booking Cancellation Rules

**Status:** proposed
**Scope:** nextcloud-bookings
**Tier:** T1 (phase 2)
**Depends on:** `bookings-create-appointment` (approved), `bookings-service-catalog` (approved)

## Preamble

This specification defines the `CancellationPolicy` register and extends the `Appointment` register with cancellation fields, enabling operators to enforce minimum-notice policies, late-cancellation fees, and no-show charges. All requirements use RFC 2119 language (MUST, SHOULD, MAY).

---

## ADDED Requirements

### Requirement: CancellationPolicy Register Definition

The `CancellationPolicy` register MUST be declared in `lib/Settings/bookings_register.json` with the following schema:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| policyId | string | Yes | Unique policy identifier |
| name | string | Yes | Human-readable policy name |
| description | string | No | Policy description (displayed to customers) |
| minNoticeDays | integer | Yes | Minimum days notice for free cancellation (0 = same-day) |
| rescheduleWindowDays | integer | Yes | Days after service start to allow rescheduling |
| lateFeeBrackets | array | Yes | Bracket array: [{daysBeforeStart, feeType, feeAmount}, ...] |
| noShowFee | number | Yes | Fee percentage or fixed amount for no-show (in €, as integer cents) |
| cardHoldRequired | boolean | No | Whether card hold is required (default: false) |
| refundPolicy | enum | Yes | One of: card_reversal, store_credit, none |
| status | enum | Yes | One of: active, archived |
| linkedService | string | No | FK to Service.serviceId (T2+) |

**Schema Type**: OpenRegister-managed; relations: CancellationPolicy → Service (optional, T2)

#### Scenario: Create a Cancellation Policy

- **GIVEN** an operator with admin permissions
- **WHEN** they POST to `/ocs/v2.php/apps/openregister/api/objects/bookings/CancellationPolicy` with valid policy data (all required fields)
- **THEN** the policy is persisted to the register and receives a unique policyId
- **AND** the policy appears in the admin policy list with status "active"
- **AND** audit trail records the creation with actor name and timestamp

#### Scenario: Update a Cancellation Policy

- **GIVEN** an active `CancellationPolicy` with ID "policy-yoga"
- **WHEN** an operator updates `lateFeeBrackets` from 20% to 25%
- **THEN** the policy is updated in the register
- **AND** the change is audit-logged
- **AND** future appointments use the new brackets; historical appointments preserve their original policy snapshot

#### Scenario: List All Policies

- **GIVEN** an operator viewing the admin Cancellation Policies page
- **WHEN** they load the policy list
- **THEN** all active policies are displayed with name, minNoticeDays, and noShowFee visible
- **AND** archived policies are available via a filter

---

### Requirement: Appointment Schema Extensions

The `Appointment` register (from `bookings-create-appointment`) MUST be extended with the following fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| cancelledAt | datetime (ISO 8601 UTC) | No | Timestamp when appointment was cancelled |
| cancelledReason | enum | No | One of: customer_request, double_booked, schedule_conflict, payment_issue, no_show, other |
| appliedPolicy | json or relation | Yes (on create) | Snapshot of the `CancellationPolicy` applied at creation time (policy version + brackets) |
| refundAmount | number | No | Amount eligible for refund (in €, as integer cents); set at cancellation |
| refundStatus | enum | No | One of: pending, processed, failed, cancelled (default: pending on cancellation) |
| refundedAt | datetime (ISO 8601 UTC) | No | Timestamp when refund was completed (phase 3) |

**Schema Type**: Extension of existing `Appointment` register; no breaking changes

#### Scenario: Appoint Receives a Cancellation Policy at Creation

- **GIVEN** a customer creates an appointment for "Yoga Class" service
- **WHEN** the appointment is persisted via POST /appointments
- **THEN** the system looks up the applicable `CancellationPolicy` (global or service-specific, phase 2 = global)
- **AND** the policy is stored in `Appointment.appliedPolicy` as a versioned snapshot (id, brackets, fees at creation time)
- **AND** the customer receives the policy terms in the confirmation email (phase 3)

#### Scenario: Appointment is Cancelled Within Notice Period

- **GIVEN** an `Appointment` with `appliedPolicy` containing `minNoticeDays: 2` and `startTime: 2026-05-28T14:00:00Z`
- **WHEN** the appointment is cancelled on 2026-05-26T10:00:00Z (>2 days before start)
- **THEN** `refundAmount = appointmentCost * (100% - 0%)` (full refund)
- **AND** `refundStatus = pending`
- **AND** `cancelledReason` is set to the reason supplied
- **AND** `cancelledAt = 2026-05-26T10:00:00Z`

#### Scenario: Appointment is Cancelled After Notice Period

- **GIVEN** an `Appointment` with `appliedPolicy` containing brackets `[{daysBeforeStart: 2, fee: 0%}, {daysBeforeStart: 0, fee: 20%}]` and `startTime: 2026-05-28T14:00:00Z` and `appointmentCost: €100`
- **WHEN** the appointment is cancelled on 2026-05-27T10:00:00Z (~28 hours before start)
- **THEN** the matching bracket is `{daysBeforeStart: 0, fee: 20%}` (closest bracket with daysBeforeStart <= 1)
- **AND** `refundAmount = €100 - (20% × €100) = €80`
- **AND** `refundStatus = pending`
- **AND** `cancelledAt = 2026-05-27T10:00:00Z`

---

### Requirement: Cancellation Service Layer

A new `CancellationService.php` MUST be created with the following public methods:

- `calculateRefund(Appointment $appointment): int` — returns refund amount in cents given the appointment and its appliedPolicy
- `validateCancellation(Appointment $appointment): ValidationResult` — checks whether cancellation is allowed (not already cancelled, etc.)
- `initiateCancellation(Appointment $appointment, string $reason): Appointment` — updates appointment with cancelledAt, cancelledReason, refundAmount, refundStatus; logs audit trail

#### Scenario: Fee Calculation with Fixed-Amount Bracket

- **GIVEN** a `CancellationPolicy` with bracket `{daysBeforeStart: 0, feeType: "fixed", feeAmount: 5000}` (€50)
- **WHEN** `calculateRefund()` is called with an €100 appointment cancelled 6 hours before start
- **THEN** the method returns €5000 (€50 refund; €50 fee deducted)

#### Scenario: No Refund for Too-Late Cancellation

- **GIVEN** a `CancellationPolicy` with bracket `{daysBeforeStart: 0, feeType: "percentage", feeAmount: 100}` (100% fee)
- **WHEN** `calculateRefund()` is called for an appointment cancelled 1 hour before start
- **THEN** the method returns €0 (no refund; full amount forfeited)

#### Scenario: Validate Cancellation Prevents Double-Cancellation

- **GIVEN** an `Appointment` with `cancelledAt` already set (previously cancelled)
- **WHEN** `validateCancellation()` is called
- **THEN** validation fails with error "Appointment already cancelled"
- **AND** the cancellation request returns HTTP 409 Conflict

---

### Requirement: Admin Cancellation Interface

An admin Vue component `src/components/AppointmentCancel.vue` MUST be created with the following features:

1. **Appointment Display** — shows appointment details (customer, service, start time, appointment cost)
2. **Policy Display** — shows the applied policy (minNoticeDays, lateFeeBrackets, noShowFee) in plain language
3. **Fee Calculation** — displays calculated refund amount and fee breakdown (e.g., "€80 refund | €20 fee (20%)")
4. **Reason Selection** — dropdown with cancellation reasons: customer_request, double_booked, schedule_conflict, payment_issue, no_show, other
5. **Confirmation** — "Confirm Cancellation" and "Cancel" buttons; confirmation modal with final fee display
6. **Audit Trail** — after cancellation, shows "Cancelled by [admin name] at [time]" and logs to audit trail

#### Scenario: Admin Cancels an Appointment

- **GIVEN** an admin on the Appointments list viewing a confirmed appointment
- **WHEN** they click "Cancel Appointment" on an appointment scheduled 12 hours away
- **THEN** a modal opens showing: customer name, service, start time, cost, applied policy (48h notice, 20% late fee), and calculated refund (€80)
- **AND** they select reason "double_booked" and click "Confirm Cancellation"
- **THEN** the appointment is updated with `cancelledAt`, `cancelledReason`, `refundAmount`, `refundStatus = pending`
- **AND** the list updates to show the appointment as "Cancelled"
- **AND** an audit log entry is created

---

### Requirement: Customer Self-Service Cancellation

A customer view `src/views/PortalCancellation.vue` MUST be created with:

1. **Upcoming Appointments List** — shows customer's future appointments with service, date/time, cost
2. **Cancel Action** — click-to-cancel button on each appointment
3. **Policy Disclosure** — modal shows policy terms, calculated refund, and cancellation deadline
4. **Confirmation** — customer confirms cancellation; modal asks reason (customer_request, schedule_conflict, other)
5. **Success Message** — "Appointment cancelled. Refund: €80 (pending). You will receive confirmation via email."
6. **Protection Against Late Cancellation** — if cancellation is too late (within minNoticeDays), modal shows a warning: "This cancellation is within our X-hour cancellation deadline. A €50 cancellation fee will apply."

#### Scenario: Customer Cancels with Full Refund

- **GIVEN** a customer on the self-service portal viewing their appointment (Yoga Class, 5 days away, €100)
- **WHEN** they click "Cancel" on the appointment
- **THEN** a modal appears: "Cancel Yoga Class? Policy: 48-hour cancellation notice. Refund: €100 (full refund)"
- **AND** they select reason "schedule_conflict" and click "Confirm"
- **THEN** the appointment status changes to "Cancelled"
- **AND** a success message appears: "Appointment cancelled. Refund: €100 (pending). Confirmation email sent to [customer email]."

#### Scenario: Customer Cancels Too Late (Late Fee Applied)

- **GIVEN** a customer with an appointment 12 hours away (Policy: 48h notice, 20% late fee, €100 cost)
- **WHEN** they cancel
- **THEN** the modal shows: "⚠️ This cancellation is within 48 hours. A €20 late-cancellation fee will apply. Refund: €80"
- **AND** upon confirmation, the appointment is marked as "Cancelled" with refund €80 (pending)

---

### Requirement: REST API Cancellation Endpoint

The `AppointmentApiController.php` MUST be extended with a DELETE handler:

**Endpoint**: `DELETE /ocs/v2.php/apps/bookings/api/v1/appointments/{appointmentId}`

**Request Body** (optional):
```json
{
  "reason": "customer_request",
  "notes": "Had to reschedule due to work conflict"
}
```

**Response (200 OK)**:
```json
{
  "appointmentId": "apt-12345",
  "status": "cancelled",
  "cancelledAt": "2026-05-27T10:00:00Z",
  "refundAmount": 8000,
  "refundStatus": "pending",
  "refundedAt": null
}
```

**Response (409 Conflict)** — if already cancelled:
```json
{
  "error": "Appointment already cancelled",
  "cancelledAt": "2026-05-26T14:30:00Z"
}
```

#### Scenario: Cancel via REST API

- **GIVEN** an authenticated client with permission to cancel appointments
- **WHEN** they send `DELETE /ocs/v2.php/apps/bookings/api/v1/appointments/apt-12345` with reason "customer_request"
- **THEN** the appointment is cancelled and a 200 response with full cancellation details is returned
- **AND** audit trail records the API client and cancellation details

---

### Requirement: Refund Status Tracking

The `Appointment` MUST track refund lifecycle via `refundStatus`:

- `pending` — cancellation initiated; refund not yet processed (set immediately on cancellation)
- `processed` — refund completed; amount has been returned (set by phase 3 payment flow)
- `failed` — refund attempt failed; customer must contact support (set by phase 3 payment flow on error)
- `cancelled` — refund was cancelled (reserved for edge cases where customer re-requests cancellation)

#### Scenario: Refund Status Transitions

- **GIVEN** an appointment cancelled with `refundStatus = pending`
- **WHEN** phase 3 payment integration processes the refund
- **THEN** `refundStatus = processed` and `refundedAt` is set
- **AND** the customer receives a "Refund Processed" email
- **OR IF** the refund fails (card declined, account closed, etc.)
- **THEN** `refundStatus = failed` and an email is sent: "Refund Failed — Please contact support"

---

### Requirement: Audit Trail Integration

Every cancellation MUST log an audit trail entry via OpenRegister's audit mechanism:

- **Actor**: The user/system that initiated the cancellation (admin name or "customer [email]")
- **Action**: "cancelled"
- **Before**: Full `Appointment` object before cancellation
- **After**: Full `Appointment` object after cancellation (with cancelledAt, cancelledReason, refundAmount set)
- **Timestamp**: ISO 8601 UTC
- **Reason**: (if supplied)

#### Scenario: Audit Trail for Admin Cancellation

- **GIVEN** admin "John Doe" cancels appointment "apt-12345" with reason "double_booked"
- **WHEN** the cancellation is persisted
- **THEN** audit trail entry is created:
  - Actor: "John Doe"
  - Action: "cancelled"
  - Reason: "double_booked"
  - Before: Full appointment (not cancelled)
  - After: Full appointment (cancelled, refundAmount=€80, refundStatus=pending)
- **AND** the entry is queryable via `/ocs/v2.php/apps/openregister/api/auditTrail?objectId=apt-12345`

---

### Requirement: Cancellation Cannot be Undone (Immutability)

Once `Appointment.cancelledAt` is set, the appointment MUST be considered immutable with respect to cancellation:

- **MUST NOT** allow a second cancellation (return 409 Conflict)
- **MUST NOT** allow uncancellation (no "restore" endpoint in phase 2)
- **MAY** (phase 2+) allow rescheduling of a cancelled appointment into a new time slot (separate spec)

#### Scenario: Prevent Double-Cancellation

- **GIVEN** an appointment already cancelled (cancelledAt = "2026-05-26T14:00:00Z")
- **WHEN** someone attempts to cancel it again
- **THEN** the request returns 409 Conflict: "Appointment already cancelled at 2026-05-26T14:00:00Z"
- **AND** no second cancellation is recorded

---

### Requirement: Manifest and Navigation

The `src/manifest.json` MUST be updated with:

1. **Cancellation Policies Admin Section** — new navigation item under "Settings": "Cancellation Policies" → `/apps/bookings/admin/cancellation-policies`
2. **Appointment List Action** — add "Cancel" button to appointment rows in the admin dashboard
3. **Customer Portal Link** — if not already present, add customer-accessible "My Bookings" → includes "Cancel" option

#### Scenario: Admin Navigates to Cancellation Policies

- **GIVEN** an admin logged into Nextcloud Bookings
- **WHEN** they click Bookings > Settings > Cancellation Policies
- **THEN** the Cancellation Policy Manager opens, showing all active policies
- **AND** they can create, edit, or archive policies

---

### Requirement: i18n Strings

English (`src/locales/en_US.json`) and Dutch (`src/locales/nl_NL.json`) translations MUST include:

**English:**
```json
{
  "Cancellation": "Cancellation",
  "Cancellation Policy": "Cancellation Policy",
  "Cancellation Fee": "Cancellation Fee",
  "Late Cancellation": "Late Cancellation",
  "No-Show Fee": "No-Show Fee",
  "Minimum Notice": "Minimum Notice",
  "Refund Amount": "Refund Amount",
  "Refund Status": "Refund Status",
  "Cancellation Reason": "Cancellation Reason",
  "Customer Request": "Customer Request",
  "Double Booked": "Double Booked",
  "Schedule Conflict": "Schedule Conflict",
  "Payment Issue": "Payment Issue",
  "No Show": "No Show",
  "Pending": "Pending",
  "Processed": "Processed",
  "Failed": "Failed"
}
```

**Dutch:**
```json
{
  "Cancellation": "Annulering",
  "Cancellation Policy": "Annuleringsbeleid",
  "Cancellation Fee": "Annuleringskosten",
  "Late Cancellation": "Laat Annulering",
  "No-Show Fee": "No-Show Tarief",
  "Minimum Notice": "Minimale Opzegtermijn",
  "Refund Amount": "Restitutie",
  "Refund Status": "Restitutiestatus",
  "Cancellation Reason": "Reden voor Annulering",
  "Customer Request": "Klantverzoek",
  "Double Booked": "Dubbel Geboekt",
  "Schedule Conflict": "Conflicterend Schema",
  "Payment Issue": "Betalingsprobleem",
  "No Show": "No-Show",
  "Pending": "In Behandeling",
  "Processed": "Verwerkt",
  "Failed": "Mislukt"
}
```

#### Scenario: All cancellation labels are translated in both locales

- **GIVEN** the app ships `l10n/en.json` and `l10n/nl.json`
- **WHEN** the cancellation capability is loaded in either an English or a Dutch session
- **THEN** every cancellation, fee, refund, reason and status label above MUST resolve to a non-empty translation in the active locale
- **AND** no cancellation label MAY fall back to the untranslated source string

---

## Conformance

This spec conforms to:
- **ADR-031** (Declarative schema) — CancellationPolicy is register-driven with no custom PHP mappers
- **ADR-024** (App manifest) — manifest.json carries all navigation + action definitions
- **RFC 2119** (Requirement language) — MUST/SHOULD/MAY language used throughout

Verification: `openspec validate` exits 0; architecture review confirms no custom schema mappers beyond `CancellationService.php` for business logic.
