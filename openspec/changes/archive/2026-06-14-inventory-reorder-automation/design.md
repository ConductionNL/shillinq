# Design — Inventory Min/Max + Reorder Point

**Status:** pr-created

## Context

Dutch SMB and small retail operators depend on just-in-time inventory
discipline for cash flow management. The competitor landscape
(22/22 coverage) unanimously implements min/max reorder levels with
low-stock alerts. Per ADR-022, alerts flow through OpenRegister's
notification engine, not app-local email tables. Per ADR-031, reorder
decision logic is declarative aggregation + lifecycle rules, not a
`ReorderService` class.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire reorder-automation surface as **declarative
  metadata** — schemas + lifecycle + aggregations + notification
  dispatch — per ADR-031.
- Consume OR's notification engine abstraction — per ADR-022. Zero
  parallel email-queue table.
- Make the spec a **SMB-operator readable contract** — Dutch MKB
  inventory flow recognisable end-to-end (set min/max, monitor
  stock, trigger alert, optionally auto-order, receive).
- Declare the reorder-rule and alert-policy shape so T2 can attach
  demand forecasting + cycle-count workflows additively.
- Enable **low-touch auto-PO generation** for operators who want
  just-in-time procurement without manual order entry.

## Non-Goals

- No PHP reorder-engine service, no `InventoryReorderService.php`.
- No demand forecasting or sales velocity analytics — T2.
- No multi-supplier assignment or round-robin fallback — T2.
- No cycle-count scheduling or physical inventory reconciliation — T2.

## Decisions

### D1 — Reorder rule is a first-class register with per-location granularity

`InventoryReorderRule` is a declarative register tied to a specific
`InventoryStock` (item + location combination). Each rule carries
min, max, reorder-point, and lead-time-aware thresholds. Unlike
static fields on the item itself, rules are mutable and can vary by
location and supplier arrangement.

### D2 — Low-stock alert workflow consumes OR's notification engine

When `InventoryStock.quantity < InventoryReorderRule.minimumLevel`,
a lifecycle action fires and dispatches a notification via OR's
`x-openregister-notifications` extension. The notification carries
the item, location, current quantity, and action links (order now,
update rule, snooze). Shillinq carries no app-local email queue.

If OR's notification extension is not yet stable, ADR-031's
exception path applies: a single-method `NotificationGuard` ships,
cited in the spec.

### D3 — Reorder trigger is an aggregation precondition, not a service

A `x-openregister-aggregations` query evaluates `SUM(InventoryStock.quantity
WHERE item_id = X AND location = Y)` and compares against the rule's
reorder-point threshold. No PHP service; pure declarative logic.

### D4 — Optional auto-PO generation materialises a balanced purchase order

If a reorder rule carries `autoPurchaseOrder: true`, the alert
lifecycle action creates a `PurchaseOrder` to the rule's linked
supplier with `reorderQuantity` and delivery-date = `today +
supplier.leadTimeDays`. The PO materialisation pattern reuses the T1
purchase-order contract from purchaseq. Operator approval gate may
apply (per administration policy).

### D5 — Lead-time awareness prevents stock depletion during procurement

Reorder-point calculation factors `supplier.leadTimeDays`. Instead of
reordering when stock hits zero, the rule triggers when stock
projects to zero *during* the lead-time window. Formula: reorder-point
= expected-daily-usage × lead-time-days + safety-stock.

### D6 — Low-stock dashboard aggregates below-minimum items by location

A manifest aggregation queries `InventoryStock` items below their
rule's minimum, grouped by location. Operator sees at-a-glance which
locations need restocking. Widget links to reorder-rule detail page
for quick adjustment.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Reorder rule lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `InventoryReorderRule` (`active → alert → auto-ordered` or `manual-order` as optional paths); materialises notification event |
| Low-stock alert dispatch | OR `x-openregister-notifications` (if stable; else gap) | Consumed via lifecycle action; PHP guard fallback per ADR-031 exception if needed |
| Reorder trigger evaluation | OR `x-openregister-aggregations` | Aggregation precondition comparing `SUM(InventoryStock.quantity)` against threshold |
| Low-stock dashboard | OR `x-openregister-aggregations` | GROUP BY location, filtering items below minimum |
| Auto-PO materialisation | T1 `PurchaseOrder` register + purchaseq OpenAPI | Same PO contract; optional trigger from lifecycle action |
| Supplier lead time | T1 `Supplier` register (already carries `leadTimeDays`) | Consumed as FK reference in reorder-rule |
| Audit trail | OR `x-openregister-audit` | Automatic on rule changes and alert dispatch |
| Manifest navigation | T1 manifest pattern | 3 entries (Reorder Rules, Low Stock Alerts, Stock Levels) + their pages |

