# Design — Inventory Cycle Count / Stock-take

<!-- status: pr-created -->
<!-- pr: Codeberg shillinq PR #294 (pre-migration, not migrated to GitHub) -->

## Context

Dutch SMBs use cycle counts to manage shrinkage, obsolescence, and counting errors. 
Cycle counting with variance reason codes is table-stakes in mid-market inventory systems. 
Per the intelligence database, 16/22 competitors offer cycle counts with variance 
categorization and GL posting.

This change is **spec-only**. Implementation lands later through `opsx-apply` and the 
standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire cycle-count surface as **declarative metadata** — schemas + lifecycle 
  + line variance calculation + manifest entries — per ADR-031.
- Make the spec a **warehouse-supervisor-readable contract** — count initiation, line-
  item variance flagging, reason-code attachment, GL posting, reconciliation.
- Support both **full inventory counts** (all SKUs) and **partial counts** (by location, 
  zone, or category filter) without destructive schema changes.
- Keep variance reason codes as a **configurable register** (not hardcoded enums) so 
  organizations customize categorization (damage, shrinkage, obsolescence, etc.).

## Non-Goals

- No PHP count-service orchestration; no `CycleCountService.php`.
- No barcode-scanning logic (barcode generation is separate).
- No mobile-scanner app (deferred to T4; webhook shape documented for future integration).
- No automated scheduling (manual trigger is primary; calendar scheduling is T3).
- No multi-currency variance revaluation (T5).

## Decisions

### D1 — Cycle Count is a register with line items, not a transaction log

`InventoryCycleCount` is a top-level register carrying a count batch: count date, 
location scope (optional), initiating user, and an array of line items (one per SKU in 
scope). Each line holds expected qty (from `InventoryStock` snapshot at count start), 
counted qty (operator input), and variance (calculated field: counted − expected).

**Alternative considered**: Immutable transaction log of individual count observations 
(mobile scanner events). Rejected — the line-item register shape supports batch review 
before posting and is aligned with GL materialization (one `InventoryAdjustment` per 
count, not per scan event).

### D2 — Variance reason codes are a configurable register, not hardcoded enums

`InventoryVarianceReason` is a sister register carrying the categorization taxonomy 
(damage, theft, writing error, obsolescence, stocking error, count error, system 
discrepancy, other). Organizations can extend or customize (per ADR-022 company decision 
on OR contact abstraction — allows flexibility in reason-code governance).

**Alternative considered**: Hardcoded enum in the schema. Rejected — Dutch SMB tax 
auditors want variance reasons on the GL audit trail; a configurable register allows 
audit-ready categorization.

### D3 — Variance threshold flagging is declarative policy

Lines with qty variance > configurable threshold (e.g., > 5%) or cost variance > absolute 
threshold (e.g., > €500) are auto-flagged in the UI and require a reason code before the 
count can be posted. The thresholds are stored in the register config, not hardcoded.

If OR's `x-openregister-lifecycle.requires` cannot express conditional logic (e.g., "if 
variance > threshold then reason-code is required"), ADR-031's exception path applies: a 
single-method `InventoryVarianceGate` (~30 LOC, no state).

### D4 — Variance posting updates InventoryStock + materializes GL

On `InventoryCycleCount.reconcile`, each line with variance generates an 
`InventoryAdjustment` record, updating `InventoryStock.quantity` and materializing GL 
impact (debit/credit to variance expense account, with reason-code FK for audit trail). 
The same lifecycle pattern as T1 JournalEntry → GLTransaction.

### D5 — Partial counts use standard register filters, no custom indexing

Location-scoped or zone-scoped counts apply a filter to `InventoryStock` (e.g., 
`location: "warehouse-A"`) at count creation, returning only SKUs in that location. No 
special indices required; standard OR query filters suffice.

### D6 — Mobile-scanner integration deferred; webhook shape documented

The spec documents a POST `/cycle-count/{id}/count-line` webhook endpoint shape (JSON 
payload with `sku`, `countedQty`, `timestamp`) for future T4 mobile-app integration. The 
primary path is manual count-line entry via the UI.

## Data Model

### InventoryCycleCount

Represents a single inventory count batch.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `countId` | string | Yes | Unique count identifier (auto-generated: CC-YYYY-MM-NNNNN) |
| `countDate` | date | Yes | Date count was performed |
| `initiatedBy` | string | Yes | FK to Person (user initiating count) |
| `countType` | enum | Yes | `full` or `partial` |
| `locationFilter` | string | No | Location/zone code if partial count (FK to Location) |
| `categoryFilter` | string | No | Product category if partial count |
| `expectedValue` | number | No | Total book value of in-scope inventory at count date |
| `countedValue` | number | No | Total physical value (sum of lines: counted qty × unit cost) |
| `varianceValue` | number | No | Calculated: countedValue − expectedValue |
| `variancePercentage` | number | No | Calculated: (varianceValue / expectedValue) × 100 |
| `state` | enum | Yes | One of `draft`, `submitted`, `counting`, `posted`, `reconciled`, `cancelled` |
| `notes` | string | No | Supervisor notes |
| `administrationId` | string | Yes | FK to Administration |

