# Proposal: bookings-deposits

Introduce **deposit payment collection at booking time** for the booking module. Collect partial or full prepayment from customers via Mollie or Stripe at the moment of booking confirmation. Materialise a Shillinq `Invoice` linked to the booking's `Order` and use OpenConnector's payment adapter for tokenized payment routing.

## Summary

This change adds deposit-collection capability to the booking workflow. When a booking is confirmed with a deposit requirement, an `Invoice` is automatically created in Shillinq for the deposit amount (in the customer's home currency, EUR). The booking system routes the payment intent through OpenConnector, which handles PCI-compliant tokenization and gateway selection (Mollie or Stripe per customer preference). The payment result (`authorized` / `failed` / `pending`) is linked back to the booking, and on successful authorization, the booking state advances from `pending_payment` to `confirmed`.

Deposit configuration is per-booking-type: operators define a deposit rule (e.g., "50% at booking, due before 14 days before event date") which is evaluated at quote time and enforced at booking time. Settlement and full-invoice reconciliation happen in Shillinq's standalone accounts-receivable cycle.

Per ADR-031 (no app-local business logic), all deposit rules are declared as `x-openregister-lifecycle` preconditions and calculations; no `DepositService.php` is authored.

**Depends on:** `add-shillinq-accounts-receivable-core` (customer invoicing), `add-shillinq-bank-connectors` (payment gateway integration via OpenConnector).

## Motivation

18 of 21 market competitors offer deposit collection at booking time (Bookly, Cal.com, Cogsworth, Mews, OpenTable, Resy, theFork, Treatwell). Deposits are an immediate risk-mitigation signal for operators and reduce no-show friction. The feature addresses the Nextcloud booking module's top market-parity gap vs. incumbent SaaS solutions and directly supports SMB cash-flow requirements (reduce chargeback risk, accelerate confirmation, lower per-booking administrative overhead).

## Affected Projects

- [x] Project: booking — adds the `DepositPayment` register with lifecycle (authorization, capture, cancellation) and calculations (invoice creation, payment-link generation, settlement reconciliation); adds 2 manifest navigation entries (`Deposits` overview, booking-detail deposit widget).
- [x] Project: shillinq — consumes existing AR invoicing (`ARInvoice`) as the financial record, no new register added.
- [x] Project: openconnector — integration point: payment adapter routing, tokenization, webhook listener for async payment confirmation (Mollie async, Stripe webhook).
- [ ] Project: nextcloud — no changes (OAuth scope, permission boundaries already in place per ADR-005).

## Scope

### In Scope

- One new register: `DepositPayment` with fields: booking reference (FK to Order), amount, deposit rule reference, payment status (pending, authorized, captured, failed, voided), payment intent ID (from Mollie/Stripe), last API error (if any), created/authorized/capturedAt timestamps.
- Deposit rule configuration (per booking-type, declarative): deposit amount as percentage or fixed, due-date offset logic, refund policy on cancellation.
- Automatic `ARInvoice` creation in Shillinq on DepositPayment authorization, linked via FK URI.
- Payment-link generation (per-invoice calculation) for Mollie iDEAL / Stripe hosted checkout, embedded in confirmation email.
- Booking state machine: `pending_payment` → `confirmed` on successful authorization; `pending_payment` → `cancelled` on explicit cancellation or payment authorization failure after TTL.
- Webhook listener for async payment confirmation (Mollie, Stripe) with idempotent reconciliation.
- Refund initiation on booking cancellation (if authorized but not yet captured).
- Integration with `openconnector` payment adapter for PCI compliance and multi-gateway routing.

### Out of Scope

- Multi-currency conversion (all deposits in EUR per customer context; T5 multi-currency feature).
- Installment payment plans (e.g., 50% now, 50% 7 days before event).
- Dunning/retry logic on failed authorization (OpenConnector's async retry + manual operator re-attempt).
- Live bank initiation (SEPA direct debit, ACH) — payment gateways via Mollie/Stripe only.
- Chargeback dispute handling (compliance-audit feature set, out of scope).

## Risks

1. **Deposit rule misconfiguration** — operators define rules that conflict with booking dates (e.g., due date after event date). Mitigation: spec includes validation rule (REQ-DP-002).
2. **Async payment webhook failures** — Mollie/Stripe webhook delivery can be delayed or retried. Mitigation: DepositPayment carries `lastWebhookAttempt`, polling fallback in background job (T4 async worker).
3. **PCI compliance drift** — if deposit amount is logged in plain text or payment intent exposed in client response. Mitigation: spec enforces storage rules (REQ-DP-001, no payment-token on response); OpenConnector is PCI-certified.
4. **Invoice reconciliation mismatch** — deposit invoice in Shillinq does not link back to booking if FK reference is lost. Mitigation: spec includes bidirectional FK (DepositPayment → ARInvoice, ARInvoice source field references DepositPayment UUID).

## Rollback

If OpenConnector payment adapter is not available at runtime, DepositPayment creation fails with a clear error (no fallback to manual payment entry). Bookings cannot be confirmed until a valid payment method is configured. Full rollback: delete `DepositPayment` register, remove booking-detail deposit widget, revert booking state machine.

## Open Questions

1. Should refund initiation be automatic on booking cancellation, or require operator confirmation? (Spec assumes automatic; review with ops team).
2. Should deposit rules be shared across booking-types, or scoped per type? (Spec assumes per type for flexibility; confirm with product team).
3. Is a separate `DepositRule` register needed, or is inline declarative configuration sufficient? (Spec assumes inline; if rules are reusable across many booking-types, may refactor).
