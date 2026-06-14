---
status: draft
---

# Rate Card Engine (multi-tier, effective-dated)

## Placement & Information Architecture

**Placement type:** `SETTING+DETAIL_TAB` (compound — implement all of the following):

- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.
- **`DETAIL_TAB`** — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Beheer / Rate Cards (settings) + Verkoop / Contracten → Rate-card tab

**Rationale:** Multi-tier effective-dated cards.  
_Source: /tmp/ia-shillinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Multi-tier rate hierarchy (user / role / project / client / blended); effective-dated rates.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 21/26 competitors
- **Dependencies:** none

## Competitor Evidence (from intelligence-db)

- anuko-time-tracker :: Per-user per-project rates :: Rate-card basics
- bigtime :: Multi-tier rate cards :: Per-employee, per-role, per-client, per-project, blended rates
- clio :: Rate per attorney per matter per client :: Multi-tier legal rate cards
- clockify :: Hourly rates project user task client :: Rate at any level; most specific wins
- deltek-maconomy :: Rate cards per role per project per client :: Enterprise rate card hierarchy with overrides
- everhour :: Bill rates per user per project :: Rate hierarchy with role-based defaults
- harvest :: Per-task and per-person hourly rates :: Default rate per task, override per assigned user
- hubstaff :: Pay rates and bill rates separately :: Pay rate for payroll; bill rate for invoicing
- kantata :: Rate cards per role per client :: Multi-tier rate cards by role, level, client
- kimai :: Per-user per-project per-activity rates :: Rate hierarchy: user-activity > user > activity > project
- moneybird :: Hourly rates per project :: Default and per-project rates
- replicon :: Multi-tier rate cards :: Role, person, project, client overrides
- tempo-timesheets :: Rate cards per role per account :: Tempo Cost Tracker add-on for cost rates
- timecamp :: Rate cards per user per task :: Billable and cost rates configurable
- timely :: Hourly bill rates per project user :: Standard rate-card configuration
- toggl-track :: Per-project and per-workspace rates :: Billable rates at project, user, or workspace level
- yuki :: Hourly rates per project per employee :: Standard rate cards

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 17 competitor implementations. See `/tmp/shillinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
