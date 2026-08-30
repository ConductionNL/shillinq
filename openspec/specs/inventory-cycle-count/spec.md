---
status: done
---

# Spec: inventory-cycle-count

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (inventory operations)
**Depends on:** `inventory-stock-tracking` (T2 baseline for `InventoryStock`),
`cost-accounting-allocation` (T2 GL impact via variance posting)

## Purpose

This specification defines the requirements for inventory cycle count in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/schema: cycle count register — not browser-testable


### REQ-ICC-001: Inventory Cycle Count SHALL be declared as `InventoryCycleCount` + `InventoryCycleCountLine` registers

Inventory cycle count MUST be expressed as two new registers in `lib/Settings/shillinq_register.json`:

- `InventoryCycleCount` — count batch (count date, location scope, initiator, line aggregate values).
- `InventoryCycleCountLine` — line-item detail (expected qty, counted qty, variance, reason code FK).

A cycle count MUST NOT duplicate inventory data; instead it SHALL snapshot `InventoryStock` 
at count-start time and record counted quantities as operator input. Variance (counted − 
expected) is a calculated field.

Per ADR-024, no custom database tables in `lib/Db/`. Per ADR-031, variance calculation 
is declarative or (if conditional logic required) guarded by a single exception-annotated 
method.

#### Scenario: No parallel inventory-count table exists

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml` declarations naming 
  `inventory_cycle_count`, `stock_count`, `physical_count`, or `count_line`
- **THEN** no such classes or declarations SHALL exist outside the OpenRegister 
  register manifest.

#### Scenario: Count line variance is calculated, not stored

- **GIVEN** a cycle count line with `expectedQuantity: 100` and `countedQuantity: 95`
- **WHEN** the count is submitted
- **THEN** the register MUST compute and display `quantityVariance: -5` and `valueVariance` 
  as a derived field (not manually input).

### REQ-ICC-002: The `InventoryCycleCount` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `InventoryCycleCount` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `countId` | string | Yes | Unique count identifier, auto-generated (CC-YYYY-MM-NNNNN format) |
| `countDate` | date | Yes | Physical count date |
| `initiatedBy` | string | Yes | FK to Person initiating the count |
| `countType` | enum | Yes | `full` (all SKUs) or `partial` (scoped to location/category) |
| `locationFilter` | string | No | Location code if `countType = partial` (FK to Location or string) |
| `categoryFilter` | string | No | Product category if `countType = partial` |
| `expectedValue` | number | No | Book value of in-scope inventory at count start (calculated from line snapshot) |
| `countedValue` | number | No | Physical value (sum of `line.countedValue`); null until count complete |
| `varianceValue` | number | No | Calculated: `countedValue − expectedValue` |
| `variancePercentage` | number | No | Calculated: `(varianceValue / expectedValue) × 100` |
| `state` | enum | Yes | One of `draft`, `submitted`, `counting`, `posted`, `reconciled`, `cancelled` |
| `notes` | string | No | Supervisor or investigator notes |
| `administrationId` | string | Yes | FK to Administration |

Schema.org annotation: `schema:InventoryCount` (custom extension).

#### Scenario: Schema validator accepts a minimal full-count

- **GIVEN** the schema
- **WHEN** `{countId:"CC-2026-05-00001", countDate:"2026-05-20", initiatedBy:"user-1", 
  countType:"full", state:"draft", administrationId:"adm-1"}` is saved
- **THEN** validation MUST pass; `expectedValue` and `countedValue` MUST be null until count 
  submitted.

#### Scenario: Partial count requires a location or category filter

- **GIVEN** the schema
- **WHEN** attempting to create a count with `countType:"partial"` but neither `locationFilter` 
  nor `categoryFilter` populated
- **THEN** validation MUST fail with a "partial count requires location or category scope" 
  error.

### REQ-ICC-003: The `InventoryCycleCountLine` schema SHALL declare expected-vs-counted structure

The system SHALL satisfy this requirement: The `InventoryCycleCountLine` schema SHALL declare expected-vs-counted structure.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `lineId` | string | Yes | Unique line identifier within count (CC-YYYY-MM-NNNNN-LLL format) |
| `countId` | string | Yes | FK to InventoryCycleCount |
| `sku` | string | Yes | Product SKU |
| `productName` | string | No | Denormalized product name from Product |
| `expectedQuantity` | number | Yes | Qty from `InventoryStock` snapshot at count start |
| `countedQuantity` | number | No | Qty physically counted; null until operator enters data |
| `unitCost` | number | Yes | Unit cost at count date (for variance valuation) |
| `expectedValue` | number | Yes | Calculated: `expectedQuantity × unitCost` |
| `countedValue` | number | No | Calculated: `countedQuantity × unitCost`; null until counted |
| `quantityVariance` | number | No | Calculated: `countedQuantity − expectedQuantity` |
| `valueVariance` | number | No | Calculated: `countedValue − expectedValue` |
| `requiresReason` | boolean | No | Calculated: true if `|quantityVariance| > variance threshold` (per REQ-ICC-004) |
| `reasonCode` | string | No | FK to InventoryVarianceReason; mandatory if `requiresReason = true` |
| `notes` | string | No | Line-level investigation or annotation |

#### Scenario: Expected quantity populated from InventoryStock snapshot

- **GIVEN** an `InventoryCycleCount` transitioned to `submitted` for `countType: full`
- **WHEN** the `InventoryCycleCountLine` records are created
- **THEN** each line MUST have `expectedQuantity` populated from the current 
  `InventoryStock.quantity` for that SKU; no line SHALL remain without an expected quantity.

#### Scenario: Variance fields null until count is entered

- **GIVEN** a cycle count line at creation with `expectedQuantity: 50` and no counted input
- **WHEN** the line is inspected
- **THEN** `countedQuantity`, `countedValue`, `quantityVariance`, and `valueVariance` MUST 
  all be null; `requiresReason` MUST be false.

#### Scenario: Schema rejects negative quantities

- **GIVEN** the schema
- **WHEN** attempting to enter `countedQuantity: -5`
- **THEN** validation MUST fail with a "negative quantity not allowed" error.

### REQ-ICC-004: Variance threshold flagging SHALL categorize lines requiring investigation

The system SHALL satisfy this requirement: Variance threshold flagging SHALL categorize lines requiring investigation.

Lines with absolute quantity variance exceeding a configurable threshold OR cost variance 
exceeding an absolute threshold MUST be auto-flagged with `requiresReason = true`, 
requiring a reason code before the count can transition to `posted` state.

Thresholds are configured per administration in the register metadata:
- `quantityVarianceThresholdPercent`: default 5% (configurable 0–100)
- `valueVarianceThresholdAbsolute`: default €500 (configurable 0–∞)

The flagging condition is:
```
requiresReason = true IF (|quantityVariance| > expectedQuantity × quantityVarianceThresholdPercent / 100) 
                     OR (|valueVariance| > valueVarianceThresholdAbsolute)
