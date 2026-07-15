# Design: bookings-depth

## Per-item verify verdicts (against HEAD `1e7c9df8`)

### 1. no-show-fee-capture — VERDICT: REAL GAP (built)

- `bookings-cancellation-rules` (archived 2026-06-14) declares
  `CancellationPolicy.noShowFee` (`register.d/40-bookings-cancellation-rules.json`,
  a 0-100 percentage; seeds 100/100/50) and snapshots the policy onto
  `Appointment.appliedPolicy`.
- **Grep proof**: `noShowFee` appears ONLY in `register.d/40-bookings-cancellation-rules.json`
  and `tests/…/BookingsCancellationRulesFragmentTest.php`. **Zero code reads or charges
  it.** `CancellationService` computes *refunds* from `lateFeeBrackets`; the `no_show`
  reason flows through the same refund path and never triggers a charge.
- The capture rail already exists: `DepositPaymentAdapterInterface`
  (`requestPayment` / `fetchStatus` / `initiateRefund`, lifecycle states include
  `authorized` / `captured`; `DepositPayment` schema has `authorizedAt` / `capturedAt`)
  — but there was **no `capturePayment()`** and nothing wired the fee to it.
- **Delta built**: `capturePayment()` on the port + dormant adapter;
  `NoShowFeeCaptureService` (compute + capture); Appointment no-show bookkeeping fields;
  `BookingDepthController::captureNoShow`.

### 2. recurring-appointment-series — VERDICT: REAL GAP (built)

- `bookings/spec.md` §Notes: "Tier-2 enhancements (recurring bookings, staff
  availability rules) will extend these entities" — recurring is explicitly deferred.
- `Appointment` schema (`register.d/10-bookings-create-appointment.json`) has no
  recurrence field; no `RecurringSeries`/RRULE service exists (only the unrelated
  `RecurringInvoiceGenerator`).
- Reusable engine present: `SlotService::enumerateSlotsPublic` (opening/closing hours +
  overlap, past-slot filter) and `ConflictDetectionService::checkConflicts`.
- **Delta built**: `AppointmentSeries` schema + `Appointment` recurrence overlay;
  `RecurringSeriesService` (RRULE expander + per-occurrence availability decision that
  **calls `SlotService::enumerateSlotsPublic`** — no forked overlap logic);
  `BookingDepthController::createSeries`.

## Decisions

- **D1 — no fee ⇒ no charge.** `NoShowFeeCaptureService::computeNoShowFeeCents` returns 0
  when cost ≤ 0, no policy, or `noShowFee ≤ 0`; a 0 fee short-circuits before any adapter
  call and stamps `noShowFeeStatus = none`. This is the exact test contract ("a booking
  without a defined fee does not charge").
- **D2 — integer cents, round half-up.** Fee = `round(cost × pct / 100)` in integer
  cents; pct clamped 0-100; fee clamped to cost. No IEEE-754 drift.
- **D3 — capture rail selection.** If the appointment carries a `depositPaymentIntentId`
  (existing card hold / authorization) the fee is CAPTURED against it (capture-later);
  otherwise a fresh charge is opened via `requestPayment` (authorise-now). Both go
  through the same `DepositPaymentAdapterInterface`, so no Mollie-vs-Stripe branch leaks.
- **D4 — reuse, don't fork (recurring).** Availability/conflict for each occurrence is
  decided by asking `SlotService::enumerateSlotsPublic` for the occurrence's date and
  checking exact-start membership. Earlier-generated occurrences are folded back into the
  existing-appointment set so a later occurrence cannot double-book.
- **D5 — RRULE subset.** `FREQ=DAILY|WEEKLY|MONTHLY`, `INTERVAL`, `COUNT`, `UNTIL`
  (date-only inclusive), `BYDAY` (WEEKLY). Open-ended rules are capped at
  `MAX_OCCURRENCES = 366` (fail-safe against runaway expansion). Unsupported FREQ throws
  `InvalidArgumentException` → controller 400.
- **D6 — ADR-031.** No lifecycle guard is introduced; both services are ADR-022
  ObjectService consumers invoked from a thin ADR-003 controller. The `AppointmentSeries`
  schema carries a declarative `x-openregister-lifecycle` (`active → cancelled`) — the
  ADR-031 declarative-first path; the capture/expansion arithmetic that the declarative
  DSL cannot express lives in the two services (the documented ADR-031 exception shape,
  matching in-repo precedent `RevenueRecognitionService` / `EmuCalculator`).
- **D7 — dormant by default.** The payment binding stays `LogDepositPaymentAdapter`
  (log-only, `dormant=true`) until a production PSP binding is wired; the no-show flow
  still advances the bookkeeping so it stays observable in test/staging.

## Seed Data

`register.d/60-bookings-depth.json` seeds one demonstrative `AppointmentSeries`
(`series-yoga-weekly-demo`, `FREQ=WEEKLY;BYDAY=MO;COUNT=4`, 2030 dates so it never
collides with "now"). No new `CancellationPolicy` / `DepositPayment` seeds are needed —
the archived `bookings-cancellation-rules` change already seeds policies carrying
`noShowFee` (100/100/50) that the capture path consumes.

## Risks

- **Orphaned-capability risk** (the very defect this change closes) — mitigated by the
  reachable `BookingDepthController` + routes + controller test, so both capabilities have
  a real invocation path, not just direct-from-test calls.
- **Provider dormancy** — a dormant capture returns a synthetic `captured`/`pending`
  state; the `dormant` flag is logged so operators know no money moved until a PSP binding
  is configured.
