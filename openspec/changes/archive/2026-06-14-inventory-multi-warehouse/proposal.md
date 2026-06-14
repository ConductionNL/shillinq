# Proposal: inventory-multi-warehouse

`kind: spec` per ADR-032 — hierarchical location structure with warehouse → zone →
bin taxonomy; inter-warehouse and in-transit inventory tracking. New register
`Location` with hierarchy, extending the existing simple Location entity to support
multi-level organizational warehousing. Follows ERPNext's warehouse-group pattern
and Brightpearl's real-time multi-location stock visibility.

## Summary

Introduce the **hierarchical locations** capability for Shillinq inventory management
as a foundational T2 capability for multi-warehouse operations. This capability
enables operators to organize inventory storage across warehouses, zones, and bins
with support for inter-location transfers and in-transit inventory tracking. The change
declares the enhanced `Location` entity with parent-child hierarchy and location type
(warehouse, zone, bin); inter-location transfer workflows; in-transit location pattern
for transfers between warehouses; and location-level stock tracking integration with
`InventoryStock`. Supports stock movement across warehouse → zone → bin hierarchy
with visibility of in-transit quantities during transfers.

This change conforms to the shared `nextcloud-app` spec for app structure.

**Depends on:** `inventory-stock-tracking` (stock quantities per location),
`add-shillinq-general-ledger` (optional GL integration for transfer accounting).

## Motivation

Multi-warehouse inventory management is a P0 must-have capability present in 22/22
competitors (per intelligence-db 2026-05-20). Organizations require hierarchical
organization of storage (warehouse → zone → bin), real-time visibility of stock
across locations, and seamless inter-warehouse transfer workflows. The Brightpearl
real-time multi-location model enables operators to see in-transit quantities
separately from received quantities, reducing discrepancies. ERPNext's warehouse-group
hierarchy allows nested organization and rollup reporting. Dutch SMB warehouses
(from market research cohort) report average 3.2 active locations per organization,
with 40% managing multiple sites.

Per ADR-022 (consumes shared abstractions), location hierarchy and transfer workflows
are declarative; no bespoke PHP `WarehouseService`.

## Affected Projects

- [x] Project: shillinq — extends 1 existing entity (`Location`); declares 1 new
  capability spec (`inventory-multi-warehouse`); adds optional GL integration for
  transfer accounting (depends on `add-shillinq-general-ledger` in T1).
- [ ] Project: openregister — no source changes; consumes existing Location entity
  with hierarchy support.

## Scope

### In Scope

- Enhanced `Location` entity with parent-child hierarchy (parentLocationId FK), location
  type (warehouse, zone, bin, in-transit), and location code for warehouse-level
  identification.
- One new capability spec (`inventory-multi-warehouse`) — see the `specs/` folder.
- Hierarchical location structure: warehouse is a container for zones; zones contain
  bins; in-transit is a special virtual location type for transfer-in-progress.
- Rollup stock visibility: operator can query total stock at warehouse level (sum of
  all zones and bins under it).
- Inter-location transfer workflows: move stock between any two locations in the
  hierarchy (warehouse-to-warehouse, zone-to-zone, bin-to-bin).
- In-transit inventory pattern: stock transferred between warehouses shows as in-transit
  until physically received (optional, depends on `inventory-stock-movement-ledger` spec).
- Location-level `InventoryStock` association: stock is tracked at the most-granular
  location level (bin); queries aggregate up the hierarchy.
- Manifest navigation: Warehouse Locations hierarchy tree, inter-warehouse transfers,
  in-transit stock visibility.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers,
  tests, and CI changes are deliberately not in this proposal; the task list references them
  but implementation lands via a separate `opsx-apply` cycle.
- **Automated putaway / slotting rules** — out-of-spec advanced optimization; future
  `inventory-putaway-rules` capability.
- **Barcode / mobile scanner integration** — future `inventory-mobile-scanner` capability.
- **Yard / dock management** — future `inventory-yard-management` capability (cross-dock,
  receiving docks).
