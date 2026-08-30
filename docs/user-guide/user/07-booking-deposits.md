---
sidebar_position: 7
title: Collect a deposit at booking time
description: Configure deposit rules per booking type, take a partial or full prepayment via Mollie or Stripe, and manage authorized, failed, and refunded deposits.
---

# Collect a deposit at booking time

Take a partial or full prepayment from a customer the moment a booking is
confirmed. The deposit is collected through a payment gateway (Mollie or Stripe,
routed by OpenConnector), and Shillinq materialises an accounts-receivable
invoice for the deposit so it flows straight into your books.

## Goal

Reduce no-shows and chargeback risk by securing a deposit up front, with the
invoice and refund handling done for you.

## How deposits work

A deposit is stored as a **DepositPayment** record. It moves through a small
lifecycle:

| State | Meaning |
| --- | --- |
| Draft | Created, no payment requested yet. |
| Pending | A payment link was sent; awaiting the customer and the gateway. |
| Authorized | The payment cleared. An AR invoice is created automatically. |
| Captured | Funds settled into your account. |
| Failed | The gateway rejected the payment. The reason is shown for follow-up. |
| Voided | The booking was cancelled and the deposit refunded; a credit note reverses the invoice. |

Shillinq never stores card numbers or tokens. Only the gateway's opaque payment
reference is kept on the record.

## Configure a deposit rule

Deposit rules are set per **booking type** (for example "Studio Portrait
Session"). A rule defines:

- **Type** — `percentage` (for example 50% of the booking price) or `fixed` (a
  set amount in euro cents).
- **Due offset days** — how many days before the event the deposit is due. If the
  event is sooner than that, the deposit is due immediately.
- **Refund policy** — `automatic_on_cancellation` to refund the moment a booking
  is cancelled, or `operator_approval` to queue a refund request for review.

When a booking with a deposit rule is confirmed, Shillinq computes the deposit
amount, the VAT split (21% standard NL rate by default), and the due date, then
creates the DepositPayment and the customer payment link.

## Take a payment

1. Confirm the booking. Its state becomes **Pending payment**.
2. The customer receives a confirmation e-mail with a **Complete payment** link.
3. The customer pays via iDEAL (Mollie) or hosted checkout (Stripe).
4. The gateway confirms asynchronously. Shillinq marks the deposit **Authorized**,
   creates the AR invoice, and advances the booking to **Confirmed**.

If a confirmation webhook is delayed or lost, a background reconciliation runs
every five minutes and checks the gateway directly, so a paid deposit never
stays stuck on **Pending**.

## Manage deposits

The **Deposits** overview lists every deposit with its booking, amount, state,
gateway, and due date. Filter by state to find:

- **Failed** deposits — the error reason is shown so you can ask the customer to
  retry with another method.
- **Pending** deposits past their due date — candidates for a reminder.

On a booking's detail view, the **Deposit** widget shows the current state, the
amount, the payment link (while pending), and a link to the AR invoice.

## Cancel and refund

When you cancel a booking that has an authorized or captured deposit:

- With **automatic** refund policy, Shillinq initiates the refund through the
  gateway and creates an AR **credit note** that reverses the deposit invoice.
- With **operator approval**, a refund request is queued for you to approve first.

The customer is notified that the refund was started.
