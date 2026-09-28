---
kind: code
depends_on: [case-payment-requests, receivables-object-request-settlement]
---

# Proposal: receivables-object-request-refund-and-credit

## Why

A player who cancels a paid registration gets the money back or keeps it as credit for a later event. Larpinq may not hold that money or that balance: under hydra ADR-107 shillinq is the only ledger. Larpinq's merged change `registration-cancel-transfer-refund` (larpinq development `f6a55a5`, merged in larpinq PR #749) asks for it. Its "Cross-Project Dependencies" section says:

> Shillinq holds refunds and credit balances (hydra ADR-107). Its open change `case-payment-requests` and spec `ar-invoice-payment-links` cover requesting and capturing a payment on an object; nothing on shillinq development yet receives a refund or credit request for an object payment request. That half is reported to the coordinator: listen to the two larpinq events (or an agreed fleet event), refund through the provider or book a credit note on the debtor, and apply open credit to the debtor's next payment request.

Its design D3 dispatches a refund or a credit request with "registration reference, payment request id, debtor, amount of the paid request", sets `settlement: refund-requested` or `credit-requested`, and waits for "a payment request state [shillinq] emits" to set `refunded` or `credited`. Its requirement REQ-RCT-003: "Larpinq MUST NOT hold a credit balance itself."

The larpinq rows are `reg-refunds` and `reg-player-credit` in larpinq's `openspec/parity/capabilities.json`, both with LarpManager and pretix rated yes. `reg-refunds`, pretix: "src/pretix/control/views/orders.py:1086 OrderRefundView creates refunds to the original payment method or a gift card". `reg-player-credit`, LarpManager: "larpmanager/fixtures/feature.yaml:709 credits feature, credits usable for registration fees and redeemable".

Shillinq has nothing for it on a request that stands on an object. `case-payment-requests` puts refunds out of scope ("reversed through the existing credit path"), and that path, `CreditNote`, reverses an invoice through `sourceInvoiceReference`. An object request has no invoice.

These halves were handed to shillinq after shillinq's own OpenSpec-pass lane had finished (owner-moves pass, 28 September 2026). Decision: build, because a merged change of another product depends on them.

## What changes

- Shillinq publishes two typed events another app dispatches: `PaymentRefundRequestedEvent` and `PaymentCreditRequestedEvent`. They are the "agreed fleet event" larpinq's change leaves room for.
- A refund request on a settled request becomes a refund a finance user approves and pays out, recorded on the request. The request moves to `refund_requested` and then `refunded`.
- A credit request books the paid amount as credit for the debtor and moves the request to `credited`.
- When a new request is created for a debtor with open credit, the credit pays it first. A request fully paid by credit is settled at once.

## Scope

### In scope

- The two events and their listeners, the lifecycle states, a `DebtorCredit` record, the two bookings and the credit applied at create.
- A "Refunds to pay" list for finance with approve and "mark paid" actions.

### Out of scope

- A refund through the payment provider's API. Integriq's `PaymentProviderInterface` offers `createPayment` and `fetchPaymentStatus` only (integriq development, `lib/Service/Payment/PaymentProviderInterface.php`). A finance user pays the refund by bank and records it, as money that arrived another way is recorded today.
- Partial refunds and withheld fees. Larpinq's policy is all or nothing.
- Credit that expires.

## Impact

- `lib/Settings/register.d/ar-invoice-payment-links.json`: states `refund_requested`, `refunded`, `credited` and their transitions; `refunds` on `PaymentRequest`; `credit` in `settledVia`.
- New `lib/Settings/register.d/debtor-credit.json`: schema `DebtorCredit`.
- New `lib/Event/PaymentRefundRequestedEvent.php`, `lib/Event/PaymentCreditRequestedEvent.php`, `lib/Listener/PaymentRefundRequestedListener.php`, `lib/Listener/PaymentCreditRequestedListener.php`, `lib/Service/ObjectRequestRefundService.php`, `lib/Service/DebtorCreditService.php`; registrations in `lib/AppInfo/Application.php`.
- `lib/Integration/PaymentRequestLeafProvider.php` `create()`: apply open credit.
- `lib/Service/PaymentSettlementService.php`: method `credit`.
- `src/manifest.json`: the "Refunds to pay" page and its two actions.

## Rows and halves

| requesting repo | requesting change | half |
|---|---|---|
| larpinq | registration-cancel-transfer-refund | refund and credit request events on object payment requests |
| larpinq | registration-cancel-transfer-refund | credit applied to a later request |