```

#### Scenario: Small variance under threshold not flagged

- **GIVEN** configuration: `quantityVarianceThresholdPercent: 5%`, `valueVarianceThresholdAbsolute: €500`
- **WHEN** a line has `expectedQuantity: 100`, `countedQuantity: 99` (variance: -1, 
  -1%), `unitCost: €40`, `valueVariance: -€40`
- **THEN** `requiresReason` MUST be false; the line does not require a reason code.

#### Scenario: Variance exceeding % threshold flagged

- **GIVEN** configuration: `quantityVarianceThresholdPercent: 5%`
- **WHEN** a line has `expectedQuantity: 100`, `countedQuantity: 94` (variance: -6, 
  -6%), `unitCost: €40`
- **THEN** `requiresReason` MUST be true; the line MUST have a `reasonCode` before count 
  posts.

#### Scenario: Variance exceeding cost threshold flagged

- **GIVEN** configuration: `valueVarianceThresholdAbsolute: €500`
- **WHEN** a line has `expectedQuantity: 100`, `countedQuantity: 50` (variance: -50), 
  `unitCost: €15`, `valueVariance: -€750`
- **THEN** `requiresReason` MUST be true despite quantity % being low.

### REQ-ICC-005: The `InventoryVarianceReason` register SHALL provide configurable reason-code taxonomy

The system SHALL satisfy this requirement: The `InventoryVarianceReason` register SHALL provide configurable reason-code taxonomy.

`InventoryVarianceReason` is a configurable register (not a hardcoded enum) allowing 
organizations to define and customize variance categorization. Each organization (per 
administration) maintains its own reason-code set.

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `reasonId` | string | Yes | Reason code (e.g., `DMG`, `OBS`, `ERR-COUNT`, `THEFT`) |
| `name` | string | Yes | Human-readable reason name (e.g., "Damaged Goods", "Obsolescence") |
| `category` | enum | Yes | Coarse category: `damage`, `loss`, `obsolescence`, `error-counting`, `error-stocking`, `system-discrepancy`, `other` |
| `description` | string | No | Extended explanation for audit trail |
| `isActive` | boolean | Yes | Reason available for use on new counts |
| `administrationId` | string | Yes | FK to Administration |

System-seed default reason codes (per administration creation):
- `DMG` / "Damaged Goods" / `damage`
- `OBS` / "Obsolescence" / `obsolescence`
- `ERR-COUNT` / "Counting Error" / `error-counting`
- `ERR-STOCK` / "Stocking Error" / `error-stocking`
- `THEFT` / "Loss/Theft" / `loss`
- `SYS` / "System Discrepancy" / `system-discrepancy`
- `OTHER` / "Other" / `other`

#### Scenario: Reason code is mandatory for flagged lines

- **GIVEN** a line with `requiresReason: true`
- **WHEN** attempting to transition the count to `posted` without populating `reasonCode`
- **THEN** the transition MUST fail with a "variance investigation required; select a reason 
  code" error; the line MUST be re-opened for investigation.

#### Scenario: Inactive reason code cannot be selected

- **GIVEN** a reason code `OBS` with `isActive: false`
- **WHEN** an operator attempts to assign it to a line
- **THEN** the schema MUST not allow assignment; the UI MUST display only active reason codes.

### REQ-ICC-006: The `InventoryCycleCount` lifecycle SHALL manage state transitions via `x-openregister-lifecycle`

`InventoryCycleCount` MUST declare an `x-openregister-lifecycle` block with the following 
state machine:

| From | To | Trigger | Guard |
|------|----|---------| ------|
| `draft` | `submitted` | Supervisor submits | Count scope (location/category) is valid if `countType = partial` |
| `submitted` | `counting` | Warehouse staff begin | Snapshot of `InventoryStock` taken; line items created with `expectedQuantity` populated |
| `counting` | `posted` | Supervisor completes review | All lines with `requiresReason = true` MUST have a `reasonCode` populated (REQ-ICC-004, REQ-ICC-005) |
| `posted` | `reconciled` | Finance posts GL variance | Per REQ-ICC-007, `InventoryAdjustment` records created; `InventoryStock` updated; GL posted |
| `draft` / `submitted` / `counting` / `posted` | `cancelled` | Any role | Count superseded or error detected |

No PHP service implements state transitions. Per ADR-031, the lifecycle is declared in 
the schema (or guarded by a single-method exception if conditional logic is required).

#### Scenario: Submitting a count creates line-item snapshot

- **GIVEN** an `InventoryCycleCount` in state `draft` for a full count
- **WHEN** the supervisor submits it
- **THEN** the count MUST transition to `submitted`; **AND** `InventoryCycleCountLine` 
  records MUST be automatically created, one per SKU in the full inventory (or scoped 
  inventory if partial), with `expectedQuantity` populated from `InventoryStock` and 
  `countedQuantity` null.

#### Scenario: Cannot post count with unflagged variance lines

- **GIVEN** a count in state `counting` with a line where `requiresReason: true` but 
  `reasonCode: null`
- **WHEN** supervisor attempts to transition to `posted`
- **THEN** the transition MUST fail; the UI MUST highlight the line requiring investigation.

#### Scenario: Cancelling a count at any state

- **GIVEN** a count in state `counting`
- **WHEN** supervisor cancels (e.g., discrepancy discovered, count to be re-done)
- **THEN** count state MUST become `cancelled`; no GL posting occurs; counts can be 
  re-initiated if needed.

### REQ-ICC-007: Variance posting SHALL generate `InventoryAdjustment` records and update `InventoryStock`

On transition to `reconciled`, the system MUST:

1. Create one `InventoryAdjustment` record per count-line with non-zero variance.
2. Update `InventoryStock.quantity` for each SKU: 
   `new_quantity = old_quantity + (countedQuantity − expectedQuantity)`.
3. Post GL impact: variance expense account debit/credit with cost-center allocation (per 
   cost-accounting-allocation spec) and reason-code FK for audit trail.
4. Record the cycle-count UUID as a reference in the adjustment for full traceability.

If multiple counts are in-flight for overlapping SKUs, adjustments MUST be serialized 
(first-in-first-posted) to prevent phantom qty updates. Conflicts are detected and 
reported.

#### Scenario: Variance adjustment updates inventory

- **GIVEN** an `InventoryCycleCount` in state `posted` with a line: 
  `sku: SKU-001`, `expectedQuantity: 100`, `countedQuantity: 95`, `unitCost: €50`, 
  `reasonCode: ERR-COUNT`
- **WHEN** the count transitions to `reconciled`
- **THEN** `InventoryStock` for SKU-001 MUST be updated: 
  `new_quantity = 100 + (95 − 100) = 95`; **AND** `InventoryAdjustment` MUST record 
  the variance with reason-code `ERR-COUNT` for GL audit trail.

#### Scenario: GL posting reflects reason code

- **GIVEN** an adjustment with `valueVariance: -€500` and `reasonCode: DMG`
- **WHEN** the adjustment is posted
- **THEN** the GL line MUST include the reason code and cost-center allocation; the 
  variance-expense account MUST be debited/credited accordingly (per cost-accounting-
  allocation).

### REQ-ICC-008: Partial (location/zone-scoped) counts SHALL filter expected inventory via standard register queries

When `countType: partial`, the system MUST:

1. Accept a location code (`locationFilter`) or product-category code (`categoryFilter`).
2. Query `InventoryStock` using standard OR filters (e.g., `InventoryStock.location = 
   "warehouse-a"`).
3. Create `InventoryCycleCountLine` records only for the filtered results.
4. Snapshot `expectedValue` based on filtered inventory only.

No bespoke indexing or custom SQL queries are required; standard OR query filters suffice.

#### Scenario: Partial count by location

- **GIVEN** a request to create a partial count for `locationFilter: "warehouse-a"`
- **WHEN** the count is submitted
- **THEN** `InventoryCycleCountLine` records MUST be created only for SKUs in warehouse-a 
  (scoped via `InventoryStock.location` filter); `expectedValue` MUST reflect the 
  warehouse-a inventory value only.

#### Scenario: Full count includes all locations

- **GIVEN** a count with `countType: full` and no location/category filter
- **WHEN** the count is submitted
- **THEN** line items MUST cover all SKUs in all locations; no filtering applied.

### REQ-ICC-009: Mobile-scanner integration SHALL be documented as a webhook endpoint (integration deferred to T4)

The system SHALL satisfy this requirement: Mobile-scanner integration SHALL be documented as a webhook endpoint (integration deferred to T4).

The spec documents the webhook shape for future mobile-app integration. Mobile-scanner 
integration is deferred to T4; the primary path is manual count-line entry.

Documented webhook endpoint (future T4 implementation):
```
POST /api/cycle-count/{countId}/count-line

