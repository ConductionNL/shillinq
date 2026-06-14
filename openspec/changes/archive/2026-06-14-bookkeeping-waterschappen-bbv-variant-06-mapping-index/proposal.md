---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-06-mapping-index

Member 6 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets`.
Successor: `bookkeeping-waterschappen-bbv-variant-07-mapping-detail`.

This `kind: code` member builds the **Budget Mapping index page** — the
`CnIndexPage` list of `BudgetBBVMapping` records with search, filter,
and navigation into the detail page, backed by an object store created
with `createObjectStore`.

## Why

Admins need a list view to find and manage budget-to-programme mappings
(giant REQ-BBVW-004 index). The index is the entry point to the
create/edit flows; building it before the detail page (07) gives a
navigable list-to-detail flow. The store uses Shillinq's standard
`createObjectStore` Options-API pattern (no custom Vuex store).

## What Changes

- Create `BudgetBBVMappingIndex.vue` (CnIndexPage): columns GL Account,
  Programme, Allocation %, Effective From, Effective To, Status.
- Add search by GL account number / programme code and filters by
  fiscal year, allocation range, effective-date range.
- Wire the Add button → detail page `id=new`; row click → detail
  `id=<uuid>`.
- Create the `budgetBBVMappingStore` via
  `createObjectStore('budget-bbv-mapping', 'BudgetBBVMapping',
  'Mappings')` with relations + auditTrails plugins.

## Out of Scope (this member)

Detail page + pickers + save/delete (07), compliance service (08),
fiscal/audit (09), i18n (10), tests (11), docs (12).
