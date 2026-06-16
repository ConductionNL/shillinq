---
sidebar_position: 2
title: Set up resources and calendars
description: Create a bookable resource, then a calendar with a time zone and working hours, so you can start taking bookings.
---

# Set up resources and calendars

Before you can take bookings you need at least one resource and a calendar bound to it.

## Goal

By the end you will have created a resource and a calendar, ready to accept bookings.

## Prerequisites

- A Nextcloud account on an instance where **Shillinq** is installed and enabled.
- The **OpenRegister** app installed and enabled — the booking module stores resources, calendars, and bookings in OpenRegister.
- The Shillinq register and schemas imported by an admin (see [Manage Shillinq settings](../admin/03-admin-settings.md)).

## Create a resource

1. Open **Shillinq → Bookings → Resources**.
2. Create a new resource and fill in:
   - **Type** — one of `staff`, `room`, `equipment`, `furniture`, or `other`.
   - **Name** — for example "Jan Peeters" or "Vergaderruimte A".
   - **Organization** — the organization that owns the resource.
   - **Status** — `active` to make it bookable.
3. Save. The resource appears in the list.

## Create a calendar

1. Open **Shillinq → Bookings → Calendars**.
2. Create a new calendar and fill in:
   - **Resource** — the resource this calendar belongs to.
   - **Time zone** — an IANA identifier such as `Europe/Amsterdam`. Booking times are stored in UTC and displayed in this zone.
   - **Working hours** — optional per-weekday template (for example `monday: 09:00-17:00`). Leave empty for 24/7 availability.
   - **Status** — `active`.
3. Save.

## What you have now

A resource with at least one calendar. You are ready to [create a booking](./03-creating-bookings.md).
