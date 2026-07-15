# Tasks: bookings-depth

Bundles two verified-real bookings gaps. Extends existing bookings services; consumes OR
abstractions (ADR-022). SPDX EUPL-1.2 on new PHP; i18n EN+NL.

## 1. no-show-fee-capture

- [x] 1.1 Add `capturePayment(string $paymentIntentId, array $payload): DepositPaymentResult` to `DepositPaymentAdapterInterface` (authorise-now / capture-later rail) + `@spec`
- [x] 1.2 Implement `capturePayment()` on the dormant `LogDepositPaymentAdapter` (synthetic `captured` / `PAYMENT_DEFERRED`, `dormant=true`)
- [x] 1.3 Create `lib/Service/NoShowFeeCaptureService.php`: `computeNoShowFeeCents()` (round half-up, integer cents, clamp 0-100 / ≤cost; 0 when no cost/policy/fee — D1/D2) + `captureNoShowFee()` (capture existing intent, else fresh `requestPayment`; no fee ⇒ no charge — D3)
- [x] 1.4 Extend `Appointment` overlay (`register.d/60-bookings-depth.json`) with `noShowFeeAmount` / `noShowFeeStatus` / `noShowFeePaymentIntentId` / `noShowFeeCapturedAt`

## 2. recurring-appointment-series

- [x] 2.1 Declare `AppointmentSeries` schema (RRULE + base occurrence template, `x-openregister-lifecycle active→cancelled`, seed one demo series) + `Appointment` overlay `seriesId` / `recurrenceIndex` in `register.d/60-bookings-depth.json`
- [x] 2.2 Create `lib/Service/RecurringSeriesService.php` `expandRule()`: RRULE subset (`FREQ=DAILY|WEEKLY|MONTHLY`, `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY`), cap `MAX_OCCURRENCES=366`, throw on unsupported FREQ (D5)
- [x] 2.3 `planSeries()`: per occurrence REUSE `SlotService::enumerateSlotsPublic` for the availability/conflict decision (no fork — D4); tag generated appointments with `recurrenceIndex`; fold generated occurrences into the existing set; skip violations with a reason

## 3. Reachable operator surface (ADR-003 / ADR-005)

- [x] 3.1 Create `lib/Controller/BookingDepthController.php`: `#[NoAdminRequired]` `captureNoShow()` + `createSeries()`, per-administration guard (no IDOR), persist via ObjectService (ADR-022)
- [x] 3.2 Register routes `bookingDepth#captureNoShow` (`POST /api/v1/appointments/{appointmentId}/no-show`) + `bookingDepth#createSeries` (`POST /api/v1/appointment-series`) in `appinfo/routes.php`
- [x] 3.3 Classify `AppointmentSeries` as a bookings (non-bookkeeping) schema in `tests/validate-registers.js`

## 4. i18n (ADR-007)

- [x] 4.1 Add EN + NL keys for the new user-facing strings (No-Show Fee capture status, Appointment Series labels)

## 5. Tests + spec

- [x] 5.1 `tests/Unit/Service/NoShowFeeCaptureServiceTest.php`: no-show charges the defined fee via the provider; a booking without a defined fee dispatches no charge; percentage + clamp + capture-against-authorization paths
- [x] 5.2 `tests/Unit/Service/RecurringSeriesServiceTest.php`: RRULE expansion (weekly/daily/monthly/until/unsupported); series generates the right individual appointments; skips availability + conflict violations
- [x] 5.3 `tests/Unit/Controller/BookingDepthControllerTest.php`: 401/403 guards; no-show happy path persists bookkeeping; series happy path persists AppointmentSeries + individual appointments
- [x] 5.4 Spec deltas: MODIFIED `bookings-cancellation-rules` (no-show capture) + ADDED `bookings-recurring-series`
