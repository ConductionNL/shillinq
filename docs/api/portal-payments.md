# Portal payments API

**Endpoint:** `POST /index.php/apps/shillinq/api/portal/payments/initiate`
**Spec:** [`portal-payment-initiation`](../../openspec/specs/portal-payment-initiation/spec.md) and [`arinvoice-lines-and-portal-amounts`](../../openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md), REQ-SPPI-007 and REQ-SPPI-008. The contract is in [contract.md](../../openspec/changes/arinvoice-lines-and-portal-amounts/contract.md).
**Consumer:** portaliq, server to server.

## What a customer sees

In the portal a customer finds three things from shillinq:

- **My invoices**: their own invoices with the amount, the lines and the status.
- **Pay my invoices**: the payment requests on those invoices.
- **My payment requests**: requests that stand without an invoice, such as leges
  on a case. Each open one has a pay button.

## Authentication

Portaliq sends the signed `X-Portal-Subject` assertion. It is the only
credential. The audience must be `customer` or `parent`.

## Request

Pay an invoice:

```json
{ "invoiceId": "00000000-0000-0000-0000-000000000000" }
```

Pay a request without an invoice (the `pay-request` row action sends this):

```json
{ "paymentRequestId": "00000000-0000-0000-0000-000000000000" }
```

When both are sent, the invoice wins.

## Response

- `200 {"checkoutUrl": "..."}`: send the person to the checkout.
- `401` without a valid assertion.
- `403` when the target is not theirs, not open, or missing. One answer for every reason.
- `502` when OpenRegister or the provider fails, or the request has no amount to charge.
- `503 {"status": "deferred"}` when the payment provider is switched off.

The amount always comes from shillinq: the invoice's gross amount, or the
request's own amount. The client never sends one.

## How a request without an invoice reaches the portal

The request carries its debtor's customer in `customerId`. The leaf API and the
leges intake set it when they raise the request, from `debtor.customerMasterId`.
A repair step sets it on requests raised before this existed. A request whose
debtor is only a name and an email has no customer, so it stays out of the
portal; send its payment link by mail instead.

## The pay buttons in the portal

Portaliq shows a Pay now button on a row only for an action that says which body
key carries the row's id (`rowField`) and which rows may pay (`rowWhen`).

| Collection | Action | `rowField` | Shown while |
|---|---|---|---|
| My invoices (`salesInvoices`) | `pay` | `invoiceId` | `lifecycleState` is issued, partially-paid or overdue |
| My payment requests (`requestPayments`) | `pay-request` | `paymentRequestId` | `state` is pending |

Pay my invoices (`paymentRequests`) has no button: its rows are payment
requests, and their invoice is paid from My invoices. On a parent's invoice
cards the portal shows `invoiceNote`, the voluntary sentence, as a notice.

## Where the checkout sends the payer back

Set the portal's address under **Administration settings > Shillinq > Portal
return address**, or through the settings API:

```bash
curl -u admin -X PUT -H 'Content-Type: application/json' \
  -d '{"portal_payment_redirect_url": "https://portaal.gemeente.example/betalen"}' \
  https://nextcloud.example/index.php/apps/shillinq/api/settings
```

Only an absolute `https` address is accepted; anything else answers 400 and
nothing is stored. Empty sends the payer to the Nextcloud start page.
