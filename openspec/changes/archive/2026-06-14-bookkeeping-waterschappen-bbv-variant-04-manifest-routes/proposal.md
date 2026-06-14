---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-03-validation-rules]
chain:
  - bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed
  - bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance
  - bookkeeping-waterschappen-bbv-variant-03-validation-rules
  - bookkeeping-waterschappen-bbv-variant-04-manifest-routes
  - bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets
  - bookkeeping-waterschappen-bbv-variant-06-mapping-index
  - bookkeeping-waterschappen-bbv-variant-07-mapping-detail
  - bookkeeping-waterschappen-bbv-variant-08-compliance-service
  - bookkeeping-waterschappen-bbv-variant-09-fiscal-audit
  - bookkeeping-waterschappen-bbv-variant-10-i18n
  - bookkeeping-waterschappen-bbv-variant-11-testing
  - bookkeeping-waterschappen-bbv-variant-12-docs-quality
---

# Proposal: bookkeeping-waterschappen-bbv-variant-04-manifest-routes

Member 4 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-03-validation-rules`. Successor:
`bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets`.

This `kind: code` member wires the **navigation and routing skeleton**:
two manifest navigation entries (BBV Compliance Dashboard + Budget
Mapping) and the `appinfo/routes.php` registrations + thin controller
endpoints that the dashboard (05) and mapping UI (06/07) bind to.

## Why

The dashboard widgets and the mapping pages need reachable routes +
manifest entries before they have anything to render. Building the
navigation + route skeleton first gives members 05–07 a stable mount
point and keeps each later member focused on its component, not on
plumbing. Routes are registered only in `appinfo/routes.php` (ADR-016)
with explicit auth attributes (ADR-005, hydra-gate-route-auth).

## What Changes

- Add the BBV Compliance Dashboard navigation entry to
  `src/manifest.json` (title, icon, order after the main dashboard).
- Add the Budget Mapping navigation entry + its index/detail pages to
  `src/manifest.json`.
- Register routes in `appinfo/routes.php`:
  `GET /bbv-dashboard`, `GET /budget-mappings`,
  `GET /budget-mappings/:id` — each with the correct auth attribute.
- Add the thin `DashboardController::index()` endpoint returning the
  widget-data envelope (data wired in member 05).

## Out of Scope (this member)

Dashboard widget components (05), mapping index (06), mapping detail +
pickers (07), compliance service (08), fiscal/audit (09), i18n (10),
tests (11), docs (12).
