---
kind: code
depends_on: [case-payment-requests]
---

# Proposal: payment-request-event-fee-type

Asked in shillinq#1836 by larpinq's `registration-payments-through-shillinq`
change, the fourth of the smaller asks.

## Why

An object payment request carries a `requestType`, and the type picks the
revenue account the receipt books against (`paymentRevenueAccounts`,
REQ-SOPR-002). Larpinq asks for event fees and has no type for them. It uses
`other` today, so an administrator cannot book event fees to their own
account, and the panel and reports cannot tell them from anything else that
is `other`. `contribution` is the school contribution, not an event fee.

## What changes

- `PaymentRequest.requestType` gains `eventFee`: the fee for taking part in
  an event (a larp, a course day, a camp).
- The validator accepts it on an object request, and the one-open-request
  rule holds per type as before.
- An administrator maps it like the others, for example
  `{"eventFee": "8050"}` in `paymentRevenueAccounts`. Unmapped, a captured
  event fee waits in `captured_unapplied`, as every unmapped type does.

## Not in this change

Larpinq keeps `other` until it moves to `eventFee`; both stay valid. The
other asks in shillinq#1836 are their own changes.