Request body:
{
  "sku": "SKU-4521",
  "countedQuantity": 145,
  "timestamp": "2026-05-20T14:30:00Z",
  "deviceId": "scanner-001"
}

Response:
{
  "lineId": "CC-2026-05-00001-001",
  "countId": "CC-2026-05-00001",
  "sku": "SKU-4521",
  "countedQuantity": 145,
  "status": "recorded"
}
```

Manual count-line entry via the UI is the primary path (REQ-ICC-003, REQ-ICC-006).

#### Scenario: Webhook shape documented, not implemented in T2

- **GIVEN** the spec
- **WHEN** reviewed for T2 scope
- **THEN** the webhook endpoint MUST be documented in `design.md` as a future integration 
  point; **AND** no T2 implementation code SHALL exist; **AND** manual entry SHALL be the 
  primary counting method.

### REQ-ICC-010: Manifest navigation SHALL expose three index + detail pages for cycle counts, templates, and variance reports

The `src/manifest.json` MUST declare:

1. **Cycle Counts** (`/cycle-count`) — list index showing all counts (past, current, 
   cancelled) with state, date, location (if partial), variance %, and action buttons 
   (view, edit draft, cancel).
2. **Count Templates** (`/count-template`) — list of recurring count configurations (full 
   annual, monthly zone-a, etc.) for schedule management.
3. **Variance Reports** (`/variance-report`) — aggregated view of all variances (by reason 
   code, by location, by SKU) for trend analysis and audit.

Detail pages expose full count detail (lines, reasons, notes, GL audit trail).

#### Scenario: Cycle Count index page displays variances

- **GIVEN** the manifest declares `/cycle-count` index
- **WHEN** the page loads
- **THEN** it MUST display a list of all counts with columns: countId, countDate, 
  countType, variancePercentage, state; filtering by state, date range, and location 
  MUST be available.

#### Scenario: Variance report aggregates by reason code

- **GIVEN** five counts with variances distributed across `DMG`, `OBS`, and `ERR-COUNT`
- **WHEN** the variance report is opened
- **THEN** it MUST show aggregated counts/amounts by reason code; a drill-down to 
  individual lines MUST be available.

## Reuse / Existing Entities

This spec declares two new entities (`InventoryCycleCount`, `InventoryVarianceReason`) 
and introduces one new entity-linking (`InventoryAdjustment` references `InventoryCycleCount`).

Existing entities consumed:
- **InventoryStock** — per `inventory-stock-tracking`; snapshot taken at count start.
- **Product** — denormalized productName + unitCost sourced from Product detail.
- **Location** — optional FK for location-scoped partial counts.
- **Account** — variance-expense account for GL posting.
- **CostCenter** — cost allocation on variance GL posting.
- **Person** — initiator + supervisor roles.
- **Administration** — multi-tenant scope for all counts and reason codes.

New entity introduced (deferred to ADR-000 reconciliation in a follow-up cycle):
- **InventoryAdjustment** — adjustment transaction linking counts to GL impact (stub 
  reference in this spec; full definition in separate inventory-adjustment spec).

No existing entities are modified. The `InventoryStock.quantity` field is updated on 
reconciliation (per REQ-ICC-007), but the schema definition is not changed.
