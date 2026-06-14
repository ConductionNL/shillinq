---
status: draft
---

# Booking Deposits at Booking Time

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Verkoop / Afspraken → Aanbetalingen tab

**Rationale:** Deposit-at-booking-time.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Take partial / full payment at booking time via Mollie/Stripe.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 18/21 competitors
- **Dependencies:** none

## Cross-app integration

Uses shillinq invoice module + openconnector payment adapter.

## Competitor Evidence (from intelligence-db)

- bookly :: Deposits Add-on :: Partial-payment / deposit add-on
- bookly :: Stripe Payments :: Online payments via Stripe
- cal-com :: Payment Collection :: Stripe payments at booking
- cogsworth :: Payment Collection :: Stripe payments at booking
- mews :: Mews Payments :: Built-in payment processing
- opentable :: Pre-Payment / Deposits :: Capture deposits and prepaid experiences
- resy :: Prepayment / Deposits :: Capture deposits at booking
- thefork :: Prepayment :: Up-front payment for set menus and events
- treatwell :: Payment Collection at Booking :: Take deposits and full payments at time of booking

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 9 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