**Net new code in implementation cycle**: 1 schema declaration +
1 lifecycle block + 2 aggregations + 3 manifest entry pairs. At most
1 single-method PHP guard (`NotificationGuard`) gated by ADR-031
exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Reorder rule lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine (active → alert → ordered) |
| Low-stock alert dispatch | Consumed from OR notification engine if stable; else single-method `NotificationGuard` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Reorder trigger evaluation | Declarative (`x-openregister-aggregations` precondition) | Pure SUM + threshold comparison |
| Low-stock dashboard | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM + filtering |
| Auto-PO materialisation | Lifecycle action invoking purchaseq OpenAPI contract | No new service; PO is a first-class entity |
| Lead-time calculation | Declarative — schema fields + computed expression | `reorderPoint = dailyUsage × leadDays + safetyStock` |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `NotificationGuard`).

## Seed Data

Three example reorder rules for common Dutch MKB scenarios:

1. **Grocer (daily high-velocity item)**: Apple juice 1L
   - Minimum: 50 units
   - Maximum: 200 units
   - Reorder-point: 75 units (5-day lead time × 10 units/day + 25 safety)
   - Auto-PO: enabled

2. **Office supply retailer (slow-moving)**: Premium A4 paper ream
   - Minimum: 5 units
   - Maximum: 50 units
   - Reorder-point: 10 units (7-day lead time × 1 unit/day + 3 safety)
   - Auto-PO: disabled (manual approval preferred)

3. **Warehouse (multi-location)**: Industrial fasteners, Location: Amsterdam
   - Minimum: 500 units
   - Maximum: 5000 units
   - Reorder-point: 1500 units (14-day lead time × 100 units/day + 500 safety)
   - Auto-PO: enabled with €5000 spending gate

All seed rules carry administration = `default`, supplier = `primary`,
lifecycleState = `active`.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR notification engine not yet stable | Spec shape-neutral; PHP guard fallback (`NotificationGuard`, single-method, ~30 LOC) per ADR-031 exception; remove when OR extension lands |
| Lead-time unavailable for supplier | Rule defaults to fallback window (+7 days); operator overrides reorder-point manually if needed |
| Auto-PO spending spiral | Operator approval gate (optional per administration); supplier contract spending limit enforced by purchaseq |
| Stock level changes between alert and order | Operator can snooze/cancel alert; reorder-point dynamic (recalculated on alert fire) |
| Notification delivery failure | OR's notification engine includes retry + dead-letter handling (ADR-022); no local queue in shillinq |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/nextcloud_inventory_register.json` is patched with the
   `InventoryReorderRule` schema (additive — no existing schema changes).
2. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).
3. If OR's notification engine is not yet stable,
   `lib/Lifecycle/NotificationGuard.php` ships (single method, ~30 LOC,
   ADR-031 exception annotated).
4. Operator configures reorder rules via UI on first use; no data migration.

Down-direction: registers are non-destructive — reverting removes
the manifest entries; reorder rules remain queryable but alerts and
auto-PO are disabled.

## Open Questions

1. **OR notification engine stability** — resolved in `opsx-ff`
   discovery; OR issue filed if needed.
2. **Lead-time fallback window** — default +7 days if supplier lead time
   unavailable; customisable per administration; resolved during
   implementing cycle's UX review.
3. **Auto-PO approval gate** — should materialised PO require operator
   approval before shipment, or auto-approve if within policy?
   Resolved during implementing cycle's business logic review.
4. **Daily usage estimation** — for lead-time-aware reorder-point
   calculation, should the system estimate daily usage from recent
   stock movements, or require operator input? Resolved in T2 demand-
   forecasting capability.
