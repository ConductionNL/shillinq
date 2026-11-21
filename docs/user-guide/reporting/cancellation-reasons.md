---
sidebar_position: 5
title: Cancellation reasons
description: Record why a subscription ends, let customers end their own subscription from the portal, and read which reasons cost the most revenue.
---

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

# Cancellation reasons

When a subscription (a recurring invoice profile) ends, Shillinq records
**why**, **who** ended it, when it was asked for and when it takes effect. The
**Cancellation reasons** report then shows which reasons cost the most
monthly revenue, per quarter.

## Ending a subscription

Open the subscription under **Sales → Recurring Invoices** and choose **End**.
Shillinq asks for a reason and, optionally, a note:

| Code | Meaning |
|---|---|
| too-expensive | Too expensive |
| not-using | Not using it any more |
| switched-provider | Moved to another provider |
| missing-feature | Missing a feature |
| service-quality | Unhappy with the service |
| business-closed | The business stopped |
| other | Another reason (explain in the note) |
| not-given | The customer gave no reason |

Staff must choose a reason other than *not-given*: an End without one is
refused. The cancellation takes effect the day before the next invoice would
have been made, so the customer is not billed again.

A subscription that reaches its own end (its last occurrence) ends without a
reason and is not counted as a cancellation.

## Customers ending their own subscription

A customer who is signed in to the portal sees **Mijn abonnementen** with
their running and paused subscriptions, and can end one with **Abonnement
opzeggen**. They may give a reason but do not have to; their cancellation is
recorded with *the customer* as the one who ended it.

A customer can only end a subscription that bills their own customer record.
For older subscriptions Shillinq links the customer record during the
upgrade; subscriptions it could not link are named in the upgrade log, so
staff can link them by hand.

## The report

Open **Reports** and choose **Cancellation reasons**. The page shows:

- how many subscriptions ended per quarter;
- the monthly amount (before VAT) lost per reason;
- the ended subscriptions themselves, each with its reason and note.

The lost monthly amount is what the subscription billed per month when it
ended.
