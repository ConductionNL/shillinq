---
kind: config
depends_on: [bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance

Member 2 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed`.
Successor: `bookkeeping-waterschappen-bbv-variant-03-validation-rules`.

This `kind: config` member declares the **compliance aggregation** as
declarative `x-openregister-aggregations` metadata on the registers
from member 01: budget-per-programme, YTD-spend-per-programme,
utilization, and the derived compliance status — computed, not stored
(ADR-031). It carries an integration test verifying the materialised
values.

## Why

Per the giant's D3, `BBVProgramme.complianceStatus` is a computed field
derived from GL spend vs budget allocation at query time — no separate
"compliance status" table. Expressing this as an OpenRegister
aggregation isolates the engine dependency early (ADR-032): if the
cross-schema aggregation cannot be expressed declaratively, the chain
pauses here before any consumer code is written.

## What Changes

- Add an `x-openregister-aggregations` block to the `BBVProgramme`
  schema computing `TotalBudget`, `YTDSpend`, `Utilization`, and the
  derived `ComplianceStatus` (`unconfigured` / `on-track` / `at-risk` /
  `non-compliant`) per REQ-BBVW-005 thresholds.
- Add the aggregation query summing GL spend by mapped account and
  applying each mapping's allocation percentage.
- Add an integration test asserting the materialised aggregation values
  for the seeded fixtures.

## Out of Scope (this member)

Schema validation rules (03), manifest/routes (04), dashboard widgets
(05), mapping UI (06/07), the imperative `ComplianceService` fallback
(08), fiscal scoping + audit (09), i18n (10), full tests (11), docs
(12).
