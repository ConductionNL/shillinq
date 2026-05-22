---
status: draft
---

# Booking Cancellation Policy

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Verkoop / Afspraken → Annulering settings

**Rationale:** Cancellation policy config.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Min-notice, reschedule windows, cancellation reasons, fees.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 14/21 competitors
- **Dependencies:** bookings-create-appointment

## Competitor Evidence (from intelligence-db)

- booksy :: No-Show Protection :: Card hold + cancellation fee policy
- fresha :: No-Show Card Capture :: Hold card details, charge no-show fee
- mindbody :: Late Cancellation Fees :: Auto-charge late-cancel and no-show fees
- thefork :: Credit Card Guarantee :: Card-on-file to deter no-shows
- treatwell :: No-Show Protection :: Card capture to charge no-show fees

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 5 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
