---
title: Creating bookings
sidebar_position: 3
---

# Creating bookings

Once a calendar exists you can browse it in the **Verkoop →
Boekingenkalender** view. The calendar grid supports three views:

- **Month** — one cell per day. Useful for an at-a-glance overview.
- **Week** — seven columns by 24 hours, one cell per hour.
- **Day** — vertical 24-hour view for a single day.

Switch views with the buttons in the calendar header.

## Picking a slot

Click any empty cell to open the booking form. The form pre-fills the
slot's start time and adds a 30-minute window by default. You can adjust
the times freely; the form enforces the rules described below.

## The booking form

| Field | Notes |
|-------|-------|
| Title | Free text, max 255 characters. Shown in the calendar cell. |
| Start time | UTC datetime. The form uses the browser's `datetime-local` widget. |
| End time | Must be strictly after the start, and the duration must be ≥15 minutes. |
| Attendee | Free text (or a Nextcloud contact name) — max 255 characters. |
| Status | `pending` (default) or `confirmed`. |

### Status: pending vs confirmed

- **Pending** — the booking is recorded but flagged as unconfirmed. The
  calendar UI highlights it in red so an operator can review it. Pending
  bookings do not block the slot; the conflict check will report them as
  overlaps until they are either confirmed or cancelled.
- **Confirmed** — the booking is live and blocks the slot. Any future
  booking that overlaps a confirmed window will hit the 409 Conflict
  branch (see [Conflict resolution](./conflict-resolution.md)).

### Validation rules

- Title and attendee are required.
- End time must be strictly after start time.
- Duration must be at least 15 minutes.
- Start and end times are stored in UTC; the UI converts to the
  calendar's time zone for display.

## API equivalents

The calendar UI is a thin layer over the REST API. The same workflow is
scriptable:

```bash
# List all calendars
curl -u admin:secret http://nc.example/index.php/apps/shillinq/api/v2/calendars

# Read one calendar
curl -u admin:secret http://nc.example/index.php/apps/shillinq/api/v2/calendars/cal-001

# List bookings in a date range
curl -u admin:secret \
  "http://nc.example/index.php/apps/shillinq/api/v2/calendars/cal-001/bookings?start=2026-05-21&end=2026-05-31"

# Create a booking
curl -u admin:secret \
  -H "Content-Type: application/json" \
  -H "OCS-APIRequest: true" \
  -X POST \
  -d '{
    "title": "Klant: Bob Jansen",
    "startTime": "2026-05-21T14:00:00Z",
    "endTime":   "2026-05-21T14:30:00Z",
    "attendee":  "Bob Jansen",
    "status":    "confirmed"
  }' \
  http://nc.example/index.php/apps/shillinq/api/v2/calendars/cal-001/bookings
```

A successful POST returns HTTP 201 with the saved booking payload. A
conflicting POST returns HTTP 409 with the list of overlapping bookings
— see [Conflict resolution](./conflict-resolution.md).
