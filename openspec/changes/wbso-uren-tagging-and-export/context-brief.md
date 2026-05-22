---
status: draft
---

# WBSO Uren Tagging + RVO Export

## Placement & Information Architecture

**Placement type:** `ACTION` — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Salaris / ZZP-uren → "Tag uren als WBSO" + "Export naar RVO"

**Rationale:** Pure action on uren-records.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

WBSO project + activity codes (SO, TWO etc.); auto-tag from project metadata; export WBSO-uren report in RVO-required format. **Unique NL moat — zero competitor coverage.**

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 0/26 competitors (gap)
- **Dependencies:** billable-categories-and-tags

## Cross-app integration

Links to existing shillinq/bookkeeping-wbso-sno-administratie.

## Competitor Evidence (from intelligence-db)

- moneybird :: No WBSO-specific tagging :: No native WBSO uren-export module
- yuki :: No WBSO uren-tagging native :: Project budgets but no WBSO-specific submission export

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 2 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
