---
sidebar_position: 1
title: Down payments on an order
description: Invoice part of an order up front, book it as an advance received, and take it off the final invoice.
---

# Down payments on an order

You ask a customer to pay part of an order before you deliver. Shillinq invoices that part as a down payment and takes it off the final invoice of the order.

## Invoice a down payment

1. Open **Accounts Receivable** and choose **New down-payment invoice**.
2. Pick the customer and the order. An order kept in Shillinq brings its own totals. For an order from somewhere else, such as a quote in another app, type its reference and fill in the order's net amount per VAT rate.
3. Choose a percentage of the order or a fixed net amount, and choose **Create draft**.

Shillinq splits the down payment over the order's VAT rates in proportion. Thirty percent of an order of EUR 15,000 at 21 percent is one line of EUR 4,500 plus EUR 945 VAT. The invoice gets type code 386, the code for a prepayment invoice, and keeps the order totals it was computed from.

Check the draft and issue it like any other invoice. It is sent, dunned and paid like any other invoice too.

## How it is booked

A down payment is not revenue yet. When you issue it, Shillinq books the receivable against account 2310 Vooruitontvangen bedragen and VAT payable. Nothing goes to revenue. The final invoice books the full revenue and releases the advance from 2310.

To use another account for advances, set it once for the app:

```
occ config:app:set shillinq ledger_advances_account --value=<account number>
```

## Deduct the down payments on the final invoice

1. Draft the final invoice for the order as usual, with the full order on it.
2. On the invoice page, the **Down payments** panel lists every order of this customer with issued down payments not yet deducted. Choose **Deduct down payments** for the order.

Shillinq adds one negative line per down payment and VAT rate, at the net and VAT the down payment charged, and names each down-payment invoice as a preceding invoice. For the kitchen above, the final invoice shows EUR 15,000 plus EUR 3,150 VAT, a deduction of minus EUR 4,500 and minus EUR 945 VAT, and EUR 12,705 to pay.

A down payment that is issued but not paid yet is offered too. The panel shows which ones are paid.

## A down payment is deducted once

When the final invoice is issued, each down payment it deducts records that invoice. Another final invoice that tries to deduct the same down payment cannot be issued: Shillinq names the invoice that already deducted it. A final invoice whose deductions are more than its total cannot be issued either.

## The order's position

On a down-payment invoice or a final invoice, the **Down payments** panel lists every down payment of the order: its number, its amount, whether it is paid and the invoice that deducted it.

## The e-invoice

A down-payment invoice goes out as UBL invoice type 386. The final invoice carries the deductions as negative invoice lines at their VAT rate, and a billing reference to each down-payment invoice, so the customer's software can match them.
