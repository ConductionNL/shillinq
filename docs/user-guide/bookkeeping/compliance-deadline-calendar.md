---
sidebar_position: 9
title: Compliance Deadline Calendar & Reminders
description: How BTW/ICP/VPB filing deadlines, payment runs, invoice due dates and contract opzegtermijnen appear on your Nextcloud calendar and notification bell, and how to tune the per-user category toggles.
---

# Compliance Deadline Calendar & Reminders

A bookkeeping deadline missed is a fine or a rejected filing. Shillinq
already computes every relevant date — VAT/ICP/VPB filing periods,
payment-run execution dates, invoice due dates, contract
opzegtermijnen — and publishes them where you already live: the
**Nextcloud Calendar** (as app-managed events, syncing to your phone
via CalDAV) and the **Notifications** bell.

## Goal

By the end of this guide you will know which deadline categories are
published, how to enable or disable each category for your account,
and how the reminder notifications work.

## Prerequisites

- A Nextcloud account with **Shillinq** installed and enabled.
- The Nextcloud **Calendar** app (or any CalDAV backend) for the
  calendar surface. Without one, Shillinq degrades gracefully: the
  deadlines stay visible inside the app and the publication is
  reported as `failed` in the logs — nothing else breaks.

## What gets published

| Category | Source | Deadline | Removed when |
| --- | --- | --- | --- |
| Filing deadlines (default **on**) | BTW-aangifte (VATReturn), ICP-opgaaf, VPB deadlines | Last day of the month after the period end (BTW/ICP); the VPB deadline's own date | The filing is submitted / filed |
| Payment runs (default **on**) | PaymentRun | The scheduled execution date | The run is exported / reconciled |
| Invoice due dates (default **off**, opt-in) | AR invoices | The invoice `dueDate` | The invoice is paid or written off |
| Contract deadlines (default **on**) | Contract obligations (renewal / opzegtermijn) | The obligation `dueDate` | The obligation is done or waived |

Every event carries a stable identifier (`{source}:{objectId}`), so a
changed date updates the same event instead of creating a duplicate,
and a closed source removes it. The register row remains the source of
truth — the calendar event is a read-only surface.

Invoice due dates are opt-in because a busy administration can have
hundreds of open invoices; the low-volume filing, payment-run and
contract categories are on by default.

## Section 1 — Choosing your categories

1. Open **Shillinq → Settings → Deadline calendar**.
2. Toggle each category on or off. Disabling a category immediately
   removes its events from your calendar and stops its reminders.
3. For each enabled category, set the **reminder lead time** — how
   many days before the deadline you want the notification. Defaults:
   10 days for filings, 7 days for the other categories.
4. Click **Save**.

The settings are strictly personal: they never affect what your
colleagues see, and the endpoints only ever act on your own account.

## Section 2 — The calendar

A daily background job publishes the open deadlines of your enabled
categories into your calendar. When a calendar named
`shillinq-deadlines` exists on your account it is used as the
dedicated deadline calendar; otherwise Shillinq falls back to your
first writable calendar. Tip: create a calendar with that name in the
Calendar app first if you want the deadlines kept visually separate
(and independently shareable / hideable).

Contract deadlines additionally appear the moment a contract
obligation is saved — the same bridge that creates the NC Tasks to-do
for an obligation also publishes its calendar event.

## Section 3 — Reminders

The same daily job raises **exactly one** Nextcloud notification per
deadline within your lead time — no repeat nagging on every cron run.
If a deadline moves to a new date, the reminder re-arms for the new
date. Disabled categories are never notified.

## Troubleshooting

- **No events appear.** Check that a CalDAV calendar backend exists
  (Calendar app installed) and that the category is enabled in
  **Settings → Deadline calendar**. The background job runs once a
  day; saving your settings also publishes immediately.
- **Events for closed filings linger in some clients.** Removal is
  expressed as a cancelled event (`STATUS:CANCELLED`) — most calendar
  clients hide or strike these; some show them greyed out.
- **No reminder notifications.** Reminders honour your lead time; a
  deadline further away than the lead does not notify yet. Check the
  Nextcloud notification settings for the Shillinq app as well.
