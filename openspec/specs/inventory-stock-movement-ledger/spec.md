---
status: done
---

# Spec: inventory-stock-movement-ledger

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (inventory + operations)
**Depends on:** `inventory-stock-tracking` (InventoryStock updates),
`add-shillinq-general-ledger` (GL materialisation for receipt/issue COGS)

## Purpose

This specification defines the requirements for inventory stock movement ledger in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: stock movements index page not yet implemented


### REQ-SM-001: Stock movement SHALL be declared as `StockMove` register with double-entry semantics

Stock movement MUST be expressed as a new register in `lib/Settings/shillinq_register.json`
per ADR-024:

- `StockMove` — immutable record of atomic stock movement between two locations
  (source, destination), or between warehouse and supplier/customer. Every move
  captures: item reference, quantity, unit cost, movement type (receipt, transfer,
  issue, manufacture, repack), source location, destination location, reference
  document URI (PO, sales order, production plan), reason code, and audit trail.

This capability enables immutable, auditable stock tracking across multi-warehouse
environments per Dutch CTR and IAS 2 standards. Posting a `StockMove` MUST update
the `InventoryStock.quantity` for source and destination locations via OR's materialisation
extension (debit source, credit destination per Tryton pattern). GLTransaction materialisation
on posting SHALL generate balanced GL entries per movement type (receipt: debit inventory
asset; issue: debit COGS, credit inventory asset).

#### Scenario: Reviewer confirms no parallel stock_move table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `stock_move`, `stock_transaction`,
  `inventory_move`, or `warehouse_*`
- **THEN** no such classes SHALL exist.

#### Scenario: InventoryStock qty reconciles to StockMove balance

- **GIVEN** InventoryStock for item SKU-001 shows quantity 50 in warehouse W-01
- **WHEN** all posted, non-cancelled StockMoves touching W-01 are summed
  (destination minus source)
- **THEN** the sum MUST equal 50 (allowing for rounding to 2 decimals).

### REQ-SM-002: The `StockMove` schema SHALL declare a fixed minimum field set

The system SHALL satisfy this requirement: The `StockMove` schema SHALL declare a fixed minimum field set.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `movementNumber` | string | Yes | Shillinq-side sequential ID per administration |
| `itemId` | string | Yes | FK to Product (via InventoryStock SKU match) |
| `quantity` | number | Yes (≥ 0) | Units moved (must respect source location available qty on post) |
| `unitCost` | number | Yes (≥ 0) | Cost per unit in EUR; used for GL posting on issue |
| `movementType` | enum | Yes | One of: `receipt`, `transfer`, `issue`, `manufacture`, `repack` |
| `sourceLocationId` | string | No | FK to Location (null for receipt); must be different from destination if both present |
| `destinationLocationId` | string | No | FK to Location (null for issue); must be different from source if both present |
| `referenceDocumentUri` | string | No | URI to PO (receipt), sales order (issue), production plan (manufacture), or null (manual repack) |
| `movementReason` | enum | Yes | Admin-configurable reason code (damaged, expired, shrinkage, normal, inter-warehouse, etc.); mandatory on post |
| `notes` | string | No | Free-text notes for operator context |
| `draftedAt` | datetime | Yes | Timestamp when move was created |
| `postedAt` | datetime | No | Timestamp when move transitioned to `posted` (null until posting) |
| `cancelledAt` | datetime | No | Timestamp when move transitioned to `cancelled` (null unless cancelled) |
| `administrationId` | string | Yes | FK to administration |
| `locked` | boolean | No (default: false) | Immutability flag; true on posting, prevents edits. Cancellation creates offsetting move, not patch. |
| `lifecycleState` | enum | Yes | One of: `draft`, `posted`, `cancelled` |

Schema.org annotation: `schema:Event` (per shillinq config.yaml `rules.specs` — movement is a transaction).

#### Scenario: Schema validator accepts a minimal receipt

- **GIVEN** the schema
- **WHEN** `{movementNumber:"SM-2026-0001", itemId:"prod-123", quantity:100, unitCost:12.50, movementType:"receipt", destinationLocationId:"loc-warehouse-01", movementReason:"normal", administrationId:"adm-1", lifecycleState:"draft"}` is saved
- **THEN** validation MUST pass.

#### Scenario: sourceLocationId and destinationLocationId are mutually exclusive with type

- **GIVEN** a draft `receipt` movement
- **WHEN** sourceLocationId is provided
- **THEN** validation MUST fail with message "Receipt movement requires null sourceLocationId".

