---
kind: config
depends_on: [bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance]
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

# Proposal: bookkeeping-waterschappen-bbv-variant-03-validation-rules

Member 3 of 12 in the `bookkeeping-waterschappen-bbv-variant` chain
(ADR-032). Predecessor:
`bookkeeping-waterschappen-bbv-variant-02-aggregation-compliance`.
Successor: `bookkeeping-waterschappen-bbv-variant-04-manifest-routes`.

This `kind: config` member declares the **schema-level validation
rules** for both registers (REQ-BBVW-008): programme code format +
uniqueness, fiscal-year bounds, allocation precision, effective-date
ordering, and the per-account "allocation ≤ 100%" sum rule.

## Why

Validation belongs at the schema level (OpenRegister) so invalid data
cannot be persisted regardless of which client writes it (ADR-022).
Declaring the rules before the mapping UI (06/07) is built means the
detail page's inline validation (member 07) reads from one canonical
constraint set rather than reimplementing it.

## What Changes

- Add programme validation to the `BBVProgramme` schema: `programmeCode`
  regex `^\d+\.\d+(\.\d+)?$`, unique per (administration, fiscalYear);
  `fiscalYear` integer 1900–2100; `status` enum; `programmeName`
  required, max 255.
- Add mapping validation to the `BudgetBBVMapping` schema:
  `glAccountNumber` FK existence; `allocationPercentage` 0–100 with
  0.01 precision; `effectiveFrom` required ISO date; `effectiveTo` ≥
  `effectiveFrom` when present.
- Add the per-account allocation sum rule: total allocation per GL
  account per fiscal year SHALL equal 100% within ±0.1% tolerance.

## Out of Scope (this member)

Manifest/routes (04), dashboard (05), mapping UI (06/07) — though the
detail page's inline validation consumes these rules — the service
(08), fiscal/audit (09), i18n (10), tests (11), docs (12).
