---
title: Withdrawals
sidebar_position: 5
description: How a consumer who booked online withdraws within 14 days, what Shillinq refunds, and how to mark a service that cannot be withdrawn from.
---

# Withdrawals

A consumer who books a service through your booking widget may withdraw from the booking within 14 days, without a fee. Shillinq checks whether a booking can still be withdrawn, cancels it at no cost, refunds what was paid and confirms the withdrawal to the consumer.

## When a booking can be withdrawn

A booking can be withdrawn when all of these are true:

- it was booked through the booking widget (a widget booking is a consumer's booking);
- it is still booked, not cancelled or completed;
- fewer than 14 days have passed since it was booked;
- the appointment has not started;
- the booked service is not exempt from withdrawal.

When one of these is not true, the consumer is told which one, and until which day the booking could have been withdrawn.

## How the consumer withdraws

- **In the portal.** A consumer with a portal account sees **Withdraw from contract here** on the booking.
- **With the link from the confirmation.** The booking confirmation e-mail and the widget's confirmation step carry a link signed for that one booking. It opens the withdrawal page of your portal and stops working when the withdrawal period ends. The consumer does not need an account.

For the link, an administrator sets the address of the portal's withdrawal page as the app setting `portal_withdrawal_url`. It must start with `https://`. Without it, no link is offered.

## What happens on a withdrawal

1. The booking is cancelled with reason **Withdrawal**. No cancellation fee is charged, whatever the cancellation policy says.
2. A paid deposit is refunded in full through the payment provider. When the provider is not connected or refuses, the refund is recorded as due within 14 days and staff must pay it.
3. The consumer gets an e-mail confirming when the withdrawal was received, that no fee is charged, and how the refund is made.
4. The booking operators are notified.

Each withdrawal is kept as a record with its channel (portal or link), the moment it was asked, the moment the confirmation e-mail left, the refund amount and its status:

| Refund status | Meaning |
|---|---|
| None | nothing was paid |
| Initiated | the payment provider started the refund |
| Due | pay it yourself by the date shown |
| Failed | the provider refused; pay it yourself |

When the confirmation e-mail could not be sent, the record has no confirmation moment. Contact the consumer yourself.

Shillinq does not raise the credit note: the withdrawal names the invoice to credit.

## Mark a service as exempt

Some services cannot be withdrawn from, for example a leisure activity on a fixed date.

1. Open the service under **Sales > Services**.
2. Switch on **Exempt from withdrawal**.
3. Fill in **Exemption reason**, for example "Leisure activity on a fixed date". The consumer sees this reason.

The exemption only counts with a reason. A service marked exempt without a reason can still be withdrawn from.
