---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-10-i18n]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-11-testing

Member 11 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-10-i18n`. Successor:
`bookkeeping-waterschappen-bbv-variant-12-docs-quality`.

This `kind: code` member adds the **test matrix** across the built
capability: unit tests for the compliance service, an integration test
for the aggregation, browser tests for the dashboard + mapping CRUD +
fiscal scoping + validation, and route smoke tests (giant Phase 6).

## Why

The capability is now fully built (members 01–10); this member proves
it. Tests assert real behaviour (real GL fixtures from member 01's
scaffold, real validation rejections from member 03), per the
no-mock-fixes preference. Grouping all test authoring into one member
keeps the per-component members lean and gives one coherent coverage
pass (ADR-008).

## What Changes

- Unit-test `ComplianceService::computeComplianceStatus()` across spend
  levels, multi-account aggregation, rounding tolerance, fiscal scoping.
- Integration-test that dashboard data matches computed aggregations and
  updates as GL transactions are recorded.
- Browser-test the dashboard (4 widgets, badges, drill-in), mapping
  index (search, add, row click), mapping detail create + edit + delete,
  fiscal-year scoping, and validation/error handling.
- Smoke-test all routes respond 200 and seed data loads.

## Out of Scope (this member)

Docs + quality gates (12).
