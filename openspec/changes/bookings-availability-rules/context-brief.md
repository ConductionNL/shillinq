---
status: draft
---

# Booking Availability Rules (hours, breaks, holidays)

## Purpose

Per-resource working hours, breaks, holidays, vacation, blackout dates.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 17/21 competitors
- **Dependencies:** bookings-resource-calendar

## Competitor Evidence (from intelligence-db)

- cal-com :: Buffer + Min-Notice :: Pre/post buffer + min notice time
- cogsworth :: Booking Window Limits :: Min/max lead time controls
- cogsworth :: Buffer Times :: Pre/post buffer settings
- easy-appointments :: Customizable Booking Rules :: Cancellation period, advance booking limits
- easy-appointments :: Working Plans :: Per-provider working hours and break definitions
- resy :: Customisable Booking Parameters :: Cover counts, time slots, prep time per service
- salonized :: Working Hours and Holidays :: Per-staff schedules with breaks, holidays, vacation

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 7 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
