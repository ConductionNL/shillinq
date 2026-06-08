---
sidebar_position: 3
title: Create a booking
description: Open a resource calendar, pick a slot, fill in the booking form, and confirm the appointment.
---

# Create a booking

With a resource and calendar in place, you can book appointments from the calendar view.

## Goal

By the end you will have created a confirmed booking on a resource's calendar.

## Prerequisites

- A resource with an active calendar (see [Set up resources and calendars](./02-setup.md)).

## Open the calendar

1. Open **Shillinq → Bookings → Calendars** and open the calendar you want to book on.
2. The calendar grid opens. Switch between **Month**, **Week**, and **Day** with the buttons in the toolbar.
   - Existing bookings appear in their time slots.
   - Conflicting bookings (those with `pending` status that overlap another) are highlighted in red.

## Create the booking

1. Click an empty slot, or open the booking form from the calendar.
2. Fill in:
   - **Title** — for example "Klant: Anna de Wit".
   - **Start time** and **End time** — the form requires the end to be after the start and the duration to be at least 15 minutes.
   - **Attendee** — the person the appointment is for.
   - **Status** — `pending` while unconfirmed, or `confirmed` once locked in.
3. Click **Create Booking**.

The times you enter are converted to UTC for storage and shown back to you in the calendar's time zone.

## Result

The booking appears on the calendar at its slot. If the slot overlapped an existing booking, see [Resolve a conflict](./04-conflict-resolution.md).

## API reference

The form uses these endpoints:

- `GET /apps/shillinq/api/v2/calendars` — list calendars (optional `?resource=` filter).
- `GET /apps/shillinq/api/v2/calendars/{calendarId}` — a single calendar.
- `GET /apps/shillinq/api/v2/calendars/{calendarId}/bookings?start=&end=` — bookings in a date range, sorted by start time.
- `POST /apps/shillinq/api/v2/calendars/{calendarId}/bookings` — create a booking. Returns `201` on success or `409` when the slot conflicts.