- **3PL multi-client segregation** — future spec for 3PL warehouse operators.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-multi-warehouse`** — declares the enhanced `Location` entity with
hierarchy, location type, and rollup stock queries. Declares inter-location transfer
workflows and in-transit inventory pattern. Integrates with existing `InventoryStock`
and optional GL posting for transfer accounting.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-LOC-*` for traceability.

## New Dependencies

None beyond existing inventory and GL tiers. Consumes InventoryStock from
`inventory-stock-tracking` and optional GL integration pattern from
`add-shillinq-general-ledger`.

## Impact

- `lib/Settings/shillinq_register.json` — extends `Location` schema with hierarchy
  fields (parentLocationId, locationType, locationCode).
- `openspec/architecture/adr-000-data-model.md` — updates Location entity definition
  to reflect hierarchy and type enum.
- `src/manifest.json` — adds navigation entries (Warehouse Locations hierarchy, 
  inter-warehouse transfers, in-transit stock).
- No new PHP services (per ADR-031 — location hierarchy and transfer workflows are
  declarative or use existing entity patterns).
- 2-3 Vue components for hierarchy tree view, transfer form, in-transit visibility.

## Cross-Project Dependencies

- **InventoryStock** — from `inventory-stock-tracking` spec; Location FK is
  scoped to InventoryStock queries. Hierarchy enables rollup queries.
- **GL integration** — optional; if `add-shillinq-general-ledger` is present,
  inter-warehouse transfers can post GL adjustment entries (location reclassification).
- **StockMove** — optional; if `inventory-stock-movement-ledger` is present,
  in-transit location holds stock during transfer moves.

## Risks

### Risk 1: Location hierarchy depth explosion

**Severity**: Low
**Mitigation**: Location hierarchy is operator-managed (admin-defined). Recommend
max depth 4 (warehouse → zone → aisle → bin). In implementation cycle, add depth
validation and visual UI limit. ADR-032 review confirms Location max depth constraint.

### Risk 2: Rollup stock query performance at scale

**Severity**: Low-Medium
**Mitigation**: InventoryStock queries are typically at bin level (most-granular);
rollup aggregation uses indexed parent-child FK (parentLocationId). Caching at
zone/warehouse level in implementation cycle if gates trip. Database indices on
Location(parentLocationId, type) for hierarchy traversal.

### Risk 3: In-transit location semantics clash with GL

**Severity**: Low
**Mitigation**: In-transit is a virtual location (type='in-transit'); stock in it
is not GL-posted until received (stays in "goods-in-transit" GL account per
`inventory-stock-movement-ledger` pattern). Clarified in design.md Decision D2.

### Risk 4: Inter-warehouse transfer without `inventory-stock-movement-ledger`

**Severity**: Low
**Mitigation**: Transfer without move ledger is allowed (simpler use case); operators
manually adjust stock if needed. With move ledger, transfers are atomic (move creates
offsetting stock moves). No breakage.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no
runtime impact. After implementation (separate cycle), rollback follows the standard
pattern: revert the implementing PR; Location hierarchy fields remain (soft delete via
stored flag if needed); InventoryStock remains queryable.

## Open Questions

1. **Location hierarchy depth limit** — is max depth 4 (warehouse → zone → aisle → bin)
   sufficient for Dutch SMB? Competitors show up to 5 levels (warehouse → yard → dock →
   staging → bin). Resolved in ADR-032 / architecture review.
2. **In-transit location visibility** — should in-transit stock be visible separately
   in InventoryStock (e.g., separate `inTransitQuantity` field) or rolled into
   `quantity` with status flag? Depends on `inventory-stock-movement-ledger` integration.
   Resolved during implementing cycle's UX design.
3. **Warehouse transfer GL posting** — if GL integration is enabled, does
   warehouse-to-warehouse transfer post reclassification GL (debit W2 location GL,
   credit W1 location GL) or stay off-GL until final receipt? Resolved in implementing
   cycle's GL architect review.
4. **Location code uniqueness** — is location code unique per administration or globally?
   Resolved during implementing cycle's data model review.
5. **Rollup reporting** — should warehouse-level stock rollup include in-transit stock
   or only received stock? User preference or configurable per administration? Resolved
   in implementing cycle's UX design.
