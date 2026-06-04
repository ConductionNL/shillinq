---
status: draft
---

# Booking Service Catalogue (duration, price, buffers)

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Verkoop / Afspraken → Diensten-catalogus tab

**Rationale:** Service catalogue inside bookings.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Services with duration, price, buffer (pre/post), prep time, category.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 21/21 competitors
- **Dependencies:** none

## Competitor Evidence (from intelligence-db)

- acuity-scheduling :: Group Scheduling :: Group classes / multi-attendee bookings (Standard+)
- bookly :: Group Bookings Add-on :: Multi-attendee bookings
- bookly :: Service Extras Add-on :: Optional add-ons per service
- bookly :: Unlimited Services :: Services with price and duration
- booksy :: Intelligent Automation :: AI suggestions for slot allocation and rebooks
- booksy :: Service Categorisation :: Services grouped by category with prices/durations
- boulevard :: Inventory :: Product stock management
- boulevard :: Precision AI Scheduling :: AI gap-optimisation, double-book during processing time
- cal-com :: Collective Scheduling :: All attendees must be available
- cal-com :: Event Types :: Define services with duration, buffer, price
- cal-com :: Round Robin :: Round-robin assignment to team members
- easy-appointments :: Services and Providers :: Manage services + providers separately
- fresha :: Inventory Management :: Retail product stock and reorder tracking
- indico :: Badge and Ticket Printer :: Print attendee badges and tickets
- mews :: Bookable Services Module :: Sell spa/restaurant/event services alongside room
- opentable :: Special Events :: Wine tastings, multi-course set menus
- practice-better :: Group Programmes :: Multi-attendee coaching groups
- pretix :: Multi-Variation Products :: Ticket variations (Early bird, VIP, etc.)
- resy :: Group Bookings :: Large-party private dining
- resy :: Tock Event Ticketing Merged :: Event ticketing post-2026 Tock merger
- salonized :: Service Catalog with Duration :: Services with price, duration, prep time, buffer time
- salonized :: Stock Management :: Track retail products sold alongside services
- treatwell :: Stock Management :: Retail product inventory tracking
- vagaro :: Class Booking :: Group class scheduling alongside 1:1
- vagaro :: Inventory Management :: Retail products + in-house + online sales

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 25 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
