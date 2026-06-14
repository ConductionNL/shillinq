---
status: draft
---

# Booking Create Appointment (single source of truth)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verkoop / Afspraken (Agenda tab)

**Rationale:** The single-source-of-truth booking flow.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Admin + customer + API booking through one canonical create flow.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 21/21 competitors
- **Dependencies:** bookings-resource-calendar, bookings-service-catalog

## Competitor Evidence (from intelligence-db)

- acuity-scheduling :: Multiple Staff Calendars :: Per-staff calendars, varies by tier
- acuity-scheduling :: Unlimited Appointments :: No per-appointment caps on any plan
- booksy :: Staff Calendar :: Per-staff calendar with conflict detection
- cogsworth :: Multiple Calendars :: Per-user, per-team, per-location calendars
- fresha :: Appointment Calendar :: Multi-staff calendar with drag/drop and conflict detection
- indico :: Drag-Drop Timetable :: Drag/drop conference timetable editor
- indico :: Room Booking Module :: Powerful meeting-room booking interface
- mews :: Property Management System :: Cloud PMS for hotels, hostels, serviced apartments
- mindbody :: Class Scheduling :: Group class schedules with capacity limits
- opentable :: Table Management :: Floor plan, table assignment, seating optimisation
- practice-better :: Appointment Scheduling :: Multi-practitioner schedule
- resy :: Real-Time Reservation Tracking :: Live table-state dashboard
- resy :: Table Management :: Floor plan with table assignment
- salonized :: Online Appointment Calendar :: Drag-and-drop appointment book per staff member
- thefork :: Centralised Reservations :: Bookings from Google, TripAdvisor, Michelin
- thefork :: Smart Floor Plan :: Optimise table turnover via floorplan
- treatwell :: Digital Calendar :: Multi-staff digital appointment book in browser
- vagaro :: Customisable Calendar :: Per-bookable-calendar (chair/room/staff) scheduling

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 18 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
