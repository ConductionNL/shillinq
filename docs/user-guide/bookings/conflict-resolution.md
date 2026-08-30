---
title: Conflict resolution
sidebar_position: 4
---

# Conflict resolution

The shillinq booking engine is designed around the most important rule
in any scheduling system: **a resource can only be in one place at one
time**. Whenever a new booking would overlap an existing non-cancelled
booking on the same resource, the API returns HTTP 409 Conflict and the
UI shows a confirmation dialog before letting you override.

## How overlaps are detected

Two bookings on the same resource conflict if and only if their time
intervals overlap. The check uses a half-open comparison:

```
existing.startTime < proposed.endTime  AND  existing.endTime > proposed.startTime
```

So two bookings that touch but don't overlap — for example **10:00 to
10:30** and **10:30 to 11:00** — are perfectly fine. Bookings whose
status is `cancelled` are skipped entirely; you can recycle their slot
without overriding anything.

Conflict detection runs inside the same database transaction as the
booking insert and acquires a row-level lock on the resource record. Two
operators submitting overlapping bookings at exactly the same moment
will serialise: one succeeds and the other receives the 409 response.

## What HTTP 409 looks like

```json
{
  "error":   "conflict",
  "message": "The proposed booking overlaps existing bookings on this resource.",
  "conflicts": [
    {
      "bookingId": "bk-002",
      "calendar":  "cal-001",
      "resource":  "res-001",
      "title":     "Klant: Kees Bakker",
      "startTime": "2026-05-21T11:00:00Z",
      "endTime":   "2026-05-21T11:45:00Z",
      "status":    "confirmed"
    }
  ]
}
```

The UI shows the same payload as a modal dialog listing each conflicting
booking by title and time window.

## Resolving a conflict

You have three options:

1. **Cancel and adjust** — the safest path. Close the dialog, edit the
   start/end times in the form, and try again. The calendar grid keeps
   the conflicting booking visible so you can pick an adjacent slot.

2. **Override with intent** — the dialog has a **Confirm** button. When
   you click it the form resubmits with `overrideConflict=true` and the
   API skips the conflict check. Use this when the conflict is
   intentional, e.g. a back-to-back appointment that ran long.

3. **Cancel the conflicting booking first** — open the conflicting
   booking, set its status to `cancelled`, and re-submit. Cancelled
   bookings are skipped by the conflict check, so the new booking goes
   through cleanly.

## Visual cues in the calendar

Pending bookings — including conflicts created via the override flow —
are highlighted in red in the calendar grid. Confirmed bookings appear
in the primary brand colour. This gives the operator a quick visual
pulse on the state of the day:

- All green-ish: confirmed schedule, no operator action needed.
- Reds visible: at least one booking needs an explicit decision
  (`pending` confirmation or conflict review).
- Empty cells: bookable slots, available for new appointments.

## Anti-patterns

- **Don't disable conflict checks globally.** The override path is
  per-booking by design. If you find yourself reaching for "just turn it
  off" you probably need a second calendar for the same resource (or a
  follow-up Tier-2 enhancement like staff availability templates).
- **Don't delete the conflicting booking** to "fix" the error. Set its
  status to `cancelled` instead so the audit trail keeps the historical
  context. Deleted rows are gone from history forever.
- **Don't store local times in the payload.** All API times are UTC. The
  UI does the conversion to the calendar's configured zone, including
  the DST transitions, so the database stays unambiguous.
