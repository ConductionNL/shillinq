---
status: draft
---

# Booking SMS Reminder Channel

## Placement & Information Architecture

**Placement type:** `SETTING+ACTION` (compound — implement all of the following):

- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.
- **`ACTION`** — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Beheer (SMS gateway) + "SMS-herinnering" action on afspraak

**Rationale:** Channel config + per-booking action.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

SMS via configurable provider (MessageBird, Twilio) through openconnector.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 17/21 competitors
- **Dependencies:** bookings-notification-triggers

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
