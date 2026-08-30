# Design — Booking Deposits at Booking Time

## Context

18 of 21 booking-software competitors offer deposit collection at booking confirmation. For SMB operators (photography, events, services), deposits reduce no-show friction and provide immediate risk-mitigation signals. The Nextcloud booking module's integration with Shillinq invoicing and OpenConnector payment routing makes this feature technically addressable without bespoke payment code.

Per ADR-031 (no app-local business logic), all deposit rules are declared as `x-openregister-lifecycle` preconditions and calculations on the `DepositPayment` register; no `DepositService.php` is authored. Settlement and aging are handled by Shillinq's accounts-receivable (AR) module in its own lifecycle cycle (invoice → payment → credit note if refunded).

## Goals

- Enable SMBs to collect partial or full payment at booking confirmation, reducing no-show risk and chargeback exposure.
- Declare all deposit rules as **declarative metadata** (lifecycle, calculations, aggregations) per ADR-031 — no app-local business logic.
- Integrate **transparently with Shillinq AR** — DepositPayment creates an ARInvoice on authorization; refunds feed back into AR's credit-note workflow.
- Support **multi-gateway routing** through OpenConnector (Mollie iDEAL, Stripe Checkout, future SEPA/ACH).
- Keep the FK shape clean: booking → DepositPayment → ARInvoice (no reverse FK pollution).

## Non-Goals

