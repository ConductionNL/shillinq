# Proposal: bookkeeping-cost-centers-dimensions

`kind: config` per ADR-032 — the centre of mass is declarative schema metadata + manifest entries + seed data shapes. No PHP service classes are authored.

## Summary

Introduce the **cost centers, analytical dimensions, and multi-dimensional analysis** capability for bookkeeping applications as part of the advanced accounting engine. This change declares registers for `CostCenter`, `Project`, `AnalyticalDimension`, and extends the `GLLine` entity with dimension references (`costCenterCode`, `projectCode`, `dimensions` map). It declares segment P&L aggregations on `GLLine` keyed by dimension, enabling cost-center-based, project-based, and department-based analytical accounting and reporting. No PHP allocation service classes, no bespoke Vue components.

This change conforms to the shared `nextcloud-app` spec for app structure, OpenAPI 3.0 register format, and `ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Cost centers, projects, and custom analytical dimensions are mandatory for:
- Multi-dimensional reporting (cost center, project, department-level P&L)
- Budget hierarchies spanning organizational structure (departments, cost centers, projects)
- Segment analysis and departmental profit/loss tracking
- Analytical accounting per Dutch GAAP and government accounting standards

These capabilities are high-demand across public and mid-market sectors (240, 227, 178 demand points respectively from 65-73 tender mentions each). Custom dimensions (e.g., region, product line, channel) extend the analytical surface without requiring code changes.

## Affected Projects

- [x] Project: bookkeeping app — adds registers and additive extensions for cost-center and dimension management, manifest navigation entries under `Bookkeeping > Dimensions`.
- [x] Project: openregister — consumes `x-openregister-relations` (hierarchy), audit-trail-immutable, RBAC.

## Scope

### In Scope

- One new capability spec (`bookkeeping-cost-centers-dimensions`).
- `CostCenter` and `Project` register declarations with `x-openregister-relations` self-relation for hierarchical navigation.
- `AnalyticalDimension` register for custom dimension definitions.
- Additive dimension fields on `GLLine` (`costCenterCode`, `projectCode`, `dimensions` map).
- Segment P&L roll-up as `x-openregister-aggregations` keyed by dimension.
- Manifest navigation entries under `Bookkeeping > Dimensions`.
- Seed data for cost centers and example dimensions (3-5 realistic Dutch entities).

### Out of Scope

- **Implementation code** — this is a spec-only change.
- **Cost allocation rules** — a separate advanced accounting feature.
- **WBSO time-tracking** — pre-positioned by dimension structure but deferred to separate capability.
- **Frontend Vue components** beyond `CnIndexPage` / `CnDetailPage` generic rendering.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-cost-centers-dimensions`** — declares the dimension registers, additive dimension fields on `GLLine`, and segment P&L aggregations.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-CD-*` for traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions.

## Impact

- `lib/Settings/shillinq_register.json` (or equivalent app configuration) — adds 3 schemas (`CostCenter`, `Project`, `AnalyticalDimension`) with `x-openregister-relations` self-relations; additive patches on `GLLine` for dimension fields; declares `x-openregister-aggregations` on `GLLine` for segment P&L.
- `src/manifest.json` — adds 4 navigation entries (Cost Centers, Projects, Analytical Dimensions, Segment P&L) under `Bookkeeping > Dimensions`.
- Seed data with 3-5 realistic cost centers and example dimensions.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-relations`, `x-openregister-aggregations`, audit-trail-immutable, RBAC.
- **Bookkeeping tier 1 (General Ledger)** — additive dimension fields on `GLLine` reuse the existing tier-1 schema additively.

## Risks

### Risk 1: Dimension hierarchy depth unbounded

**Severity**: Low
**Mitigation**: Schemas permit arbitrary depth via `x-openregister-relations` self-relation; UI renders the first 4 levels by default with collapse/expand.

### Risk 2: Custom dimension key validation complexity

**Severity**: Low
**Mitigation**: Validation is declared via OR's relation engine (not custom PHP), reusing existing validation patterns.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder. The additive dimension fields on `GLLine` are optional, so existing callers without dimensions stay correct.

## Open Questions

1. **Seed dimension defaults** — ship example Cost Centers (Dutch municipality structure?), or only schema?
2. **Segment P&L visualization** — dashboard widget vs. drill-down detail page?
3. **Custom dimension authoring workflow** — UI to define new analytical dimensions, or operator-edited register only?
