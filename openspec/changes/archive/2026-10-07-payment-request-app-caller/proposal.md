---
kind: code
depends_on: [case-payment-requests]
---

# Proposal: payment-request-app-caller

Decided by Ruben on 2 Oct 2026 (build-all DECISIONS row 47), asked in
shillinq#1836 by larpinq's `registration-payments-through-shillinq` change.

## Why

`PaymentRequestLeafProvider::create()` raises a request only for a signed-in
user who carries `payment.request` (REQ-SOPR-003). That is right for a clerk
raising leges by hand. It refuses two of the three ways larpinq accepts an
event registration: a player taking a free place (a player must not carry
`payment.request`, it would let them raise any request on any object) and the
oldest waitlisted registration moving up (often larpinq's daily job, with no
user at all). Those requests wait until a game master presses "Request
payment".

## What changes

- An administrator may grant a payment action to an APP in app config,
  `paymentActionApps`, for example `{"payment.request": ["larpinq"]}`.
- The leaf gains `createAsApp(appId, register, schema, objectId, payload)`.
  It raises the same request as `create`, refused unless the app is named
  for `payment.request` and enabled. The request records `requestedBy:
  "app:<appId>"`. It reads and writes as the system, because there may be no
  user.
- `create` is unchanged: the user path, its 403 and its requester stay as
  they are, and no payload key can claim an app.

## Not in this change

The four smaller asks in shillinq#1836 (match a bank line to an object
request by reference, a receipt mail to the debtor, an invoice on request, a
`requestType` for event fees) are queue items. Larpinq's refund and credit
events are not decided.
