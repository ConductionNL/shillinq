---
status: draft
---

# Booking Availability Rules (hours, breaks, holidays)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Verkoop / Afspraken → Beschikbaarheid settings (inside Afspraken page)

**Rationale:** Hours/breaks/holidays config.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

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
