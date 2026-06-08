---
sidebar_position: 4
title: Resolve a conflict
description: Understand what a booking conflict means, why the system blocks it, and how to resolve or override it.
---

# Resolve a conflict

Shillinq prevents double-booking the same resource. This page explains what triggers a conflict and how to resolve one.

## Goal

By the end you will know what a conflict is, how the system flags it, and your options for resolving it.

## What counts as a conflict

Two bookings on the **same resource** conflict when their time intervals overlap by any amount. For example:

- Booking A: 11:00–11:45
- Booking B: 11:15–12:00 → **conflicts** with A.

These do **not** conflict:

- Adjacent bookings that touch: A 10:00–10:30 and B 10:30–11:00.
- Bookings on **different** resources at the same time.
- A booking against a **cancelled** booking — cancelled bookings never block a slot.

All comparisons run in UTC, so a conflict is detected correctly regardless of the display time zone.

## When the system blocks a booking

When you submit a booking that overlaps an existing one, the API returns a conflict and the form opens the **Booking Conflict Detected** dialog. It lists each conflicting booking with its title and time range.

You have two options:

- **Cancel** — go back and pick a different time or resource.
- **Book anyway** — override the conflict and create the booking regardless. Use this only when you genuinely intend an overlap (for example a deliberately shared slot).

## Resolving a conflict for good

The cleanest resolutions are:

1. **Reschedule** one of the bookings to a free slot.
2. **Use a different resource** if another is available.
3. **Cancel** the booking that is no longer needed — cancelled bookings free their slot immediately.

After resolving, the red conflict highlight on the calendar clears for the affected bookings.
