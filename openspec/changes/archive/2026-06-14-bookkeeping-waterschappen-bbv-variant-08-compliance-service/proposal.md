---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-07-mapping-detail]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-08-compliance-service

Member 8 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-07-mapping-detail`. Successor:
`bookkeeping-waterschappen-bbv-variant-09-fiscal-audit`.

This `kind: code` member implements the `ComplianceService` +
`DashboardController` data binding that backs the dashboard route from
member 04 with the aggregation from member 02 — including the caching
and invalidation the declarative aggregation cannot express on its own.

## Why

The declarative aggregation (member 02) computes the values, but the
dashboard route needs a controller that assembles the per-programme
widget envelope, caches it (TTL 1h, invalidated on GL transaction
write), and exposes `computeComplianceStatus($programme)` for any path
the aggregation engine cannot serve directly. This is the deliberately
thin imperative surface the giant's Phase 4 service task and Phase 2
controller task describe — kept minimal per ADR-031 (the engine does
the maths; the service only orchestrates + caches).

## What Changes

- Create `lib/Service/ComplianceService.php` with
  `computeComplianceStatus($programme)` returning
  `{utilization, status, budget, ytdSpend}`, reading the member-02
  aggregation and the member-01 registers.
- Add result caching (TTL 1h) with invalidation on GL transaction
  create/update.
- Create `src/Dashboard/BBVComplianceWidget.php` (controller) that
  queries the registers and returns the JSON widget envelope consumed
  by member 05's dashboard.

## Out of Scope (this member)

Fiscal scoping + audit (09), i18n (10), tests (11), docs (12).
