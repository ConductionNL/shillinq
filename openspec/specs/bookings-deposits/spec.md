---
status: done
---

# Capability Spec: Booking Deposits at Booking Time

**Status:** proposed  
**Scope:** booking module + shillinq integration  
**Tier:** T2 (customer-facing payment feature)  
**Primary Spec:** This document  
**Depends on:** `add-shillinq-accounts-receivable-core` (invoicing), `add-shillinq-bank-connectors` (payment gateway via OpenConnector)

---

## Purpose

This spec defines the capability to collect partial or full deposits from customers at booking confirmation via Mollie or Stripe (routed through OpenConnector). Deposits are materialized as Shillinq `ARInvoice` records for accounting and tax compliance. The feature is declarative per ADR-031: all deposit rules, payment-link generation, and invoice creation logic are `x-openregister-lifecycle` and `x-openregister-calculations` metadata, not PHP code.

---

## Business Context

**Market evidence:** 18 of 21 booking-software competitors offer deposit collection (Bookly, Cal.com, Cogsworth, Mews, OpenTable, Resy, theFork, Treatwell).

**SMB pain points:**
- No-shows are common without deposit commitment
- Chargebacks are expensive (2–3% of transaction value)
- Cash-flow is tight; early payment helps cover costs
- Manual deposit tracking is administrative overhead

**Solution:** Deposits collected at booking time, immediately authorized via payment gateway, reducing friction and risk.

---

## Entities & Relationships

@e2e exclude pure backend/schema: booking deposits register — not browser-testable


### 1. DepositPayment (new register)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `depositPaymentId` | string (UUID) | Yes | Unique identifier |
| `orderId` | string (FK to Order) | Yes | Reference to the booking (Order) |
| `bookingTypeId` | string (FK to BookingType) | Yes | Reference to the booking type (for rule lookup) |
| `amount` | integer | Yes | Deposit amount in EUR cents (e.g., 7500 = €75.00) |
| `currencyCode` | string | Yes | ISO 4217 code (always EUR for now; future: multi-currency in T5) |
| `state` | enum | Yes | One of: `draft`, `pending`, `authorized`, `captured`, `failed`, `voided` |
| `paymentIntentId` | string | No | Payment intent ID from Mollie (tr_XXXXX) or Stripe (pi_XXXXX) |
| `paymentGateway` | enum | No | `mollie` or `stripe` (determined by customer preference in OpenConnector) |
| `paymentMethod` | enum | No | `ideal`, `card`, `banktransfer`, etc. (determined by gateway) |
| `arInvoiceId` | string (FK to ARInvoice) | No | Reference to the generated Shillinq AR invoice |
| `refundPolicy` | enum | Yes | One of: `automatic_on_cancellation`, `operator_approval` |
| `lastErrorCode` | string | No | Error code if authorization failed (e.g., `insufficient_funds`) |
| `lastErrorMessage` | string | No | Human-readable error message |
| `lastWebhookAttempt` | datetime | No | Timestamp of last webhook event received |
| `createdAt` | datetime | Yes | Timestamp of DepositPayment creation |
| `authorizedAt` | datetime | No | Timestamp when payment was authorized |
| `capturedAt` | datetime | No | Timestamp when payment was captured (if applicable) |
| `voidedAt` | datetime | No | Timestamp when payment was voided/refunded |

**Relations:**
- → Order (many-to-one): Each booking can have one DepositPayment
- → BookingType (many-to-one): For rule evaluation
- → ARInvoice (one-to-one): Auto-created on authorization

### 2. Order (existing, extended)

State machine extended to include `pending_payment`:

```
draft 
  → pending_payment (if booking-type has deposit rule)
     → confirmed (on successful DepositPayment authorization)
     → cancelled (on timeout, payment failure, or operator cancellation)
  → confirmed (skip pending_payment if no deposit rule)
     → completed
     → cancelled
```

New fields on Order:
- `depositRequired` (boolean, computed from BookingType.depositRule): Is a deposit needed?
- `depositAmount` (integer, computed from rule + basePrice): Deposit amount in EUR cents
- `depositPaymentId` (string, FK to DepositPayment): Link to the payment record

### 3. BookingType (existing, extended)

New nested object:
```json
"depositRule": {
  "enabled": true,
  "type": "percentage" | "fixed",
  "percentage": 50,           // if type=percentage
  "amount": 500,              // if type=fixed (EUR cents)
  "dueOffsetDays": 14,        // days before event date
  "refundPolicy": "automatic_on_cancellation" | "operator_approval",
  "description": "50% deposit due 14 days before event"
}
```

