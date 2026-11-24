---
kind: code
depends_on: []
---

# Proposal: inbound-payable-from-another-app

## Why

Apps in the fleet owe people money. Dossiq owes an applicant the Awb 4:17 penalty when a decision is late, a grants procedure pays an advance, an events app returns a deposit. Under hydra ADR-107 shillinq is the only ledger, but shillinq had no way for another app to hand it a payout: `APTransaction` is a supplier invoice and `PaymentRequest` collects money, it does not pay it. Dossiq therefore kept its own payout row and its own signed callback endpoint.

Ruben decided (decision 192, refactor question Q-R3-1): shillinq gets a generic inbound payable raised by another app, with amount, payee, reference and callback, usable by any app.

## What changes

- New schema `InboundPayable` (`register.d/inbound-payables.json`).
- New typed event `PayableRaisedEvent`, answered in place like the refund command; `PayableRaisedListener` stores the payable through `InboundPayableService`.
- Finance approves (expense against `inboundPayableAccount`), marks paid (payables against the bank) or rejects, at `POST /api/inbound-payables/{id}/approve|paid|reject`, gated on `payment.administer`.
- After every step `InboundPayableCallbackSender` posts the new status to the app's callback, signed with HMAC-SHA256 under the app's secret in `inboundPayableCallbackSecrets`. No secret means no callback.

## Out of scope

- A "Payouts to pay" page in the menu (follow-up; the routes and the object list work without it).
- Including payables in the SEPA payment run file (follow-up).
