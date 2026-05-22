---
status: draft
---

# Booking Confirmation Flow

## Placement & Information Architecture

**Placement type:** `ACTION` — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Verkoop / Afspraken → "Bevestig afspraak"

**Rationale:** Confirmation action.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Customer confirmation, ICS email attachment, calendar link.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 19/21 competitors
- **Dependencies:** bookings-create-appointment

## Cross-app integration

Uses openconnector for email/calendar invite.

## Competitor Evidence (from intelligence-db)

- acuity-scheduling :: Client Self-Scheduling :: Public booking page with brand customisation
- acuity-scheduling :: Custom Branding :: Logo, colours, CSS on booking page
- acuity-scheduling :: Embeddable Widget :: JS widget for any website
- acuity-scheduling :: Timezone-Aware Booking :: Auto timezone conversion for cross-region bookings
- bookly :: Embedded Forms :: Multiple booking forms per site
- booksy :: 24/7 Online Booking :: Clients book any time via Booksy app or salon page
- booksy :: Marketplace App :: Consumer Booksy app with 330K+ pros
- boulevard :: Online Booking :: Branded online booking with real-time alerts
- cal-com :: Custom Branding :: White-label on Teams/Org plan
- cal-com :: Embeddable Widget :: Inline, popup, floating button embeds
- cogsworth :: Custom Booking Pages :: Branded booking pages per service
- cogsworth :: Timezone-Aware Booking :: Auto-detect/convert timezones for global bookings
- cogsworth :: Whitelabel :: Full white-label for agencies
- easy-appointments :: Customer Self-Booking :: Customer-facing booking page
- fresha :: Branded Online Booking :: White-label booking page with salon brand
- fresha :: Marketplace Distribution :: Listed in Fresha consumer marketplace globally
- indico :: Newdle Meeting Scheduler :: Doodle-style meeting time picker
- indico :: Public Event Page :: Public event listings with registration
- mews :: Online Check-in / Check-out :: Self-service guest journey
- mindbody :: Consumer Marketplace App :: 3.5M+ MAU consumer app for class discovery
- opentable :: Direct Online Booking :: Embeddable widget on restaurant site, no cover fee on Pro
- opentable :: OpenTable Diner Network :: Consumer marketplace of 60K+ restaurants
- practice-better :: Client Portal :: Client login for self-service booking + chat
- practice-better :: Custom Branding :: White-label client portal
- resy :: AmEx Diner Network :: Surfaces in AmEx Resy consumer app

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