---

## Requirements

### REQ-DP-001: DepositPayment Storage & Tokenization

**Requirement:** DepositPayment records MUST NOT store payment card details, CVV, or authorization tokens. All sensitive payment data is handled by OpenConnector (PCI-certified).

#### Scenario: Only opaque references stored

- **GIVEN** a booking confirmation request with payment method "credit card"
- **WHEN** the system routes payment to OpenConnector
- **THEN** the DepositPayment stores only paymentIntentId (opaque reference from gateway), paymentGateway (mollie|stripe), paymentMethod (ideal|card|etc), and authorization status (not token)
- **AND** no card number, expiry, or CVV is logged anywhere

### REQ-DP-002: Deposit Rule Validation

**Requirement:** Deposit rules MUST be validated at booking-type creation time and at quote/confirmation time to prevent invalid configurations.

**Validation rules:**
- If `type=percentage`: `percentage` in range [1, 100]
- If `type=fixed`: `amount` > 0 (in EUR cents)
- `dueOffsetDays` ≥ 0 and ≤ 365
- If booking's event date is within `dueOffsetDays` from now, deposit is due immediately
- Deposit amount (after tax) MUST NOT exceed booking's total price

#### Scenario: Deposit due-date validation

- **GIVEN** a booking-type with depositRule.type=percentage, percentage=50, dueOffsetDays=14
- **WHEN** a booking is created for event date 2026-06-15 (today is 2026-05-21, 25 days away)
- **THEN** depositRequired=true, dueDate=2026-06-01 (14 days before event)
- **WHEN** a booking is created for event date 2026-05-30 (9 days away)
- **THEN** depositRequired=true, dueDate=immediately (event is within 14 days)

### REQ-DP-003: Automatic ARInvoice Creation

**Requirement:** When a DepositPayment transitions to `authorized` state, an `ARInvoice` MUST be automatically created in Shillinq, linking back to the DepositPayment.

#### Scenario: ARInvoice created on authorization

