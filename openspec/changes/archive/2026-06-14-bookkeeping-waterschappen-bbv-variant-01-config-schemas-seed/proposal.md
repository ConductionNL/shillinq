---
kind: config
depends_on: []
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

# Proposal: bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed

Member 1 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). No predecessor — this is the chain head. This member
**declares** the two registers, their relations, the manifest
navigation skeleton, the demo seed data, and the integration-test
scaffold that every later member consumes.

Predecessor: none (head of chain).
Successor: `bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance`.

## Why

Dutch water boards (waterschappen) operate under the BBV governance
framework requiring annual budgets aligned with policy programmes
(165 tender mentions; 57 for budget-line-to-programme mapping).
Before any aggregation, dashboard, UI, controller or test can run, the
`BBVProgramme` and `BudgetBBVMapping` registers and their relations
must exist as declarative metadata, and the demo seed must be loadable.
That declarative-first surface is this member's scope (ADR-031,
ADR-024).

## What Changes

- Declare the `BBVProgramme` register in
  `lib/Settings/shillinq_register.json` (programmeName, programmeCode,
  description, fiscalYear, status, relation to Administration).
- Declare the `BudgetBBVMapping` register (glAccountNumber,
  allocationPercentage, effectiveFrom, effectiveTo, relations to
  BBVProgramme, Account, Administration).
- Add the demo seed data (5 programmes + demo mappings) loaded via
  `ConfigurationService::importFromApp()`, idempotent on re-import.
- Add the integration-test scaffold that materialises programmes,
  mappings, and GL fixtures, reused by members 02, 08, 11.

## Out of Scope (this member)

Compliance aggregation definitions (02), validation rules (03),
manifest/routes wiring (04), dashboard widgets (05), mapping UI
(06/07), the compliance service (08), fiscal scoping + audit (09),
i18n (10), the full test matrix (11), and docs/quality (12).