### InventoryCycleCountLine

Line items within a count.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `lineId` | string | Yes | Unique line identifier within count |
| `countId` | string | Yes | FK to InventoryCycleCount |
| `sku` | string | Yes | Product SKU |
| `productName` | string | No | Denormalized product name |
| `expectedQuantity` | number | Yes | Qty from InventoryStock at count start |
| `countedQuantity` | number | No | Qty counted by operator (null until counted) |
| `unitCost` | number | Yes | Unit cost at count date |
| `expectedValue` | number | Yes | Calculated: expectedQuantity × unitCost |
| `countedValue` | number | No | Calculated: countedQuantity × unitCost (null until counted) |
| `quantityVariance` | number | No | Calculated: countedQuantity − expectedQuantity |
| `valueVariance` | number | No | Calculated: countedValue − expectedValue |
| `requiresReason` | boolean | No | Calculated: true if |variance| exceeds threshold |
| `reasonCode` | string | No | FK to InventoryVarianceReason (mandatory if requiresReason) |
| `notes` | string | No | Line-level investigation notes |

### InventoryVarianceReason

Configurable reason-code registry.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `reasonId` | string | Yes | Unique reason code (e.g., `DMG`, `OBS`, `ERR-COUNT`) |
| `name` | string | Yes | Human-readable reason (e.g., "Damaged Goods") |
| `category` | enum | Yes | One of: `damage`, `loss`, `obsolescence`, `error-counting`, `error-stocking`, `system-discrepancy`, `other` |
| `description` | string | No | Extended explanation for audit trail |
| `isActive` | boolean | Yes | Reason is available for use |
| `administrationId` | string | Yes | FK to Administration (per-admin customization) |

## Sample Data (seed for design.md)

### InventoryCycleCount

```json
{
  "countId": "CC-2026-05-00001",
  "countDate": "2026-05-20",
  "initiatedBy": "user-001",
  "countType": "partial",
  "locationFilter": "warehouse-a",
  "categoryFilter": null,
  "expectedValue": 45000.00,
  "countedValue": 43750.00,
  "varianceValue": -1250.00,
  "variancePercentage": -2.78,
  "state": "counting",
  "notes": "Zone A quarterly count",
  "administrationId": "adm-2026"
}
```

### InventoryCycleCountLine

```json
{
  "lineId": "CC-2026-05-00001-001",
  "countId": "CC-2026-05-00001",
  "sku": "SKU-4521",
  "productName": "Industrial Bearing",
  "expectedQuantity": 150,
  "countedQuantity": 145,
  "unitCost": 45.00,
  "expectedValue": 6750.00,
  "countedValue": 6525.00,
  "quantityVariance": -5,
  "valueVariance": -225.00,
  "requiresReason": true,
  "reasonCode": "DMG",
  "notes": "Found 5 units damaged in northeast corner"
}
```

### InventoryVarianceReason

```json
{
  "reasonId": "DMG",
  "name": "Damaged Goods",
  "category": "damage",
  "description": "Physical damage discovered during count",
  "isActive": true,
  "administrationId": "adm-2026"
},
{
  "reasonId": "OBS",
  "name": "Obsolescence",
  "category": "obsolescence",
  "description": "Product no longer in active use; designated for write-off",
  "isActive": true,
  "administrationId": "adm-2026"
},
{
  "reasonId": "ERR-COUNT",
  "name": "Counting Error",
  "category": "error-counting",
  "description": "Recount revealed previous count was inaccurate",
  "isActive": true,
  "administrationId": "adm-2026"
}
```

## Lifecycle

```
draft → submitted → counting → posted → reconciled
                                  ↓
                            cancelled (any state)
```

- **draft → submitted**: Supervisor reviews expected counts and filters; triggers snapshot of 
  `InventoryStock` into line items.
- **submitted → counting**: Count staff begin data entry (manual + optional mobile-scanner 
  webhook updates).
- **counting → posted**: Supervisor reviews variance, flagged lines have reason codes 
  attached.
- **posted → reconciled**: Finance posts variance adjustments to GL; `InventoryAdjustment` 
  records created; `InventoryStock.quantity` updated.
- **cancelled**: Any state can cancel if count is superseded or error detected.

## Design Decisions Resolved

1. **Line-item register** (D1) — aligns with GL materialization + batch review workflow.
2. **Configurable reason codes** (D2) — audit-ready, per-org customization.
3. **Declarative variance threshold** (D3) — if guard required, single-method exception 
   (ADR-031).
4. **GL posting via InventoryAdjustment** (D4) — consistent with cost-accounting-allocation.
5. **Standard filters for partial counts** (D5) — no custom indexing.
6. **Webhook shape documented, integration T4** (D6) — future-ready architecture.
