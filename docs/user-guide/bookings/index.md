---
title: Bookings overview
sidebar_position: 1
---

# Bookings

Shillinq's bookings module gives every staff member, room, equipment item
or piece of furniture its own calendar. Each calendar carries a time zone
and a weekly working-hours template; bookings live on the calendar and the
system actively prevents double-booking of the same resource.

The Tier-1 booking surface introduced by the
[`bookings-resource-calendar`](../../intro.md) change covers:

- **Resources** — anything you can book: staff (`staff`), rooms (`room`),
  equipment (`equipment`), furniture (`furniture`) or anything else
  (`other`).
- **Calendars** — one calendar per resource, with an IANA time zone
  (default `Europe/Amsterdam`) and an optional weekly working-hours
  template.
- **Bookings** — title, start, end, attendee, and lifecycle status
  (`pending` / `confirmed` / `cancelled`).
- **Conflict detection** — a booking that overlaps an existing
  non-cancelled booking on the same resource returns
  HTTP 409 Conflict and is highlighted in red in the calendar UI.

The next pages walk through setup, day-to-day booking, and what to do
when a conflict appears.

| Page | What it covers |
|------|----------------|
| [Setup](./setup.md) | Resources, calendars, working-hours and time-zone configuration. |
| [Creating bookings](./creating-bookings.md) | Picking a slot, filling the form, what `pending` vs `confirmed` mean. |
| [Conflict resolution](./conflict-resolution.md) | What an HTTP 409 means, when to override, when to reschedule. |
