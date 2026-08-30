---
status: draft
---

# Booking Email Templates (branded)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer (under Booking templates)

**Rationale:** Branded email templates.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Branded confirmation/reminder/cancel templates.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 21/21 competitors
- **Dependencies:** none

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
