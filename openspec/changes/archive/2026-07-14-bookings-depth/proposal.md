---
kind: feature
---

# Change: bookings-depth

## Why

The bookings-domain sweep surfaced two gaps where a capability was **defined** but had
no runtime realisation — the exact "spec-says-done ≠ feature runs" defect class:

1. **no-show-fee-capture** — `bookings-cancellation-rules` declares
   `CancellationPolicy.noShowFee` (a 0-100 percentage of the appointment cost,
   snapshotted onto `Appointment.appliedPolicy`) and lists `no_show` as a cancellation
   reason, but **no code ever reads or charges `noShowFee`** (verified: the only
   references are the register.d schema + a fragment test — `CancellationService`
   computes *refunds* from `lateFeeBrackets`, never the no-show fee). The fee is spec'd
   and unenforceable. shillinq already runs booking deposits through Mollie/Stripe via
   openconnector behind the `DepositPaymentAdapterInterface` lifecycle port, whose
   states already include `authorized` / `captured` (authorise-now / capture-later) —
   so the capture rail exists; nothing invokes it for the no-show fee.

2. **recurring-appointment-series** — `bookings/spec.md` self-declares recurring
   bookings **DEFERRED to Tier-2** ("Tier-2 enhancements (recurring bookings, staff
   availability rules)"). The `Appointment` schema carries no recurrence fields and no
   series service exists (verified). The availability/conflict engine (`SlotService`
   slot enumeration + overlap; `ConflictDetectionService`) is present and reusable.

## What Changes

- **no-show-fee-capture**
  - **NEW `capturePayment()`** on `DepositPaymentAdapterInterface` (+ dormant
    `LogDepositPaymentAdapter` default) — the authorise-now / capture-later rail used to
    capture a defined fee against an existing card hold / authorization.
  - **NEW `OCA\Shillinq\Service\NoShowFeeCaptureService`** — computes the fee
    (`round(appointmentCost × noShowFee / 100)`, integer cents) and captures it through
    the provider rails: capture against an existing `depositPaymentIntentId` when
    present, else open a fresh charge via `requestPayment`. A booking with **no defined
    fee dispatches no charge** (design D1).
  - **Appointment overlay** gains `noShowFeeAmount` / `noShowFeeStatus` /
    `noShowFeePaymentIntentId` / `noShowFeeCapturedAt` bookkeeping fields.

- **recurring-appointment-series**
  - **NEW `AppointmentSeries` schema** (RRULE + base occurrence template) + `Appointment`
    overlay `seriesId` / `recurrenceIndex`.
  - **NEW `OCA\Shillinq\Service\RecurringSeriesService`** — expands an RRULE-style rule
    (`FREQ=DAILY|WEEKLY|MONTHLY`, `INTERVAL`, `COUNT`/`UNTIL`, `BYDAY`) into occurrences
    and, per occurrence, **REUSES `SlotService`'s slot enumeration** (opening/closing
    hours + overlap) to decide availability — no forked conflict logic. Available
    occurrences become individual `Appointment` payloads tagged with `recurrenceIndex`;
    availability/conflict violations are skipped.

- **Reachable operator surface** — **NEW `BookingDepthController`** (ADR-003, thin) with
  `POST /api/v1/appointments/{id}/no-show` and `POST /api/v1/appointment-series`, both
  `#[NoAdminRequired]` + per-administration guard (ADR-005, no IDOR), persisting via
  OpenRegister's ObjectService (ADR-022). This ensures the two capabilities are
  **invoked**, not orphaned.

- **Tests** — service + controller PHPUnit: a no-show charges the defined fee via the
  provider (and a booking without a defined fee does not); a recurring series generates
  the right individual appointments and skips availability/conflict violations.

## Capabilities

### New Capabilities
- `bookings-recurring-series`: RRULE-driven appointment series generation reusing the
  existing availability/conflict rules.

### Modified Capabilities
- `bookings-cancellation-rules`: adds the no-show-fee **capture** behaviour that realises
  the previously-unenforceable `noShowFee` field.

## Impact

- Extends bookings services only; no app-owned tables (ADR-022). Additive schema overlays
  — no breaking changes to existing bookings registers. Payment default binding stays
  dormant (log-only) until a production PSP binding is wired in `Application::register()`.
