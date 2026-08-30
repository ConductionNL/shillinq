---
status: done
---

# Spec: inventory-reorder-automation

**Status:** proposed
**Scope:** nextcloud-inventory
**Tier:** T1 (core inventory automation)
**Depends on:** `inventory-stock-tracking` (InventoryStock register exists with per-location granularity),
`catalog-purchase-management` (PurchaseOrder register and supplier integration)

## Purpose

This specification defines the requirements for inventory reorder automation in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: reorder rule pages not yet implemented


### REQ-IRA-001: Inventory reorder automation SHALL be declared as `InventoryReorderRule` register with per-location, per-supplier granularity

Inventory reorder automation MUST be expressed as a new register in
`lib/Settings/nextcloud_inventory_register.json` per ADR-024:

- `InventoryReorderRule` — reorder policy (item reference, location,
  min/max/reorder-point thresholds, lead-time awareness, alert policy,
  optional auto-PO flag, supplier reference).

This capability enables operators to define when inventory should be
reordered, at what quantities, and whether reorder should be automated
or manual. Reorder rules are **per-location** — a single item may have
different min/max levels across warehouses based on local demand
velocity.

#### Scenario: Reviewer confirms no parallel reorder table

- **GIVEN** the nextcloud-inventory codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `reorder_rule`,
  `reorder_*`, `min_max_*`, `stock_alert`, or `procurement_automation_*`
- **THEN** no such classes SHALL exist.

#### Scenario: Reorder rule references existing inventory stock location

- **GIVEN** an `InventoryStock` record with sku="SKU-001" location="Amsterdam"
- **WHEN** an `InventoryReorderRule` is created for that stock
- **THEN** the rule MUST carry `inventoryStockId = <UUID of the InventoryStock>`,
  and the FK MUST resolve via OR's relation engine.

### REQ-IRA-002: The `InventoryReorderRule` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `InventoryReorderRule` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `ruleId` | string (UUID) | Yes | Stable rule identifier |
| `inventoryStockId` | string (FK to InventoryStock) | Yes | Reference to the stock record (item + location) |
| `supplierId` | string (FK to Supplier) | No | Primary supplier for reorder |
| `minimumLevel` | number ≥ 0 | Yes | Stock quantity below which alert triggers |
| `maximumLevel` | number > minimumLevel | Yes | Stock quantity target for replenishment |
| `reorderPoint` | number ≥ minimumLevel | Yes | Stock quantity at which reorder is triggered (factors lead time) |
| `reorderQuantity` | number > 0 | Yes | Standard quantity to order |
| `leadTimeDays` | integer ≥ 0 | No | Supplier lead time in days (defaults to supplier.leadTimeDays if null) |
| `safetyStockDays` | integer ≥ 0 | No | Safety margin in days above lead time (default: 1 day) |
| `alertThreshold` | number ≥ 0 | No | Percentage above minimum at which warning alert fires (default: 20%) |
| `autoPurchaseOrder` | boolean | Yes (default: false) | Whether to auto-generate PO on alert |
| `autoPurchaseOrderApprovalRequired` | boolean | No (default: true) | Whether auto-generated PO requires operator approval |
| `spendingLimit` | number ≥ 0 | No | Maximum spend per auto-generated PO; if exceeded, blocks auto-order |
| `alertChannel` | enum | No | Notification channel: email, dashboard, slack, webhook (default: dashboard) |
| `alertRecipients` | array of string | No | Email addresses or user IDs to notify |
| `snoozeUntil` | datetime | No | Temporarily suppress alerts until this datetime |
| `lifecycleState` | enum | Yes | One of `active`, `paused`, `archived` |
| `administrationId` | string | Yes | FK to administration |
| `createdAt` | datetime | Yes | Rule creation timestamp |
| `updatedAt` | datetime | Yes | Rule last-modified timestamp |

Schema.org annotation: `schema:Thing`.

#### Scenario: Schema validator accepts a minimal reorder rule

- **GIVEN** the schema
- **WHEN** `{inventoryStockId:"stock-1", minimumLevel:10, maximumLevel:100, reorderPoint:25, reorderQuantity:50, lifecycleState:"active", administrationId:"adm-1"}` is saved
- **THEN** validation MUST pass; `leadTimeDays` defaults to supplier.leadTimeDays; `autoPurchaseOrder` defaults to false.

#### Scenario: Reorder point must be >= minimum level

- **GIVEN** a rule with minimumLevel=10
- **WHEN** attempting to save with reorderPoint=5
- **THEN** the save MUST fail with a "reorderPoint must be ≥ minimumLevel" validation error.

### REQ-IRA-003: Low-stock alert SHALL fire when inventory falls below or equals minimum level

The system SHALL satisfy this requirement: Low-stock alert SHALL fire when inventory falls below or equals minimum level.

When `InventoryStock.quantity ≤ InventoryReorderRule.minimumLevel`,
a low-stock alert MUST be generated and dispatched according to the
rule's alert policy. The alert MUST include:

