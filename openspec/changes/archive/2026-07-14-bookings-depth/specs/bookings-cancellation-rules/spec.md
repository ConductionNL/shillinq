# Spec: bookings-cancellation-rules (delta — bookings-depth)

**Status:** in-progress
**Scope:** shillinq
**Kind:** feature (no-show-fee-capture leg of bookings-depth)

This delta realises the **capture** of the `CancellationPolicy.noShowFee` field that the
archived `bookings-cancellation-rules` change **defined** but left unenforceable (the fee
had no code path that charged it). It adds the no-show-fee capture behaviour and its
Appointment bookkeeping fields. The `CancellationPolicy` / refund requirements owned by
the archived change are NOT modified.

## MODIFIED Requirements

### Requirement: No-show fee capture through the payment-provider rails

The system SHALL provide `OCA\Shillinq\Service\NoShowFeeCaptureService` (ADR-022
consumer, invoked from the ADR-003 `BookingDepthController`) that, when an appointment is
recorded as a no-show, computes and captures the defined no-show fee.

The fee SHALL be `round(appointmentCost × noShowFee / 100)` in integer cents, where
`noShowFee` is the 0-100 percentage snapshotted onto `Appointment.appliedPolicy`. The
percentage SHALL be clamped to `[0,100]` and the fee clamped to `appointmentCost`.

When the computed fee is 0 (no cost, no snapshotted policy, or `noShowFee ≤ 0`) the
service SHALL dispatch **no** provider call and SHALL stamp `noShowFeeStatus = none`.

When the fee is positive the service SHALL capture it through
`DepositPaymentAdapterInterface`: `capturePayment()` against an existing
`depositPaymentIntentId` (authorise-now / capture-later card hold) when present, otherwise
a fresh `requestPayment()` charge. The service SHALL stamp `noShowFeeAmount`,
`noShowFeeStatus` (`captured` on a non-failed provider outcome, else `failed`),
`noShowFeePaymentIntentId`, and `noShowFeeCapturedAt` onto the appointment. A dormant
adapter SHALL still advance the bookkeeping (synthetic `captured`) so the flow stays
observable.

The `Appointment` register SHALL be extended (additive overlay, all optional) with
`noShowFeeAmount` (int cents), `noShowFeeStatus` (`none|pending|captured|failed|waived`),
`noShowFeePaymentIntentId` (string), and `noShowFeeCapturedAt` (ISO-8601 UTC).

`DepositPaymentAdapterInterface` SHALL expose `capturePayment(string $paymentIntentId,
array $payload): DepositPaymentResult`; the dormant `LogDepositPaymentAdapter` SHALL
implement it side-effect-free.

#### Scenario: No-show charges the defined fee via the provider

- **GIVEN** an appointment with `appointmentCost = 10000` cents and
  `appliedPolicy.noShowFee = 100`
- **WHEN** the no-show is recorded via `POST /api/v1/appointments/{id}/no-show`
- **THEN** `NoShowFeeCaptureService` computes a fee of `10000` cents
- **AND** it captures the fee through the `DepositPaymentAdapterInterface`
- **AND** the appointment is persisted with `status = no_show`, `noShowFeeAmount = 10000`
  and `noShowFeeStatus = captured`

#### Scenario: A booking without a defined fee is not charged

- **GIVEN** an appointment whose `appliedPolicy.noShowFee` is 0 or absent
- **WHEN** the no-show is recorded
- **THEN** no provider call is dispatched
- **AND** the appointment is stamped `noShowFeeStatus = none` with `feeCents = 0`

#### Scenario: Percentage fee against an existing authorization

- **GIVEN** an appointment with `appointmentCost = 8000`, `appliedPolicy.noShowFee = 50`,
  and a `depositPaymentIntentId` for an existing card hold
- **WHEN** the no-show is recorded
- **THEN** the fee is `4000` cents
- **AND** it is CAPTURED against the existing intent via `capturePayment()` (not a fresh
  charge)

#### Scenario: Operator authorization guard (no IDOR)

- **GIVEN** an authenticated user without access to the appointment's administration
- **WHEN** they call `POST /api/v1/appointments/{id}/no-show`
- **THEN** the request is rejected with HTTP 403 and no charge is dispatched