- **GIVEN** a draft `issue` movement
- **WHEN** destinationLocationId is provided
- **THEN** validation MUST fail with message "Issue movement requires null destinationLocationId".

### REQ-SM-003: Stock move lifecycle SHALL be `draft → posted → cancelled` with immutability lock

The system SHALL satisfy this requirement: Stock move lifecycle SHALL be `draft → posted → cancelled` with immutability lock.

`StockMove` declares a state machine per `x-openregister-lifecycle`:

- **draft**: operator creates move, can edit any field, quantity reserved from source location
  (optimistic lock on InventoryStock; CAS collision → operator retry).
- **posted**: operator confirms move; `locked = true`; quantity committed to destination;
  GL materialisation triggered (see REQ-SM-006); move is immutable thereafter. Edits rejected.
- **cancelled**: terminal state. Cancellation does NOT patch the posted move; instead, an
  offsetting `StockMove` is created (reverse quantity, same source/destination swapped) to
  preserve immutability. Original move remains queryable.

#### Scenario: Posted move rejects edits

- **GIVEN** `StockMove` in `posted` state with `locked = true`
- **WHEN** operator attempts to edit `quantity` or `unitCost`
- **THEN** the request MUST be rejected with HTTP 409 and message "Move is locked; cancellation creates offset".

#### Scenario: Cancellation creates offsetting move, not patch

- **GIVEN** `StockMove SM-001` (posted) with sourceLocationId=W-01, destinationLocationId=W-02, quantity=50
- **WHEN** operator cancels SM-001
- **THEN** a new `StockMove SM-001-CANCEL` is created with sourceLocationId=W-02, destinationLocationId=W-01,
  quantity=50, referencing the original in `notes`, with `movementReason="cancellation"`. Original SM-001 remains
  in `posted` state, linked to SM-001-CANCEL in `relations` (per OR built-in field).

### REQ-SM-004: Reserved quantity prevents over-allocation; draft move reserves from source

The system SHALL satisfy this requirement: Reserved quantity prevents over-allocation; draft move reserves from source.

On transition `draft`:

- If `sourceLocationId` is provided, reserve `quantity` from `InventoryStock` where
  `location = sourceLocationId` AND `sku` matches the item.
- Reservation via OR's optimistic-lock: `UPDATE InventoryStock SET version=version+1,
  reservedQty=reservedQty+quantity WHERE version=@currentVersion` (compare-and-swap).
- If CAS fails (concurrent move touched the same location), collision surfaces to operator:
  "Cannot reserve; another operator is updating this location. Retry or view pending moves."

On transition `posted`:

- Release reservation; decrement `reservedQty`, increment committed qty reduction (or swap
  signed quantities).

On transition `cancelled`:

- Release reservation (reverse the draft step).

#### Scenario: Concurrent moves on same source location trigger collision

- **GIVEN** Location W-01 with InventoryStock quantity=100, reservedQty=0, version=5
- **WHEN** Operator A drafts Move-A (50 units from W-01) and Operator B drafts Move-B (60 units from W-01)
  simultaneously
- **THEN** one move's CAS succeeds (reservedQty=50, version=6); the other fails with collision message.
  Failing operator retries, sees updated state, and proceeds.

### REQ-SM-005: Stock ledger: InventoryStock.quantity is reconciled by posted, non-cancelled moves

`InventoryStock.quantity` SHALL be recomputed (on read or per nightly batch) as:

```
quantity = initialStock +
  SUM(StockMove.quantity where destinationLocationId=location AND lifecycleState='posted')
  - SUM(StockMove.quantity where sourceLocationId=location AND lifecycleState='posted')
