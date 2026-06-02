---
status: draft
---

# Booking → pipelinq Customer Profile Bridge

## Placement & Information Architecture

**Placement type:** `ACTION` — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** "Bridge naar pipelinq" action on klant

**Rationale:** Cross-app sync action.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Detail view loads customer profile + history from pipelinq klantbeeld 360.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 21/21 competitors
- **Dependencies:** none

## Cross-app integration

Reads pipelinq Contact + klantbeeld; writes booking events back to pipelinq timeline.

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
