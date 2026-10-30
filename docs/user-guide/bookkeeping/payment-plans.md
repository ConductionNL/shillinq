---
sidebar_position: 10
title: Payment plans
description: Agree instalments with a customer who cannot pay overdue invoices at once, and let Shillinq follow them.
---

# Payment plans

A customer who cannot pay an overdue invoice at once can pay it in instalments. You agree the plan in Shillinq. Shillinq then pauses reminders, recognises each payment and ends the plan when an instalment is missed.

## Agree a plan

1. Open the customer, or an overdue invoice of that customer.
2. Choose **Agree a payment plan**.
3. Tick the overdue invoices the plan covers.
4. Choose a number of instalments, or an amount per instalment.
5. Set the frequency, the first due date and the grace days.
6. Tick **Include collection costs already charged** when the plan also pays those costs.
7. Choose **Draw up schedule**. Shillinq shows each instalment with its date and amount.
8. Choose **Activate plan**.

The instalments always add up to the plan total to the cent. The last instalment takes the rounding difference. For example, EUR 2,420.00 in six monthly instalments is five of EUR 403.33 and a last one of EUR 403.35.

When you activate the plan:

- reminders for the covered invoices pause;
- each invoice shows the plan that covers it;
- the customer receives a mail with the invoices, the schedule, your IBAN and the payment reference, for example RGL-2026-0007.

## Payments

Shillinq allocates every payment to the covered invoices, oldest invoice first. An invoice with nothing left due moves to paid. An amount above the instalment pays the next instalments.

A payment reaches the plan in three ways:

- **Bank feed.** A line whose description names the payment reference is booked to the plan on arrival.
- **Match by hand.** On an unmatched bank line, the **Payment plan** tab lists the plans the line fits. A line that names the reference comes first. A line of the next instalment's amount from the customer's account is offered too. You confirm it.
- **Record a payment.** On the plan page, enter the amount and the date it arrived.

## Missed instalments

Every day Shillinq checks the active plans. An instalment that is still unpaid after its grace days is marked missed, and the plan ends:

- the plan shows broken;
- reminders for its invoices resume where they paused;
- the customer receives a mail that the arrangement has ended;
- the credit controllers get a notification.

A plan whose instalments are all paid shows completed, and its invoices are paid.

## Follow your plans

**Sales > Payment plans** lists every plan with its customer, total, amount paid, arrears and next instalment. Choose **Due this month** to see the plans with an instalment this month, its amount and whether it is paid.

To stop a plan by agreement, open it and choose **Cancel plan**. Reminders for its invoices resume.

:::note
Undoing this feature by reverting the release leaves the reminder pauses of active plans in place. Resume those pauses by hand on each invoice.
:::