```

(excluding `cancelled` moves). Index on `(sourceLocationId, destinationLocationId, lifecycleState)`
for fast queries.

Stock ledger aggregation query: drill down from InventoryStock to individual `StockMove` records
that comprise the balance. Operator can trace any quantity discrepancy to a specific move.

#### Scenario: Stock ledger trace shows composition

- **GIVEN** InventoryStock for item SKU-001 in warehouse W-01 showing quantity=100
- **WHEN** operator requests stock ledger trace (aggregation query)
- **THEN** the UI MUST display a list of posted, non-cancelled StockMoves: [receipt 80, transfer-in 20, issue -10, etc.]
  with cumulative running total matching the 100.

### REQ-SM-006: GL materialisation: receipt increases asset, issue decreases asset + posts COGS

The system SHALL satisfy this requirement: GL materialisation: receipt increases asset, issue decreases asset + posts COGS.

On transition `posted`, if `StockMove.movementType` is:

- **receipt** (sourceLocationId=null): Post balanced GL entry: debit
  `[GL account per item category or admin config; default "1300 Inventory Assets"]`,
  credit `[GL account; default "2000 Goods-in-Transit" or from PO creditor if referenceDocumentUri
  links to PO]`. Amount = quantity × unitCost. Reference: StockMove.movementNumber.

- **transfer** (both sourceLocationId and destinationLocationId in-warehouse): No GL posting
  (lateral movement, no value change).

- **issue** (destinationLocationId=null): Post balanced GL entry: debit `[GL account per
  item category; default "5100 Cost of Goods Sold"]`, credit `[GL account per item category;
  default "1300 Inventory Assets"]`. Amount = quantity × unitCost.

- **manufacture** (sourceLocationId=components, destinationLocationId=finished goods): debit
  `[GL account; default "1310 Finished Goods"]`, credit `[GL account; default "1300 Raw Materials"]`.
  Amount = quantity × unitCost (standard cost from item master or move-supplied cost).

- **repack** (both locations in-warehouse, same/different): No GL posting (consolidation, no value change).

GL entries are materialised via `x-openregister-materialisation` rule. No PHP posting service.
GL lines reference the `StockMove` UUID via `subLedgerType: "inventory"`,
`subLedgerRef: "<StockMove UUID>"` per T1 REQ-GL-009 pattern.

#### Scenario: Issue move posts COGS GL entry

- **GIVEN** StockMove SM-999 (issue, quantity=10, unitCost=25.00, movementType=issue)
- **WHEN** transition to `posted`
- **THEN** two balanced GLLines MUST be created: (1) debit 5100 COGS for €250, (2) credit
  1300 Inventory Assets for €250. Both lines carry `subLedgerType: "inventory"`,
  `subLedgerRef: "<SM-999 UUID>"`.

### REQ-SM-007: Audit trail with mandatory reason code on posting

The system SHALL satisfy this requirement: Audit trail with mandatory reason code on posting.

`auditTrail` (OR built-in field) captures every lifecycle transition with:

- `timestamp` — exact timestamp of transition.
- `operator` — user ID of operator triggering the transition.
- `previousState` — JSON snapshot of move before transition (quantity, cost, locations, etc.).
- `movementReason` — admin-configurable reason code (required on transition to `posted`; optional on draft).
  Standard codes: normal, damaged, expired, shrinkage, inter-warehouse, adjustment, sample,
  demo, theft, loss. Additional codes configurable per administration.

#### Scenario: Reason code is mandatory on post

- **GIVEN** draft StockMove with `movementReason = null`
- **WHEN** operator attempts to transition to `posted`
- **THEN** the request MUST be rejected with message "movementReason is required to post".

### REQ-SM-008: Manifest navigation entries for Stock Movements, Stock Ledger, Reserved Stock

The system SHALL satisfy this requirement: Manifest navigation entries for Stock Movements, Stock Ledger, Reserved Stock.

Add three manifest entries to `src/manifest.json`:

- **Stock Movements** (`type: index`): list all `StockMove` records (paginated, filterable
  by type, location, date range, status).
- **Stock Ledger** (`type: index`): aggregation view showing InventoryStock per location,
  with drill-down to constituent moves.
- **Reserved Stock** (`type: index`): list all draft moves, grouped by source location, showing
  reserved qty and operator name.

Each index entry links to a detail view for individual moves.

#### Scenario: Stock Movements index lists moves with pagination

- **GIVEN** 500 posted StockMoves for an administration
- **WHEN** operator navigates to "Stock Movements" in the manifest
- **THEN** the UI MUST display a paginated index (20 per page by default) with columns:
  movementNumber, date, type, source/destination, quantity, status. Sortable by date, type.
  Filters for date range, type, status. Detail link on each row.

### REQ-SM-009: Reserved quantity visibility: operator sees draft moves blocking future allocation

Reserved stock MUST be visible to operators planning future issues or transfers:

- InventoryStock detail page MUST show: Available = quantity - reservedQty.
- Operator MUST see which draft moves are holding reservations (list of draft StockMove records
  per location) with age (time in draft) and operator name.
- Warning: if reservedQty > 50% of quantity, flag as "Alert: High reservation ratio".

#### Scenario: Operator sees available vs. reserved breakdown

- **GIVEN** Location W-01 with InventoryStock quantity=100, reservedQty=35
- **WHEN** operator views the location detail
- **THEN** the UI MUST show "Available: 65 | Reserved: 35 | Total: 100". A collapsible
  "Reserved by" section lists 2 draft moves (Operator Smith, 3 hours ago; Operator Jones, 1 hour ago).
