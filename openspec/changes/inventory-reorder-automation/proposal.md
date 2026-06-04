# Proposal: inventory-reorder-automation

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`InventoryReorderRule`) + aggregations + lifecycle rules +
manifest entries for min/max management and low-stock alerts. No
bespoke PHP reorder-engine service (subject to ADR-031 exception: at
most one single-method `ReorderGuard` if declarative preconditions
require PHP guard fallback).

## Summary

Introduce the **inventory min/max + reorder point** capability for
Nextcloud inventory management. This capability enables per-item,
per-location min/max stock levels with automated low-stock alerts;
auto-purchase-order generation is optional and controlled by
administration policy. The change declares the `InventoryReorderRule`
register; reorder triggers as declarative aggregation preconditions
and scheduled lifecycle actions; low-stock alert workflow consuming
OpenRegister's notification extension per ADR-022; optional
auto-PO generation with `PurchaseOrder` materialisation.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** `inventory-stock-tracking` (InventoryStock base
register exists and is actively maintained for per-location levels),
`catalog-purchase-management` (PurchaseOrder and supplier integration
for auto-PO triggering).

## Motivation

The competitor landscape (22/22 coverage, intelligence-db dated
2026-05-20) unanimously implements min/max reorder levels with
low-stock alerts. Dutch SMB (MKB) and small retail operators depend
on this for just-in-time inventory discipline and cash flow
management. Per ADR-022, alerts flow through OpenRegister's
notification engine, not app-local email tables. Per ADR-031, reorder
decision logic is declarative aggregation + lifecycle rules, not a
`ReorderService` class.

This is a priority P0-must feature with strong demand signal across
all vertical markets (brightpearl, erpnext, hike, inflow, lightspeed,
partkeepr, sortly, vagaro, zoho all ship min/max + low-stock
variants).

## Affected Projects

- [x] Project: nextcloud-inventory — adds 1 capability spec
  (`inventory-reorder-automation`); declares 1 new register
  (`InventoryReorderRule`) with lifecycle rules, aggregations, and
  alert triggers; adds 3 manifest navigation entries (Reorder Rules,
  Low Stock Alert, Stock Levels).
- [ ] Project: openregister — no source changes; consumes existing
  notification engine (`x-openregister-notifications` per ADR-022)
  and aggregation abstraction (`x-openregister-aggregations`).
- [ ] Project: purchaseq — no source changes; auto-PO triggers call
  `POST /api/v1/purchase-orders` via OpenAPI contract (optional,
  administration-gated).

## Scope

### In Scope

- One new capability spec (`inventory-reorder-automation`) — see the
  `specs/` folder.
- The `InventoryReorderRule` register defining per-item, per-location
  min, max, and reorder-point thresholds with alert policy and
  optional auto-PO policy.
- Low-stock alert workflow triggered when `InventoryStock.quantity <
  InventoryReorderRule.minimumLevel` with notification dispatch per
  `x-openregister-notifications` (ADR-022).
- Reorder trigger evaluation as `x-openregister-aggregations` query
  (SUM by location, compare against thresholds).
- Optional auto-PO generation: when alert fires, if rule carries
  `autoPurchaseOrder: true`, materialise a `PurchaseOrder` to the
  rule's linked supplier with `reorderQuantity` and `leadTimeDays`
  factored into delivery date.
- Lead-time awareness: reorder point computation factors supplier
  lead time so stock does not deplete during procurement.
- Dashboard widget: manifest entry aggregating `InventoryStock`
  below-minimum items across all locations per administration.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Demand forecasting** — sales velocity analysis. T1 does not
  compute optimal reorder quantities based on sales trends; reorder
  quantities are operator-configured per rule.
- **Multi-supplier assignment** — T1 ties reorder rules to a single
  primary supplier. Secondary sourcing and round-robin fallback
  defer to T2.
- **Cycle-count automation** — physical inventory count scheduling
  and variance tracking. T1 triggers alerts; T2 adds cycle-count
  workflows.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-reorder-automation`** — declares the `InventoryReorderRule`
register, the low-stock alert workflow, the reorder trigger
aggregation, the optional auto-PO materialisation pattern, and the
dashboard aggregations.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-IRA-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions (`x-openregister-lifecycle`,
`x-openregister-aggregations`, `x-openregister-notifications`) and the
already-deployed `InventoryStock` base register.

## Impact

- `lib/Settings/nextcloud_inventory_register.json` — adds 1 new schema
  (`InventoryReorderRule`); declares aggregations on low-stock checks +
  reorder-trigger evaluation; declares lifecycle actions for alert
  dispatch and optional PO materialisation.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `ReorderGuard` if aggregations require fallback).
- No new bespoke Vue components (manifest pages use shared
  OpenRegister list + detail views).

## Cross-Project Dependencies

- **OpenRegister** — depends on notification engine
  (`x-openregister-notifications`, ADR-022), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **Nextcloud inventory (base)** — depends on `inventory-stock-tracking`
  for the `InventoryStock` register (per-location, per-item quantity
  + reorder level).
- **Purchaseq** — optional integration via OpenAPI; if auto-PO enabled,
  triggers `POST /api/v1/purchase-orders` on alert.

## Risks

### Risk 1: OpenRegister notification engine not yet stable

**Severity**: Medium
**Mitigation**: If OR's notification extension is still draft at
implementation time, the spec captures the gap, files an OR issue,
and the implementing cycle MAY ship a single-method `NotificationGuard`
per ADR-031 §"PHP guards remain a legitimate seam". The guard is
removed once OR's extension lands. Spec is shape-neutral.

### Risk 2: InventoryStock base register not actively maintained

**Severity**: High
**Mitigation**: Verify at implementation time that `InventoryStock`
carries `quantity`, `reorderLevel`, `reorderQuantity`, and `location`
fields per `inventory-stock-tracking`. If schema has drifted,
reconcile via ADR-000 amendment. This is a dependency precondition;
implementation cannot proceed until satisfied.

### Risk 3: Lead-time calculation may miss supplier latency

**Severity**: Medium
**Mitigation**: REQ-IRA-005 factors `supplier.leadTimeDays` into
reorder-point calculation. If lead time is unavailable, rule defaults
to a fallback window (e.g., +7 days). Operator overrides reorder point
manually if needed.

### Risk 4: Auto-PO generation triggers runaway orders

**Severity**: Medium
**Mitigation**: Auto-PO is opt-in per rule (`autoPurchaseOrder`
boolean). Operator approval gate on materialised PO before shipment.
Supplier spending limit per contract (purchaseq integration). Audit
trail tracks auto-PO source.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — reorder rules remain queryable but
alerts and auto-PO are disabled.

## Open Questions

1. **OpenRegister notification engine stability** — see Risk 1;
   resolved in `opsx-ff` discovery; OR issue filed if needed.
2. **InventoryStock schema completeness** — see Risk 2; precondition
   check before implementation starts.
3. **Lead-time fallback window** — default +7 days if supplier lead
   time unavailable; customisable per administration; resolved during
   implementing cycle's UX review.
4. **Auto-PO spending gate** — should materialised PO require
   operator approval, or auto-approve if within administration
   policy? Resolved during implementing cycle's business logic review.
