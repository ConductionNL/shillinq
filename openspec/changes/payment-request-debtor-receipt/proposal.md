---
kind: code
depends_on: [case-payment-requests]
---

# Proposal: payment-request-debtor-receipt

Asked in shillinq#1836 by larpinq's `registration-payments-through-shillinq`
change, the second of the smaller asks.

## Why

When an object payment request is captured, shillinq books the receipt and
the `paymentReceived` notification tells the finance group and the object's
managers. The person who paid hears nothing. Larpinq's players, and a
citizen paying leges, expect a receipt in their mail.

## What changes

- A new `PaymentReceiptMailer` mails the debtor (`debtor.email`) a plain
  receipt: what was paid, the amount, the date and the reference.
- `PaymentReconciliationService` sends it once, after it has booked and
  saved a captured object request. A capture that lands in
  `captured_unapplied` sends nothing, and a replayed webhook is a no-op, so a
  debtor gets one receipt.
- A failed mail is logged, never thrown: the payment stays booked.

## Not in this change

Requests on an invoice keep their invoice flow; no receipt is mailed for
them here. Requests without a debtor email (a `customerMasterId` only) get no
mail. No schema change.
