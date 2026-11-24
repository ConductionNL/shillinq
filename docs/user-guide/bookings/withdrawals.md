---
sidebar_position: 6
title: Withdrawals
description: How a consumer withdraws from a booking made online within 14 days, how refunds are handled, and how to mark a service exempt.
---

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

# Withdrawals (herroepingsrecht)

A consumer who books a service online has a **right of withdrawal**: within
14 days of booking they can withdraw without giving a reason and without a
fee. Shillinq offers this for every booking made through the booking widget.

## When a booking can be withdrawn

A booking can be withdrawn when all of these hold:

- it was booked through the booking widget (a booking made at the desk or by
  phone has no right of withdrawal);
- it is still booked (pending confirmation or confirmed);
- the booked service is not marked exempt (see below);
- fewer than 14 days have passed since it was booked;
- the appointment has not started.

When one of these does not hold, the consumer is told why, and can still
cancel under the normal cancellation policy.

## How the consumer withdraws

- **From the portal.** A signed-in customer sees **Herroep de overeenkomst
  hier** (*Withdraw from contract here*) on their booking.
- **From the confirmation mail.** A consumer without a portal account finds a
  link in the confirmation mail: "Changed your mind? You can withdraw from
  this booking until …". The link opens the portal's guest page for that one
  booking. It stops working when the 14 days are over, and a link that was
  changed opens nothing.

The link is only added when the portal's guest page is set up: an
administrator sets the app value `withdrawal_link_page_url` to the https
address of that page.

## What happens on a withdrawal

1. The appointment is cancelled with reason **withdrawal** and **no
   cancellation fee**, whatever the cancellation policy says.
2. Everything paid for the booking is refunded in full. A paid deposit is
   refunded through the payment provider. When the provider cannot refund
   automatically, the refund is recorded as **due** with a deadline 14 days
   after the withdrawal, and the booking operators are notified so they can
   pay it by hand. The deposit's invoice is named on the withdrawal so it can
   be credited.
3. The consumer receives an e-mail acknowledging the withdrawal, with the
   date and time it was received.

Every withdrawal is kept as a **Withdrawal** record: when it was asked for,
through which channel (portal or link), when the acknowledgement was sent,
the refund amount and its status (none, initiated, due or failed).

## Marking a service exempt

Some services have no right of withdrawal, for example a leisure service on
a specific date such as a workshop on 12 October. To mark one:

1. Open the service under **Sales → Services**.
2. Turn on **Withdrawal exempt**.
3. Fill in **Withdrawal exemption reason**, for example *leisure service on a
   specific date*. The consumer sees this reason.

An exemption without a reason does not count: the service stays
withdrawable until a reason is filled in.