- Item SKU, name, and current quantity
- Reorder quantity and suggested supplier
- Action links: "Order Now" (if auto-PO enabled), "Snooze", "Update Rule"
- Timestamp and administration context

#### Scenario: Alert fires at minimum threshold

- **GIVEN** an `InventoryReorderRule` with minimumLevel=50
- **WHEN** `InventoryStock.quantity` transitions from 51 → 50
- **THEN** a low-stock alert MUST be generated immediately.

#### Scenario: Alert includes reorder-now action link if auto-PO enabled

- **GIVEN** a rule with autoPurchaseOrder=true
- **WHEN** the alert is dispatched
- **THEN** the alert message MUST include an "Order Now" action link.

### REQ-IRA-004: Low-stock alert workflow SHALL consume OpenRegister's notification engine

Low-stock alerts MUST be dispatched via OR's `x-openregister-notifications`
extension (per ADR-022). The lifecycle action on `InventoryReorderRule`
fires when quantity ≤ minimumLevel and creates a notification record
with:

- `notificationType: "inventory-low-stock"`
- `recipient` = rule's alertRecipients
- `channels` = [rule's alertChannel]
- `payload` = {item, location, quantity, reorderQuantity, supplier}
- `actionDeadline` = null (no deadline; operator acts at convenience)

If OR's notification extension is not yet stable, ADR-031's exception
path applies: a single-method `NotificationGuard` (~30 LOC) ships to
dispatch notifications via email queue or webhook fallback.

#### Scenario: Notification dispatches via configured channels

- **GIVEN** a rule with alertChannel="email" and alertRecipients=["ops@nl.example"]
- **WHEN** the low-stock alert fires
- **THEN** OR's notification engine MUST dispatch an email to ops@nl.example
  within 5 minutes.

#### Scenario: Notification action links resolve to correct pages

- **GIVEN** a low-stock alert on item SKU-001 location Amsterdam
- **WHEN** operator clicks "Update Rule"
- **THEN** the browser navigates to the InventoryReorderRule detail page
  for that rule.

### REQ-IRA-005: Reorder point calculation SHALL factor supplier lead time

The reorder-point threshold MUST be computed to prevent stock depletion
during the supplier lead-time window:

```
reorderPoint = (expectedDailyUsage × leadTimeDays) + safetyStock
```

Where:

- `expectedDailyUsage` = operator-configured or estimated from recent
  stock movements (T1 uses operator config; T2 adds estimation)
- `leadTimeDays` = rule's leadTimeDays field, or supplier.leadTimeDays if null
- `safetyStock` = rule's safetyStockDays × expectedDailyUsage (default: 1 day)

If `expectedDailyUsage` is not available, `reorderPoint` defaults to
`minimumLevel + (leadTimeDays × 5 units/day + 10 units safety)`. Operator
may override reorderPoint manually in the UI.

#### Scenario: Lead time is factored into reorder point

- **GIVEN** a rule with leadTimeDays=5, minimumLevel=20, and expectedDailyUsage=10
- **WHEN** reorderPoint is calculated
- **THEN** reorderPoint MUST be ≥ 20 + (5 × 10) = 70 units.

#### Scenario: Missing daily usage defaults to conservative estimate

- **GIVEN** a rule with leadTimeDays=7 and no expectedDailyUsage
- **WHEN** reorderPoint is calculated
- **THEN** reorderPoint MUST be ≥ minimumLevel + (7 × 5) + 10 = minimumLevel + 45.

### REQ-IRA-006: Optional auto-purchase-order generation materialises balanced purchase orders

The system SHALL satisfy this requirement: Optional auto-purchase-order generation materialises balanced purchase orders.

If `InventoryReorderRule.autoPurchaseOrder = true`, when the low-stock
alert fires, the system MUST automatically create a `PurchaseOrder` with:

- `orderNumber` = auto-generated per administration
- `orderDate` = today
- `deliveryDate` = today + rule.leadTimeDays
- `quantity` = rule.reorderQuantity
- `supplier` = rule.supplierId
- `totalPrice` = rule.reorderQuantity × supplier.currentPrice
- `status` = "draft" (if approval required) or "issued" (if auto-approved per policy)
- `source` = "auto-reorder" (audit trail)

The PO MUST satisfy `totalPrice ≤ rule.spendingLimit` (if set); if exceeded,
the auto-order blocks and escalates to operator approval.

The PO materialisation pattern reuses the T1 PurchaseOrder contract from
purchaseq. Operator approval gate is optional per administration policy
(stored as `autoPurchaseOrderApprovalRequired`).

#### Scenario: Auto-PO is created on alert if rule enabled

- **GIVEN** an InventoryReorderRule with autoPurchaseOrder=true and
  reorderQuantity=100
- **WHEN** the low-stock alert fires
- **THEN** a PurchaseOrder MUST be materialised with quantity=100,
  status="draft" (if approval required), and source="auto-reorder".

#### Scenario: Auto-PO is blocked if exceeds spending limit

- **GIVEN** a rule with autoPurchaseOrder=true, spendingLimit=€1000,
  and reorderQuantity × supplier.price = €1500
- **WHEN** the alert fires
- **THEN** the auto-order MUST be blocked; an escalation notification
  MUST be sent to role='procurement-manager'.

### REQ-IRA-007: Reorder rule aggregation SHALL evaluate stock against minimum in real-time

A `x-openregister-aggregations` query MUST evaluate the reorder trigger
condition:

```
SUM(InventoryStock.quantity WHERE inventoryStockId = this.inventoryStockId)
  ≤ this.reorderPoint
```

This aggregation fires on every InventoryStock quantity change; if true,
the low-stock alert lifecycle action is triggered. No PHP service; pure
aggregation logic.

#### Scenario: Aggregation evaluates on every quantity change

- **GIVEN** an InventoryReorderRule for stock SKU-001 location Amsterdam
  with reorderPoint=50
- **WHEN** InventoryStock.quantity changes from 55 → 48
- **THEN** the aggregation MUST evaluate to true and trigger the
  low-stock alert lifecycle action.

### REQ-IRA-008: Dashboard widget SHALL aggregate below-minimum items by location

A manifest aggregation query SHALL group `InventoryStock` records
where `quantity ≤ linkedRule.minimumLevel` by location:

```
GROUP BY location
SELECT location, COUNT(*), SUM(minimumLevel - quantity) as deficit
FROM InventoryStock
WHERE EXISTS(InventoryReorderRule.minimumLevel > InventoryStock.quantity)
ORDER BY deficit DESC
```

The dashboard widget displays:

- Location name
- Item count below minimum
- Total deficit quantity (opportunity to restock)
- Quick-action links to reorder-rule detail page

#### Scenario: Dashboard aggregates low-stock items by location

- **GIVEN** 3 InventoryStock records below minimum:
  - Amsterdam: SKU-001 (deficit 20 units), SKU-002 (deficit 15 units)
  - Rotterdam: SKU-003 (deficit 10 units)
- **WHEN** the low-stock dashboard loads
- **THEN** the widget MUST display Amsterdam (2 items, 35-unit deficit),
  Rotterdam (1 item, 10-unit deficit).

### REQ-IRA-009: Reorder rules SHALL support lifecycle state management

`InventoryReorderRule` MUST declare an `x-openregister-lifecycle` block with:

| From | To | Trigger | Guard |
|---|---|---|---|
| `active` | `paused` | operator action | alert dispatch disabled; no auto-PO until resumed |
| `paused` | `active` | operator action | resume alert monitoring |
| `active` | `archived` | operator action | rule deactivated; no further use |
| `archived` | `active` | operator action (restore) | rule re-activated |

The lifecycle transitions are audit-trailed. Only `active` rules fire
low-stock alerts.

#### Scenario: Paused rule does not fire alerts

- **GIVEN** an InventoryReorderRule in state `paused`
- **WHEN** InventoryStock.quantity falls below minimumLevel
- **THEN** no alert SHALL be generated.

### REQ-IRA-010: Manifest navigation SHALL provide rule management and dashboards

The manifest MUST declare 3 navigation entries:

1. **Reorder Rules** (`type: index`) — list all `InventoryReorderRule`
   records (active + archived) with filters by location, supplier,
   lifecycle state. Detail page (`type: detail`) shows full rule form
   with all fields editable per REQ-IRA-002. Action buttons: Edit,
   Pause/Resume, Archive, Delete (if no active orders).

2. **Low Stock Alerts** (`type: index`) — list recent low-stock alert
   events (last 30 days) grouped by item + location. Detail page shows
   alert context, action history, and manual order-now button.
   Dismissible; optional snooze until datetime.

3. **Stock Levels Dashboard** (`type: dashboard`) — aggregated view per
   REQ-IRA-008, showing locations + item counts + total deficit.
   Quick-action links to reorder-rule detail + order-now for top
   deficits.

All three pages use OpenRegister list + detail views (no custom Vue
components). Operator reads manifest navigation to access rules, monitor
alerts, and act on low-stock conditions.

#### Scenario: Operator can create new reorder rule from UI

- **GIVEN** the Reorder Rules index page
- **WHEN** operator clicks "New Rule"
- **THEN** the detail page opens in create mode; operator fills
  inventoryStockId, minimumLevel, maximumLevel, reorderPoint, etc.;
  save creates the rule.

#### Scenario: Low Stock Alerts index shows recent alerts

- **GIVEN** 5 low-stock alerts generated in the last 7 days
- **WHEN** operator opens Low Stock Alerts
- **THEN** the index lists all 5 with timestamps, item names, and
  "Order Now" / "Snooze" action buttons.

## MODIFIED Requirements

None. This is a spec-only addition; no existing registers are changed.

## REMOVED Requirements

None. This is a spec-only addition; no capabilities are removed.