- **GIVEN** a DepositPayment with state=pending, orderId=ord-123, amount=7500 (€75)
- **WHEN** a webhook event from Mollie confirms payment authorization
- **THEN** DepositPayment.state transitions to authorized
- **AND** an ARInvoice is created with customerId (from Order.customerId), lineItem: "Studio Portrait Session - Deposit (50%)", amount=7500, taxRate=21%, dueDate (from DepositPayment's rule: event date minus dueOffsetDays), sourceDocumentUri: "urn:nextcloud:booking:deposit-payment:dp-XXXXX", state: "issued"
- **AND** ARInvoice.id is stored in DepositPayment.arInvoiceId

### REQ-DP-004: Booking State Transition on Payment Authorization

**Requirement:** Order (booking) state MUST transition from `pending_payment` to `confirmed` only after successful payment authorization.

#### Scenario: Order confirmed on authorization

- **GIVEN** an Order with state=pending_payment, depositPaymentId=dp-001
- **WHEN** DepositPayment.state transitions to authorized
- **THEN** Order.state automatically transitions to confirmed
- **AND** booking confirmation email is sent (existing booking flow)

#### Scenario: Order cancelled on payment failure

- **GIVEN** an Order with state=pending_payment, depositPaymentId=dp-002
- **WHEN** DepositPayment.state transitions to failed (after 3 failed attempts)
- **THEN** Order.state automatically transitions to cancelled
- **AND** cancellation email is sent to customer

### REQ-DP-005: Payment-Link Generation

**Requirement:** DepositPayment MUST have a calculated `paymentLink` field (per `x-openregister-calculations`) that generates a customer-facing payment URL.

#### Scenario: Payment-link generation

- **GIVEN** a DepositPayment with state=pending, depositPaymentId=dp-123
- **AND** an ARInvoice has been created (inv-ar-5001)
- **WHEN** the deposit-payment confirmation email is being rendered
- **THEN** DepositPayment.paymentLink evaluates to a URL, e.g.: https://nextcloud.example/apps/booking/pay?deposit=dp-123&invoice=inv-ar-5001&token=JWT
- **AND** the link is embedded in the email as "Complete Payment"
- **AND** clicking the link opens OpenConnector's payment UI (Mollie iDEAL, Stripe Checkout)

### REQ-DP-006: Webhook Reconciliation (Mollie, Stripe)

**Requirement:** Async payment confirmation via webhook MUST idempotently update DepositPayment state and trigger ARInvoice generation.

**Webhook listener contract:**
- **Mollie `payment.paid`**: `payment` object contains `id` (payment intent), amount, status
- **Stripe `payment_intent.succeeded`**: `payment_intent` object contains `id`, amount, status

#### Scenario: Idempotent webhook reconciliation

- **GIVEN** a DepositPayment with paymentIntentId=pi_XXXXX, state=pending
- **WHEN** a Stripe webhook event {event_type: "payment_intent.succeeded", ...} arrives
- **THEN** webhook listener looks up DepositPayment by paymentIntentId, checks current state (if already authorized, idempotent: no-op), transitions state to authorized, triggers ARInvoice creation lifecycle action, and responds with HTTP 200 OK (success) or 202 (queued) per Stripe best practices
- **AND** if the webhook arrives twice (replay), the state check prevents double-booking of ARInvoice

### REQ-DP-007: Polling Fallback for Missed Webhooks

**Requirement:** A background job (T4 async worker) MUST poll OpenConnector for pending DepositPayments to ensure webhook loss doesn't cause indefinite `pending` state.

#### Scenario: Polling fallback reconciles state

- **GIVEN** a DepositPayment with state=pending, paymentIntentId=mollie_tr_12345
- **AND** no webhook event has arrived for 5+ minutes
- **WHEN** the background job runs (every 5 minutes)
- **THEN** it calls OpenConnector.getPaymentStatus(paymentIntentId)
- **AND** receives status: {status: "authorized", amount: 7500, ...}
- **AND** DepositPayment.state is updated to authorized (if not already)
- **AND** the webhook listener's ARInvoice creation is triggered

### REQ-DP-008: Refund on Booking Cancellation

**Requirement:** When a booking is cancelled and a DepositPayment has been authorized/captured, a refund MUST be initiated per the booking-type's refund policy.

**Refund Policy:**
- `automatic_on_cancellation`: Refund is immediately initiated via OpenConnector
- `operator_approval`: A refund request is created for operator review (future feature, T3+)

#### Scenario: Automatic refund on cancellation

- **GIVEN** a booking (Order.id=ord-500) with state=confirmed
- **AND** a DepositPayment (depositPaymentId=dp-500) with state=captured, amount=7500
- **AND** BookingType.depositRule.refundPolicy=automatic_on_cancellation
- **WHEN** operator clicks "Cancel Booking"
- **THEN** Order.state transitions to cancelled
- **AND** DepositPayment.state transitions to voided
- **AND** a refund request is sent to OpenConnector: {refund: {paymentIntentId: mollie_tr_XXX, amount: 7500}}
- **AND** a CR (credit note) is automatically created in Shillinq (reversing the ARInvoice, per AR module's own lifecycle)
- **AND** refund confirmation email is sent to customer

### REQ-DP-009: Multi-Currency Preparation (T5)

**Requirement:** DepositPayment.currencyCode MUST be a separate field, initialized to EUR for now, with no hardcoded currency assumptions in logic.

#### Scenario: Currency code as a declared field

- **GIVEN** a future T5 multi-currency feature enables customer-home-currency invoicing
- **WHEN** a customer selects USD payment (in future)
- **THEN** DepositPayment.currencyCode=USD, amount is converted at spot rate
- **AND** no existing T2 code breaks because currencyCode is a declared field

### REQ-DP-010: Booking-Detail Widget & Deposits Overview

**Requirement:** The booking-detail manifest page MUST include a deposit widget showing DepositPayment state + payment-link. A separate `Deposits` overview page MUST list all pending/failed deposits for operator management.

#### Scenario: Booking-detail widget and deposits overview

- **GIVEN** a booking with a DepositPayment in pending state
- **WHEN** the operator opens the booking-detail manifest page
- **THEN** a deposit widget shows the deposit status (e.g., "Pending (⏱ expires in 12 hours)"), amount (e.g., €75.00), a "Complete Payment" button linked to paymentLink, and a "View Invoice" link to ARInvoice.uri
- **WHEN** the operator opens the separate `Deposits` overview page (manifest entry `type: index`)
- **THEN** it lists all deposits with columns Customer | Booking | Amount | State | Due Date | Action, filters for State (pending, authorized, captured, failed) and Date Range, and bulk actions [Void Selected] and [Resend Payment Link]

### REQ-DP-011: Error Handling & Logging

**Requirement:** Payment failures MUST be logged with error code and message, visible in both DepositPayment record and operator UI.

#### Scenario: Payment failure logging

- **GIVEN** a payment authorization attempt fails with error code insufficient_funds
- **WHEN** the webhook (or polling job) receives the failure response
- **THEN** DepositPayment.state=failed, lastErrorCode=insufficient_funds
- **AND** DepositPayment.lastErrorMessage="Insufficient funds. Please use a different card."
- **AND** customer receives an email: "Payment failed. Please try again or contact support."
- **AND** operator sees the error in the booking-detail widget for manual follow-up

---

## Implementation Notes

### Per ADR-031: No App-Local Business Logic

All deposit rules, state transitions, invoice creation, and payment-link generation are declared as:
- `x-openregister-lifecycle`: State machine + lifecycle actions (authorized → create ARInvoice, voided → create credit note)
- `x-openregister-calculations`: Payment-link URL generation, deposit-amount computation
- `x-openregister-aggregations`: Deposits overview aggregation

No `DepositPaymentService.php`, `RefundService.php`, or `PaymentLinkGenerator.php` is authored. If the lifecycle engine cannot express conditional preconditions (e.g., "advance state only if ARInvoice creation succeeds"), ADR-031's single-method exception path applies.

### OpenConnector Integration

DepositPayment never directly calls Mollie or Stripe APIs. All calls are routed through OpenConnector:
- `OpenConnector.createPaymentIntent(amount, currency, customerId, orderId)`
- `OpenConnector.getPaymentStatus(paymentIntentId)`
- `OpenConnector.initiateRefund(paymentIntentId, amount)`

OpenConnector handles PCI compliance, gateway selection, and token management.

### Webhook Listener Deployment

A webhook listener endpoint is deployed to `/apps/booking/webhook/payment-gateway`. OpenConnector routes Mollie and Stripe events to this endpoint. The listener:
1. Validates webhook signature (per Mollie/Stripe spec)
2. Looks up DepositPayment by paymentIntentId
3. Idempotently updates state
4. Triggers lifecycle actions (e.g., ARInvoice creation)
5. Responds with HTTP 200 OK or 202 Accepted

---

## Testing Strategy (company-wide ADR-009)

### Unit Tests
- Deposit-rule validation (percentages, fixed amounts, date logic)
- State-machine transitions (draft → pending → authorized → captured, error paths)
- ARInvoice creation calculations (tax, due-date computation)
- Webhook idempotency (double-webhook does not double-invoice)

### Integration Tests
- End-to-end booking with deposit: create booking, authorize payment, verify ARInvoice in Shillinq, transition Order to confirmed
- Refund flow: cancel booking, verify credit-note created in Shillinq, DepositPayment voided
- Webhook listener: mock Mollie/Stripe webhook, verify DepositPayment updated and ARInvoice created
- Polling fallback: simulate webhook loss, verify background job reconciles state

### Browser Tests (Playwright MCP)
- Booking detail widget: deposit state display, payment-link click (mock OpenConnector)
- Deposits overview page: filter by state, sort by due date, bulk void
- Confirmation email: payment-link renders correctly

---

## Documentation (company-wide ADR-010)

User guide: `docs/user-guide/booking/deposits.md` (with screenshots)
- How to enable deposits for a booking-type
- Configuring deposit amount (percentage vs. fixed, due-date)
- Refund policy options
- Operator manual: cancelling bookings with deposits, reviewing failed payments

---

## Internationalization (company-wide ADR-025)

Strings for Dutch (`nl_NL`) and English (`en_US`):
- "Deposit due before event" → "Borg verschuldigd voor event"
- "Payment pending" → "Betaling in afwachting"
- "Refund initiated" → "Terugbetaling gestart"
- Payment error messages (e.g., "Insufficient funds") localized per gateway

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Webhook delivered late; booking expires in pending_payment state | Polling fallback (REQ-DP-007) reconciles state within 5 minutes |
| Double webhook event from gateway | Idempotent state transitions (REQ-DP-006): only authorize once |
| Operator misconfigures deposit rule (e.g., due date after event) | Validation on rule creation (REQ-DP-002) |
| DepositPayment created but ARInvoice creation fails | Lifecycle action has a retry mechanism; operator is alerted in booking detail |
| Payment intent expires (typically 24h) before customer pays | Expiry timestamp displayed in booking-detail widget + booking state → cancelled on expiry (T4 job) |
| PCI compliance: payment token logged in plain text | No tokens stored; only OpenConnector's opaque paymentIntentId (REQ-DP-001) |

---

## Open Questions for Review

1. Should `DepositRule` be a separate register (reusable across many booking-types), or inline in BookingType? (Spec assumes inline; confirms with product team required.)
2. Should refund be automatic, or require operator confirmation? (Spec assumes automatic per `refundPolicy`; ops team feedback needed.)
3. Should polling fallback interval be 5 minutes, or user-configurable? (Spec assumes 5 minutes; check with SRE for scalability.)
