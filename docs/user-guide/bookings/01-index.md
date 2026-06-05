---
sidebar_position: 1
title: Bookings overview
description: What the Shillinq booking module does, the resource-calendar-booking model, and where to find each part in the app.
---

# Bookings overview

The booking module lets appointment-driven organisations — salons, healthcare providers, coworking spaces, hospitality venues — schedule appointments against individual resources and avoid double-booking.

## Goal

By the end you will understand the three core entities (Resource, Calendar, Booking), how conflict detection prevents double-booking, and where each screen lives in the app.

## The model

The module is built from three entities:

- **Resource** — anything bookable: a staff member, a room, a piece of equipment, or furniture. Each resource has a type, a name, and an owning organization.
- **Calendar** — a per-resource calendar carrying a time zone (default `Europe/Amsterdam`) and optional working hours. Each calendar is bound to exactly one resource.
- **Booking** — a scheduled appointment on a calendar: a title, a start and end time (stored in UTC), an attendee, and a status (`pending`, `confirmed`, or `cancelled`).

## Conflict detection

When you create a booking, Shillinq checks the resource's other bookings for an overlap. Two bookings on the same resource conflict when their time intervals overlap by any amount. Adjacent bookings that merely touch (one ends exactly when the next starts) do **not** conflict. Cancelled bookings never block a slot.

If an overlap is found, the booking form shows the conflicting appointments and lets you cancel or book anyway.

## Where to find it

Open **Shillinq → Bookings** in the navigation. The group has three lists:

- **Resources** — manage your bookable resources.
- **Calendars** — manage per-resource calendars.
- **Bookings** — browse all bookings.

The calendar grid (month, week, day) and the booking form open from a calendar.

## Next steps

- [Set up resources and calendars](./02-setup.md)
- [Create a booking](./03-creating-bookings.md)
- [Resolve a conflict](./04-conflict-resolution.md)
