---
title: Web shop order intake contract
sidebar_label: Web shop order intake
description: What a shop connector writes into shillinq for each web shop order, and what shillinq does with it.
---

# Web shop order intake contract

A shop connector (integriq's web shop connectors for WooCommerce, Shopify,
Lightspeed, CCV Shop, Magento and others) hands shillinq one `WebshopOrder`
object per order the shop takes. Shillinq turns that record into one sales
invoice (`ARInvoice`) on the channel's administration. This page is the
contract the connector's mapping follows. The source of truth is the
`WebshopOrder` and `WebshopChannel` schemas in
`lib/Settings/register.d/sales-webshop-orders.json`; when this page and the
schema disagree, the schema wins.

## Before the first order: the channel

Every order names a `WebshopChannel`. A bookkeeper creates the channel in
shillinq; the connector only reads its id.

| Field | Required | Meaning |
| --- | --- | --- |
| `name` | yes | The shop's name, such as its domain. |
| `platform` | yes | One of `woocommerce`, `shopify`, `lightspeed`, `ccvshop`, `magento`, `other`. |
| `administrationId` | yes | The administration the shop's orders are booked in. |
| `consumerDebtorId` | yes | The collective debtor (verzameldebiteur) consumer orders are booked on. |
| `revenueAccount` | yes | The revenue account for the order lines. |
| `shippingAccount` | no | The revenue account for the shipping charge. |
| `clearingAccount` | yes | The payment clearing account a paid order is settled on. |
| `issueMode` | yes | `auto-issue` issues the invoice at once; `draft-for-review` leaves it a draft. |

## The order record

The connector writes these fields. Every field marked required must be
present, or OpenRegister refuses the write.

| Field | Required | Type | Meaning |
| --- | --- | --- | --- |
| `channelId` | yes | string | The `WebshopChannel` id. An order for an unknown channel is refused. |
| `shopOrderId` | yes | string | The shop's own order number, as the shop shows it to the buyer. |
| `orderedAt` | yes | date-time | When the buyer placed the order. |
| `buyer` | yes | object | The buyer, see below. |
| `currency` | yes | string | ISO 4217 code, such as `EUR`. |
| `pricesIncludeVat` | no | boolean | Whether `unitPrice`, `shipping` and `totals` include VAT. |
| `lines` | yes | array | One entry per order line, see below. |
| `shipping` | no | number | The shipping charge, with or without VAT as `pricesIncludeVat` says. |
| `discountTotal` | no | number | The order's discount total. |
| `totals` | yes | object | `net`, `vat` and `gross` as the shop computed them. The lines must add up to them. |
| `payment` | yes | object | The payment state, see below. |

### `buyer`

| Field | Meaning |
| --- | --- |
| `type` | `business` or `consumer`. |
| `name` | The buyer's name, printed on the invoice. |
| `email` | The buyer's email address. |
| `companyName` | A business buyer's company name. |
| `vatId` | A business buyer's VAT id. Matched first. |
| `kvkNumber` | A business buyer's KvK number. Matched second. |
| `address` | The invoice address, printed on the invoice. |

A business buyer is matched to an existing customer of the channel's
administration by VAT id, else KvK number, else invoice email; when none
matches, shillinq creates the customer and marks it as created by the channel.
A consumer order is booked on the channel's collective debtor, with the
buyer's name and address on the invoice.

### `lines[]`

| Field | Meaning |
| --- | --- |
| `sku` | The shop's article code. |
| `description` | The line text, printed on the invoice. |
| `quantity` | The ordered quantity. |
| `unitPrice` | Price per unit, with or without VAT as `pricesIncludeVat` says. |
| `vatRate` | The VAT rate in percent the shop applied to this line. |

The shipping charge takes the rate of the goods it ships; on an order mixing
rates it is split pro rata over those rates.

### `payment`

| Field | Meaning |
| --- | --- |
| `status` | One of the values below. |
| `method` | The payment method, such as `ideal`. |
| `providerPaymentId` | The payment provider's id, kept on the invoice so the payout can be matched. |

| `status` | What shillinq does |
| --- | --- |
| `paid` | Invoices the order and settles it on the channel's clearing account. |
| `pending` | Invoices the order; it stays open until a later write reports `paid`. |
| `refunded` | Writes a credit note for an invoiced order. |
| `cancelled` | Writes a credit note for an invoiced order. |

A change of payment status is sent as an update of the same order record.

## Idempotency

The idempotency key is the pair `channelId` + `shopOrderId`, never the id of
the intake record. A connector that sends the same order again, also under a
new record id, gets no second invoice: the second record is marked
`duplicate`. Retrying a write is therefore always safe.

## Fields the connector never writes

These are shillinq's own and are set by its lifecycle; the connector leaves
them out.

| Field | Meaning |
| --- | --- |
| `intakeState` | `received`, `invoiced`, `refused`, `duplicate` or `credited`. A new record starts as `received`. |
| `administrationId` | Taken from the channel. |
| `arInvoiceId` | The invoice written for the order. |
| `creditNoteId` | The credit note written on refund or cancellation. |
| `refusalReason` | Why the order was refused. |

## Refusals

An order shillinq cannot invoice is moved to `refused` with a
`refusalReason`, and the administration's bookkeepers get a notification.
Shillinq refuses, rather than guesses, when:

- the channel is unknown;
- the lines, shipping and discount do not add up to `totals` (the reason names both totals);
- the VAT route cannot be determined: shillinq never falls back to the Dutch rate.
