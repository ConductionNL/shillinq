---
kind: code
depends_on: [bookkeeping-waterschappen-bbv-variant-06-mapping-index]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-07-mapping-detail

Member 7 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-06-mapping-index`. Successor:
`bookkeeping-waterschappen-bbv-variant-08-compliance-service`.

This `kind: code` member builds the **Budget Mapping detail page** — the
`CnDetailPage` form with GL-account + programme pickers, inline
allocation validation, save/delete, and the audit-trail sidebar
(giant REQ-BBVW-004 detail).

## Why

The detail page is where mappings are created and edited (giant
REQ-BBVW-004). It depends on the index (06) for navigation and surfaces
the schema validation declared in member 03 inline (live allocation-sum
feedback). It reuses the platform `CnDetailPage` + `CnFormDialog` +
`CnObjectSidebar` — no custom form framework.

## What Changes

- Create `BudgetBBVMappingDetail.vue` (CnDetailPage): GL Account picker,
  Programme picker, Allocation %, Effective From/To, Status; Save /
  Delete / Cancel actions; CnObjectSidebar audit-trail tab.
- Implement the GL Account picker (autocomplete from Chart of Accounts,
  shows name + type + balance).
- Implement the BBV Programme picker (autocomplete from BBVProgramme,
  current fiscal year only).
- Implement inline allocation validation (live per-account total, warn
  when total > 100%, helpful "you can add up to X%" message).
- Implement save logic (`objectStore.saveObject()`, toast on success,
  inline error on failure) and delete logic (confirm dialog →
  `objectStore.deleteObject()`).

## Out of Scope (this member)

Compliance service (08), fiscal/audit (09), i18n (10), tests (11), docs
(12).
