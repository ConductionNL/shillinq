# Proposal: inventory-cycle-count

`kind: config` per ADR-032 — declarative stock-count schema (`InventoryCycleCount`) + 
`x-openregister-lifecycle` for draft → submitted → counted → posted → reconciled state 
machine. Variance posting with categorized reason codes (`InventoryVarianceReason`). 
Support for full counts + partial/zone-based counts with mobile-scanner integration points.
No PHP count-service classes authored (per ADR-031).

## Summary

Introduce **inventory cycle count / stock-take** capability as one of the T2 inventory 
operations capabilities (per the Shillinq inventory roadmap). This change declares the 
`InventoryCycleCount` register with line-item structure for expected vs. counted quantities, 
variance tracking with reason codes, and lifecycle management. Cycle counts can be 
scheduled as full inventory counts or partial counts by location/zone. Variance 
reconciliation ties back to inventory adjustments.

The capability materialises inventory adjustments to `InventoryStock` per the same 
lifecycle pattern used for other stock movements.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) 
spec for app structure.

**Depends on:** [`inventory-stock-tracking`](../inventory-stock-tracking/context-brief.md)
(provides `InventoryStock` and product catalog baseline).

## Motivation

Cycle counts are core inventory control — 100% of mid-market WMS and 73% of ERP/POS 
systems ship stock-take with variance posting. Dutch SMBs use cycle counts to catch 
shrinkage, obsolescence, and counting errors before year-end physical inventory closes 
the books. Per the intelligence-db intelligence database, cycle counting with variance 
reason codes is a customer-asked feature across 16/22 competitors (72.7%).

This is one of eight T2 inventory capability changes; this proposal scopes only the 
cycle count + variance slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`inventory-cycle-count`); declares 2 
  new registers (`InventoryCycleCount`, `InventoryVarianceReason`) with lifecycles and 
  line structures; adds 3 manifest navigation entries (Cycle Counts, Count Templates, 
  Variance Reports).
- [ ] Project: openregister — no source changes; consumes existing `x-openregister-
  lifecycle`, `x-openregister-aggregations`. Optional integration point: mobile-scanner 
  app can emit `countedQuantity` via webhook for real-time count capture (T4).
- [ ] Project: inventory-stock-tracking — no source changes; this capability reads from 
  `InventoryStock` and posts adjustments back.

## Scope

### In Scope

- One new capability spec (`inventory-cycle-count`) — see the `specs/` folder.
- The `InventoryCycleCount` register carrying a count batch with optional location/zone 
  scope, count date, and line-item breakdown (SKU, expected qty, counted qty, variance).
- The `InventoryVarianceReason` register with categorized reason codes (damage, theft, 
  writing error, obsolescence, stocking error, count error, system discrepancy, other) 
  with user-configurable descriptions.
- Cycle count lifecycle (`draft → submitted → counting → posted → reconciled` plus 
  `cancelled`) with role-based state transitions (count supervisor submits, warehouse 
  staff count, supervisor reviews variance, finance posts).
- Line-item variance calculation (counted qty vs. expected qty) with % variance threshold 
  flagging (e.g., > 5% variance per line requires reason code).
- Material discrepancy detection: lines with qty variance > threshold or cost variance > 
  absolute threshold auto-flag for investigation.
- Variance posting: on `InventoryCycleCount.reconcile`, variance lines generate 
  `InventoryAdjustment` records with reason-code FK, updating `InventoryStock.quantity` 
  and materialising GL impact per cost-accounting-allocation tier.
- Partial counts: optional location/zone/category filter to limit `InventoryCycleCount` 
  to a subset of inventory (mobile-scanner integration point for T4).
- Count templates: pre-defined count batch configurations (annual full count, monthly 
  zone A rotation, etc.) for recurring count schedules.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers, 
  tests are not in this proposal.
- **Mobile scanner integration** — T4 feature; this spec provides the webhook integration 
  point but does not implement the scanner app or real-time sync.
- **Barcode printing + label generation** — separate capability; assumes SKU/barcode already 
  on inventory.
- **Advanced statistical sampling** — T4; cycle count assumes operator-driven selection 
  (full or zone-based).
- **Multi-currency revaluation on variance** — T5.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-cycle-count`** — declares the two registers, the lifecycle (draft → submitted 
→ counting → posted → reconciled), variance threshold logic, reason codes, and the 
reconciliation path to `InventoryAdjustment` + GL posting.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, 
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed 
`REQ-ICC-*` for traceability.

## New Dependencies

None beyond `inventory-stock-tracking` (already P0). Consumes existing OpenRegister 
abstractions.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 new schemas (`InventoryCycleCount`, 
  `InventoryVarianceReason`); declares lifecycle on `InventoryCycleCount`; declares line 
  variance calculation.
- `src/manifest.json` — adds 3 navigation entries + their `type: index` + `type: detail` 
  pages.
- No new PHP services (per ADR-031; variance calculation is declarative).
- Optional mobile-scanner webhook endpoint documented for future T4 integration.

## Cross-Project Dependencies

- **Inventory Stock-on-hand** — depends on `inventory-stock-tracking` for `InventoryStock` 
  baseline.
- **Cost Accounting / GL** — variance posting ties back to GL impact via cost-center 
  allocation (separate spec: cost-accounting-allocation).

## Risks

### Risk 1: Variance threshold logic requires PHP guard if not expressible declaratively

**Severity**: Medium
**Mitigation**: Variance threshold (e.g., flag > 5% discrepancy) is declared in config. 
If OR's `x-openregister-lifecycle.requires` cannot express conditional logic, ADR-031's 
exception path applies (one single-method `InventoryVarianceGate::requiresInvestigation()`).

### Risk 2: Partial counts + location-based filtering add complexity

**Severity**: Low-Medium
**Mitigation**: Location/zone filtering is optional; full counts are the default path. 
Partial counts use standard register query filters (no bespoke indexing required).

### Risk 3: Mobile scanner integration deferred to T4

**Severity**: Low
**Mitigation**: This capability declares the webhook shape (POST `/cycle-count/{id}/count-
line`, JSON payload) as a future integration point, but does not ship the mobile app. 
Manual count entry is the primary path.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime 
impact. After implementation (separate cycle), rollback follows the standard pattern: 
revert the implementing PR; registers are non-destructive — cycle counts remain queryable 
but unreferenced.

## Open Questions

1. **Variance threshold policy** — per-SKU, per-location, or global? Resolved during 
   design discovery.
2. **Reason code taxonomy** — extensible (user-defined) or fixed set? Pending product 
   decision; spec pre-defines 8 categories.
3. **Cycle count scheduler** — manual triggering vs. auto-scheduled on calendar/date? 
   Deferred to T3.
