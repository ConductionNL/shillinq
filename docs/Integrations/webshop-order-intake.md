---
sidebar_position: 30
title: Web shop order intake
description: The contract a web shop connector follows when it writes orders into Shillinq, so each order becomes one sales invoice.
---

# Web shop order intake

Shillinq turns orders from a web shop into sales invoices. It does not connect
to the shop itself. A connector in integriq reads the orders from WooCommerce,
Shopify or another platform and writes each one into Shillinq's register as a
`WebshopOrder` object. This page is the contract that connector follows.

There is no separate endpoint. The connector writes objects into register
`shillinq`, schema `WebshopOrder`, the same way it writes any other target
object.

## Before the first order: the channel

Every connected shop needs one `WebshopChannel` in Shillinq. It holds what the
shop does not know but the invoice needs:

| Field | Meaning |
| --- | --- |
| `name` | The shop's name, such as its domain. |
| `platform` | `woocommerce`, `shopify`, `lightspeed`, `ccvshop`, `magento` or `other`. |
| `administrationId` | The administration the shop's orders are booked in. |
| `consumerDebtorId` | The customer that collects all consumer orders (a collective debtor, such as "Webwinkel particulieren"). |
| `revenueAccount` | The revenue account for the order lines. |
| `shippingAccount` | The revenue account for the shipping charge. |
| `clearingAccount` | The account a paid order is settled on, such as the payment provider's clearing account. |
| `issueMode` | `auto-issue` issues the invoice at once; `draft-for-review` leaves it as a draft. |

An order for a channel Shillinq does not know is refused.

## The order

One `WebshopOrder` per shop order. Write the order as the shop sent it.

| Field | Required | Meaning |
| --- | --- | --- |
| `channelId` | yes | The id of the `WebshopChannel`. |
| `shopOrderId` | yes | The shop's own order number. |
| `orderedAt` | yes | When the order was placed (ISO 8601 date-time). |
| `buyer` | yes | `type` (`business` or `consumer`), `name`, `email`, `companyName`, `vatId`, `kvkNumber`, `address`, `countryCode` (two letters). |
| `currency` | yes | ISO 4217 code, such as `EUR`. |
| `pricesIncludeVat` | no | `true` (the default) when prices and totals include VAT. |
| `lines` | yes | One entry per order line: `sku`, `description`, `quantity`, `unitPrice`, `vatRate` (percent). |
| `shipping` | no | The shipping charge. |
| `discountTotal` | no | The order discount. |
| `totals` | yes | `net`, `vat` and `gross` as the shop calculated them. |
| `payment` | yes | `status`, `method` and `providerPaymentId`. |
| `administrationId` | no | The channel's administration. |

Do not write `intakeState`, `arInvoiceId`, `creditNoteId` or `refusalReason`.
Shillinq sets them.

### The idempotency key

The key is the pair `channelId` + `shopOrderId`, not the object id. If the
connector writes the same shop order twice, even under a new object id, the
second record is marked `duplicate` and no second invoice is written.

### Payment status

| `payment.status` | What Shillinq does |
| --- | --- |
| `paid` | Issues the invoice and settles it on the channel's clearing account. `providerPaymentId` is kept on the invoice for matching the payout. |
| `pending` | Issues the invoice and leaves it open. |
| `refunded` | On an invoiced order: writes a credit note. |
| `cancelled` | On an invoiced order: writes a credit note. |

To report a refund or cancellation, update the existing order's
`payment.status`. Do not write a new order.

## What happens to an order

Shillinq keeps its own state in `intakeState`:

| State | Meaning |
| --- | --- |
| `received` | Written by the connector, not yet handled. |
| `invoiced` | One sales invoice was written; `arInvoiceId` points to it. |
| `refused` | Not invoiced; `refusalReason` says why. The bookkeeper gets a notification. |
| `duplicate` | The channel already had an order with this number. |
| `credited` | Refunded or cancelled after invoicing; `creditNoteId` points to the credit note. |

An order is refused when its channel is unknown, when it has no lines, or when
its lines do not add up to the order total. The reason names both totals.