- Multi-currency conversion or real-time FX hedging (T5 feature).
- Installment payment plans (future enhancement if demand exists).
- Manual dunning/retry orchestration (OpenConnector's async retry sufficient; T4 background job for polling).
- Chargeback/dispute handling (separate compliance-audit spec).

## Decisions

### D1 — DepositPayment is a register, not a service

The `DepositPayment` register carries the booking reference, amount, payment intent ID, and status. A separate `DepositRule` record (or inline lifecycle configuration) defines per-booking-type rules (percentage, fixed amount, due-date offset, refund policy). On booking confirmation with a deposit rule, a DepositPayment is created and transitioned to `pending` → `authorized` → `captured` (or fails).

**Alternative considered**: A single-method `DepositService` that orchestrates creation, payment intent routing, and ARInvoice sync. Rejected per ADR-031 — the lifecycle engine + calculation extensions cover the orchestration; a service adds coupling without benefit.

### D2 — Deposit creates an ARInvoice, not a standalone Payment

When a DepositPayment transitions to `authorized`, an `ARInvoice` is automatically created in Shillinq (via lifecycle action, similar to T1 GL materialization). The ARInvoice lines carry the deposit amount and any applicable taxes. On booking cancellation, an AR `CreditNote` is created for refunds (if captured).

**Alternative considered**: DepositPayment directly creates an AR `Payment` record (skipping invoice). Rejected — AR invoicing workflows require an invoice (AR aging, revenue recognition, tax reporting); a payment-only approach would break tax and audit workflows.

### D3 — Deposit rules are per-booking-type and declarative

A booking-type record (e.g., "Outdoor Photography Session") has a nested `x-openregister-lifecycle.depositRule` object with:
```
{
  "type": "percentage" | "fixed",
  "percentage": 50,     // if type=percentage
  "amount": 500,        // if type=fixed (in EUR cents)
  "currencyCode": "EUR",
  "dueOffsetDays": 14,  // relative to event date (e.g., "14 days before event")
  "refundPolicy": "automatic_on_cancellation" | "operator_approval",
  "description": "50% deposit due 14 days before event"
}
```

This is evaluated at quote time and enforced at booking confirmation. If the booking's event date is within `dueOffsetDays`, the deposit is due immediately.

**Alternative considered**: A separate `DepositRule` register with rules reusable across booking-types. Rejected for now — inline configuration is simpler for the initial feature; if demand for rule reuse grows, future refactor is low-risk.

### D4 — Payment routing through OpenConnector, not direct Mollie/Stripe

DepositPayment declares an `x-openregister-calculations` field `paymentLink` that invokes OpenConnector's payment adapter. The adapter handles:
- PCI-compliant tokenization (never store raw card details)
- Payment method routing (Mollie iDEAL → customer's bank, Stripe Checkout → card/iDEAL hybrid)
- Async webhook handling (Mollie sends async confirmation; Stripe can also use webhooks)

No DepositPayment code directly calls Mollie or Stripe APIs; all routing is OpenConnector's responsibility.

**Alternative considered**: Direct Mollie SDK calls in a DepositService. Rejected — PCI risk, no abstraction over future payment methods, couples us to Mollie versioning.

### D5 — Webhook reconciliation is idempotent and has a polling fallback

OpenConnector delivers a webhook when payment is authorized (Mollie `payment.paid` event, Stripe `payment_intent.succeeded` event). The webhook listener:
1. Looks up the DepositPayment by payment intent ID.
2. Idempotently transitions it to `authorized` (idempotent because status is already checked).
3. Triggers the ARInvoice creation lifecycle action.

If the webhook is lost or delayed, a background job (T4 async worker) polls OpenConnector's API every 5 minutes for pending DepositPayments, checking their status. This prevents indefinite `pending` state.

**Alternative considered**: Synchronous payment capture (block on booking confirmation until payment completes). Rejected — Mollie async payments (iDEAL) take seconds; blocking the UI is poor UX. Spec assumes async model; operators see a "Payment pending, check email" message until webhook confirms.

### D6 — Booking state machine includes `pending_payment` state

The Order (booking) state machine is updated to:
```
draft → pending_payment → confirmed → completed
          ↓
       cancelled (if no-deposit rule, or deposit failed/expired, or operator cancelled)
```

Only bookings with a deposit rule and status `pending_payment` can transition to `confirmed` via successful DepositPayment authorization. Bookings without a deposit rule skip `pending_payment` and go directly to `confirmed`.

**Alternative considered**: Payment status on Order as a flag (is_deposit_paid), not a state. Rejected — state machines are clearer for audit, prevent invalid transitions, and make the UI logic explicit.

## Reuse Analysis

| Entity | Reused From | Design Note |
|--------|------------|------------|
| `Order` | booking module (existing) | State machine extended with `pending_payment` state; no schema changes to Order itself, only lifecycle. |
| `ARInvoice` | shillinq-accounts-receivable | Automatically created by DepositPayment lifecycle action; links back via `sourceDocumentUri` pointing to DepositPayment. |
| `CreditNote` | shillinq-accounts-receivable | Created on booking cancellation (refund workflow); reverse-linked to the ARInvoice via AR module's own mechanisms. |
| `DepositPayment` | booking-deposits (new) | Register carrying payment intent ID, status, and rule reference. |
| Payment gateway | OpenConnector (existing) | Adapter handles Mollie/Stripe routing; DepositPayment never talks to gateways directly. |

## Seed Data (Examples)

### Booking Type: "Studio Portrait Session"
```json
{
  "bookingTypeId": "bt-001",
  "name": "Studio Portrait Session",
  "durationMinutes": 120,
  "basePrice": 15000,  // EUR cents (€150.00)
  "currencyCode": "EUR",
  "description": "Professional studio portrait photography, 2-hour session",
  "depositRule": {
    "type": "percentage",
    "percentage": 50,
    "dueOffsetDays": 14,
    "refundPolicy": "automatic_on_cancellation",
    "description": "50% deposit due 14 days before session"
  }
}
```

### Booking Instance: "Portrait session 2026-06-15"
```json
{
  "orderId": "ord-1001",
  "bookingTypeId": "bt-001",
  "customerId": "cust-5432",
  "eventDate": "2026-06-15",
  "eventTime": "14:00",
  "state": "pending_payment",
  "estimatedTotal": 15000,  // EUR cents
  "depositRequired": 7500,  // 50% of €150
  "createdAt": "2026-05-21T10:00:00Z"
}
```

### DepositPayment: Authorized
```json
{
  "depositPaymentId": "dp-5001",
  "orderId": "ord-1001",
  "amount": 7500,  // EUR cents
  "state": "authorized",
  "paymentIntentId": "mollie_tr_9h8g7f6e5d",
  "paymentGateway": "mollie",
  "paymentMethod": "ideal",
  "authorizedAt": "2026-05-21T10:05:30Z",
  "arInvoiceId": "inv-ar-9002",  // Created by lifecycle action
  "refundPolicy": "automatic_on_cancellation"
}
```

### ARInvoice: Auto-generated from DepositPayment
```json
{
  "invoiceId": "inv-ar-9002",
  "invoiceNumber": "AR-2026-0342",
  "customerId": "cust-5432",
  "invoiceDate": "2026-05-21",
  "dueDate": "2026-06-01",  // 14 days before event, per deposit rule
  "grossAmount": 7500,
  "vatAmount": 1575,  // 21% NL standard rate
  "netAmount": 5925,
  "lineItems": [
    {
      "description": "Studio Portrait Session - Deposit (50%)",
      "quantity": 1,
      "unitPrice": 7500,
      "amount": 7500,
      "taxRate": 21
    }
  ],
  "sourceDocumentUri": "urn:nextcloud:booking:deposit-payment:dp-5001",
  "state": "issued",
  "paymentTerms": "Due immediately"
}
```

## Timeline & Dependencies

- **T0 (now)**: Spec review, architecture alignment (ADR-031 compliance).
- **T1** (booking-deposits implementation): Declare `DepositPayment` register, implement lifecycle + calculations, integrate with OpenConnector adapter (async opsx-apply).
- **T2** (shillinq AR maturation): Ensure ARInvoice creation and credit-note refund workflows are solid; deploy T1 after T2 is stable.
- **T3** (payment gateway rollout): Live Mollie and Stripe integration via OpenConnector; webhook listener deployment; polling fallback (background job, T4+).
