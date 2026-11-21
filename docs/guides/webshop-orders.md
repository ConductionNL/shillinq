---
sidebar_position: 12
title: Invoice web shop orders
description: Connect a web shop through integriq so every order becomes a sales invoice in Shillinq, and read the orders Shillinq refused.
---

# Invoice web shop orders

Shillinq turns the orders of your web shop into sales invoices without anyone
typing them. The shop is connected through **integriq**; Shillinq receives
each order, writes and issues the invoice, settles it when the order is paid,
and writes a credit note when the order is refunded or cancelled.

## Connect a shop

1. **Add the channel in Shillinq.** Open the settings gear, choose
   **Web shop channels** and click **New web shop channel**. Fill in:
   - **Name** and **Platform** (WooCommerce, Shopify, Lightspeed, ...).
   - **Administration**: where the shop's orders are booked.
   - **Consumer debtor**: one customer that collects every consumer order,
     such as "Webwinkel particulieren". Consumers do not each become a
     customer; their name stays on the invoice.
   - **Revenue account**, **Shipping account** and **Clearing account**. The
     clearing account is where paid orders wait for the payout of your
     payment provider, such as "Tussenrekening Mollie".
   - **Issue mode**: *auto-issue* issues every invoice at once;
     *draft-for-review* leaves it as a draft for you to check first.
2. **Connect the shop in integriq.** Set up the connector for your shop
   platform and point it at the channel you just made. The connector writes
   each order into Shillinq; the fields it fills are described in
   [Web shop order intake](../Integrations/webshop-order-intake.md).

From then on every new order appears under **Sales → Web shop orders**.

## What Shillinq does with an order

| The order is | Shillinq |
| --- | --- |
| New | Writes one sales invoice: a line per order line and one for shipping. |
| Paid | Settles the invoice on the clearing account and keeps the payment id for the payout match. |
| Pending | Leaves the invoice issued and open. |
| Refunded or cancelled | Writes a credit note referencing the invoice, for the whole order or for the lines the shop refunded. |
| Sent twice | Marks the second one *Duplicate*; no second invoice. |

### The debtor

A **business** buyer is looked up among your customers by VAT id, then KvK
number, then email address. When none matches, Shillinq creates the customer
and marks it as created by the channel, so you can review it. A **consumer**
is booked on the channel's consumer debtor.

### VAT

- A buyer in the Netherlands is invoiced at the rate the shop charged.
- A consumer in another EU country is invoiced at **that country's rate**
  through OSS; the invoice is marked for the OSS return.
- A business in another EU country whose VAT id the VIES check confirms is
  invoiced under **reverse charge**, with the mention *Btw verlegd*. Without
  a confirmed VAT id the business pays that country's rate, like a consumer.
- When prices include VAT, the invoice total stays what the buyer paid.

## Read refused orders

Open **Sales → Web shop orders** and choose the **Refused** filter. Each
refused order shows the reason, and you get a notification when one is
refused. Shillinq refuses an order rather than invoice it wrongly when:

| Reason | What to do |
| --- | --- |
| The channel is unknown | Check that the connector points at an existing web shop channel. |
| The order has no lines | Check the order in the shop. |
| The lines do not add up to the order total | The reason shows both totals. Correct the order in the shop or the connector mapping. |
| No VAT route | The buyer is outside the EU, or no VAT rate of the destination country is in force for the order date. Invoice this order by hand. |
| No consumer debtor | Set the consumer debtor on the channel. |

Open an order to see it as the shop sent it, with links to its invoice and
credit note.
