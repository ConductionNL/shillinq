---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-08-compliance-service]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-09-fiscal-audit

Member 9 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-08-compliance-service`.
Successor: `bookkeeping-waterschappen-bbv-variant-10-i18n`.

This `kind: code` member wires **fiscal-year scoping** across all BBV
queries/views (REQ-BBVW-006) and confirms **audit-trail integration**
on both registers (REQ-BBVW-007).

## Why

Every BBV view must be scoped to the active administration's fiscal
year so prior-year GL data is excluded and switching administrations
refreshes the data (giant REQ-BBVW-006). Audit-trail integration is
automatic via OpenRegister (giant REQ-BBVW-007, ADR-022) but must be
verified end-to-end on both registers. Both are cross-cutting concerns
that touch the dashboard, index, detail, and service from members
05–08, so they land after those consumers exist.

## What Changes

- Apply fiscal-year scoping to the dashboard, index, mapping queries,
  and `ComplianceService` — inherit the current fiscal year from the
  Shillinq Administration context.
- Exclude prior-fiscal-year GL transactions from all BBV aggregations
  and views.
- Surface the active fiscal year in the UI (label/breadcrumb) and
  refresh data when the administration changes.
- Verify OpenRegister captures create/update/delete on `BBVProgramme`
  and `BudgetBBVMapping` in the immutable audit trail (no app-local
  audit service).

## Out of Scope (this member)

i18n (10), tests (11), docs (12).
