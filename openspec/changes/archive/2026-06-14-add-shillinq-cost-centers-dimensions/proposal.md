# Proposal: add-shillinq-cost-centers-dimensions

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + allocation-rule seed shapes. No
PHP service classes are authored.

## Summary

Introduce the **cost-centers, projects, and custom dimensions with
allocation rules** capability for Shillinq as part of the Tier 4
advanced bookkeeping engine (per
`adr-001-bookkeeping-tier-roadmap.md`). This change declares four new
registers (`CostCenter`, `KostenDrager`, `Project`, `AllocationRule`),
extends T1 `GLLine` additively with dimension references
(`costCenterCode`, `kostenDragerCode`, `projectCode`, free-form
`dimensions` map), declares segment P&L as `x-openregister-aggregations`
on `GLLine` (per ADR-031), allocation rules with per-posting / monthly
/ period-close cadence routing, and pre-positions WBSO time tracking
via `time-per-project`. Manifest navigation wired through Tier-4
`CnAppRoot`. No PHP `AllocationService`, no bespoke Vue components.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

Cost-centers, kostendragers, and projects are mandatory for segment
P&L, project accounting, and pre-positions WBSO time tracking
(T4-specialized) which depends on time-per-project. Custom dimensions
(e.g. region, product line, channel) extend the analytical accounting
surface for sector-specific use without bespoke PHP. Allocation rules
spread shared cost (overhead, IT, facility) across cost objects using
declarative drivers.

This proposal is one of seven sibling Tier 4 capability changes
extracted from the bundled `add-shillinq-bookkeeping-advanced` proposal
to satisfy ADR-032 spec-sizing (cap: 20 unchecked tasks per change).

## Affected Projects

- [x] Project: shillinq — adds 4 new registers/schemas (`CostCenter`,
  `KostenDrager`, `Project`, `AllocationRule`) to
  `lib/Settings/shillinq_register.json`, additive extensions to T1
  `GLLine` (dimension references), allocation-rule seed shapes under
  `lib/Settings/seeds/allocation-rules/`, manifest navigation entries
  under `Bookkeeping > Dimensions`.
- [ ] Project: openregister — no source changes; this change consumes
  `x-openregister-relations`, `x-openregister-lifecycle`,
  `x-openregister-aggregations`, audit-trail-immutable, RBAC,
  `ScheduledWorkflow`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-cost-centers-dimensions`) —
  see the `specs/` folder.
- `CostCenter`, `KostenDrager`, `Project`, `AllocationRule` register
  declarations, each with `x-openregister-relations` self-relation for
  hierarchical navigation (first three).
- Additive dimension fields on T1 `GLLine` (`costCenterCode`,
  `kostenDragerCode`, `projectCode`, free-form `dimensions` map
  validating against registered custom dimension registers).
- Segment P&L roll-up as `x-openregister-aggregations` keyed by
  dimension.
- `AllocationRule` schema with four named drivers (`fixed-percentage`,
  `fixed-amount`, `volume`, `headcount`); cadence routes execution
  (per-posting → `x-openregister-lifecycle` action on
  `GLTransaction.post`; monthly / period-close →
  `ScheduledWorkflow`).
- `fixed-percentage` precondition that target percentages sum to 100
  expressed as `x-openregister-lifecycle.requires` on
  `AllocationRule.save`.
- Pre-positioned WBSO `time-per-project` reference (REQ-CC-007).
- Allocation-rule example seeds (paused by default).
- Manifest navigation entries under `Bookkeeping > Dimensions` using
  `type: index` / `type: detail` renderers.

### Out of Scope

- **Implementation code** — this is a spec-only change.
- **WBSO time-tracking capability itself** — pre-positioned by
  REQ-CC-007 but the actual WBSO capability is T4-specialized future
  work.
- **Custom-driver authoring** — four named drivers ship; custom drivers
  require an OR issue for the driver enum extension. No domain-specific
  allocation logic in shillinq.
- **Frontend Vue components** beyond `CnIndexPage` / `CnDetailPage`
  generic rendering.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-cost-centers-dimensions`** — declares the four
dimension registers, the `AllocationRule` register with cadence
routing, segment P&L aggregations on `GLLine`, additive dimension
fields on T1 `GLLine`, and pre-positions WBSO time-per-project.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-CC-*` for
traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 schemas
  (`CostCenter`, `KostenDrager`, `Project`, `AllocationRule`) with
  `x-openregister-relations` self-relations on the first three;
  additive patches on T1 `GLLine` for dimension fields; declares
  `x-openregister-aggregations` on `GLLine` for segment P&L.
- `lib/Settings/seeds/allocation-rules/*.json` — seed example rules
  (overhead-by-headcount, it-by-volume, facility-by-fixed-percentage)
  shipped in `lifecycleState: paused`.
- `src/manifest.json` — adds 4 navigation entries (Cost Centers,
  Kostendragers, Projects, Allocation Rules) under
  `Bookkeeping > Dimensions`.
- No new PHP services. No new Vue components. No new controllers.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-relations`,
  `x-openregister-lifecycle`, `x-openregister-aggregations`,
  audit-trail-immutable, RBAC, `ScheduledWorkflow`.
- **T1 `bookkeeping-general-ledger`** — additive dimension fields on
  `GLLine` reuse the existing T1 schema additively.

## Risks

### Risk 1: Cost-allocation driver scope creep

**Severity**: Low
**Mitigation**: REQ-CC-004 ships four named drivers
(`fixed-percentage`, `fixed-amount`, `volume`, `headcount`). Adding a
new driver is additive (enum extension). Custom domain-specific
allocation logic is out of scope; if an operator needs a custom
driver, an OR issue is filed for the driver enum extension and the
operator-side rule is configured through normal `AllocationRule`
edits.

### Risk 2: Cross-line balance constraint on per-posting allocation split

**Severity**: Medium
**Mitigation**: The cross-line balance constraint emitted when the
rule splits a transaction is the same constraint T1 declared on
`GLTransaction.post` — declarative re-use, no duplication. If T1's
balance check is a thin guard (per T1 design D2), the split honours
the same guard; no new ADR-031 exception.

### Risk 3: Dimension-hierarchy depth unbounded

**Severity**: Low
**Mitigation**: Schemas permit arbitrary depth via
`x-openregister-relations` self-relation; UI renders the first 4
levels by default with collapse/expand. No T4 enforcement of max
depth.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder. After implementation (separate cycle), rollback follows the
standard pattern: revert the implementing PR. The additive dimension
fields on `GLLine` are optional, so existing T1 single-dimension
callers stay correct.

## Open Questions

1. **WBSO time-tracking scope** — REQ-CC-007 pre-positions
   `time-per-project` but the WBSO capability itself is deferred to
   T4-specialized. Confirm boundary during opsx-ff.
2. **Default custom dimension registers** — ship none, or seed a
   `Region` example? Settled in implementing cycle UX review.
