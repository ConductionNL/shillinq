# Spec: inventory-valuation-fifo-avg

**Status:** proposed
**Scope:** shillinq
**Tier:** inventory sub-ledger
**Depends on:** inventory-stock-movement-ledger, add-shillinq-general-ledger

## ADDED Requirements

### Requirement: REQ-INV-001: The system SHALL store inventory valuation as an OpenRegister-managed `InventoryValuation` register

Inventory valuation per item per warehouse MUST be declared as a
register in `lib/Settings/shillinq_register.json`, using the
`InventoryValuation` schema from `adr-000-data-model.md` (Primary spec:
`cost-accounting-allocation`). No custom PHP entity, no custom database
table, no parallel link table (per ADR-022 anti-pattern list). The
register is exposed through OpenRegister's generic CRUD HTTP surface;
shillinq adds no per-app endpoint for basic reads.

#### Scenario: Warehouse manager inspects current valuation via the OR API

- **GIVEN** shillinq is installed with the `InventoryValuation` schema
  declared and seed data loaded
- **WHEN** an authenticated operator calls
  `GET /index.php/apps/openregister/api/objects/shillinq/InventoryValuation`
- **THEN** the response MUST list the `InventoryValuation` records,
  paginated per OR's standard list contract, including `quantity`,
  `unitCost`, `totalValue`, `valuationMethod`, `warehouse`, and
  `status` fields.

#### Scenario: Reviewer confirms no parallel storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or table declarations
  naming `inventory_valuations`
- **THEN** no such classes or declarations SHALL exist.

### Requirement: REQ-INV-002: The `InventoryValuation` schema SHALL declare the ADR-000 minimum field set

The `InventoryValuation` schema MUST declare the following fields,
matching the `adr-000-data-model.md` entry exactly:

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `quantity` | number | Yes | Quantity of items currently on hand |
| `unitCost` | number | Yes | Cost per unit under the selected valuation method |
| `totalValue` | number | Yes | Total inventory value (`quantity × unitCost`) |
| `valuationMethod` | enum | Yes | One of `FIFO`, `average` |
| `date` | datetime | Yes | Date of valuation snapshot or last update |
| `warehouse` | string | No | Warehouse or storage location identifier |
| `status` | enum | Yes | One of `active`, `adjusted`, `obsolete` |

Relations declared per ADR-000:
- → `Product` (many-to-one)
- → `CostCenter` (many-to-one)

OpenRegister built-in fields (`id`, `uuid`, `version`, `createdAt`,
`updatedAt`, `owner`, `auditTrail`, `relations`, …) are not redeclared
per `adr-000-data-model.md` preamble.

#### Scenario: Schema validator accepts a minimal valuation record

- **GIVEN** the `InventoryValuation` schema is loaded
- **WHEN** an object `{quantity: 50, unitCost: 12.5, totalValue: 625, valuationMethod: "FIFO", date: "2026-05-01T00:00:00Z", status: "active"}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an unknown valuationMethod

- **GIVEN** the `InventoryValuation` schema
- **WHEN** an object with `valuationMethod: "LIFO"` is validated
- **THEN** validation MUST fail with an enum-violation error naming the
  unsupported method.

### Requirement: REQ-INV-003: The system SHALL compute FIFO valuation on each stock movement

When a `StockMovement` with `movementType: inbound` is processed for an
item whose `InventoryValuation.valuationMethod` is `FIFO`, the system
MUST create a new FIFO cost lot representing that receipt. When a
`StockMovement` with `movementType: outbound` is processed, the system
MUST deduct quantity from the oldest open inbound lot first
(chronological by `StockMovement.date`), continuing to the next lot if
the first is exhausted, until the outbound quantity is fully allocated.

The `InventoryValuation` snapshot MUST be updated after every movement:
`unitCost` = weighted average of remaining open lot costs;
`totalValue` = `quantity × unitCost`.

#### Scenario: FIFO correctly assigns cost from two inbound lots

- **GIVEN** item `GT-10-2026` in warehouse `Magazijn Noord` with two
  inbound lots: Lot A (qty 30, unitCost EUR 10,00 on 2026-04-01) and
  Lot B (qty 20, unitCost EUR 12,00 on 2026-04-15)
- **AND** a subsequent outbound movement of qty 35 on 2026-05-01
- **WHEN** the FIFO engine processes the outbound movement
- **THEN** COGS MUST be posted as: 30 × EUR 10,00 + 5 × EUR 12,00 =
  EUR 360,00 total
- **AND** the remaining `InventoryValuation` snapshot MUST show qty 15,
  unitCost EUR 12,00, totalValue EUR 180,00.

#### Scenario: FIFO processing is idempotent on re-run

- **GIVEN** a `StockMovement` outbound event that has already been
  processed by the FIFO engine
- **WHEN** the engine is triggered again for the same event (e.g. on
  error retry)
- **THEN** the `InventoryValuation` record and COGS posting MUST NOT be
  duplicated; the engine MUST detect prior processing and skip.

### Requirement: REQ-INV-004: The system SHALL compute moving-average valuation on each inbound receipt

When a `StockMovement` with `movementType: inbound` is processed for
an item whose `InventoryValuation.valuationMethod` is `average`, the
system MUST recalculate the running weighted average unit cost:

```
new_unitCost = (current_quantity × current_unitCost + receipt_quantity × receipt_unitCost)
               / (current_quantity + receipt_quantity)
```

The `InventoryValuation` snapshot MUST be updated: `unitCost` = new
weighted average (rounded to 4 decimal places); `totalValue` =
`(current_quantity + receipt_quantity) × new_unitCost` (rounded to 2
decimal places).

On each outbound movement for an `average` item, COGS is posted at
the current `unitCost` (no lot traversal required).

#### Scenario: Moving-average recalculates correctly on receipt

- **GIVEN** item `KP-A4-500` in `Centraal Depot` with
  `InventoryValuation`: qty 100, unitCost EUR 3,50, totalValue EUR 350,00
- **WHEN** an inbound `StockMovement` of qty 200 at EUR 4,00 per unit
  arrives
- **THEN** the new `unitCost` MUST be `(100 × 3.50 + 200 × 4.00) / 300`
  = EUR 3,8333 (4 dp)
- **AND** `totalValue` MUST be 300 × 3,8333 = EUR 1.150,00 (2 dp).

#### Scenario: Moving-average outbound uses current average cost

- **GIVEN** item `KP-A4-500` with `InventoryValuation.unitCost = EUR 3.8333`
- **WHEN** an outbound movement of qty 50 is processed
- **THEN** COGS MUST be posted at 50 × EUR 3,8333 = EUR 191,67.

### Requirement: REQ-INV-005: Each item SHALL have exactly one active `InventoryValuation` per warehouse

Per item identifier (linked `Product`) per `warehouse`, there MUST be
exactly one `InventoryValuation` record with `status: active`. Multiple
active records for the same item+warehouse combination MUST be rejected
by OR's uniqueness constraint on the schema (or enforced by a lifecycle
precondition if OR cannot express cross-field uniqueness declaratively).

#### Scenario: Second active valuation record for same item+warehouse is rejected

- **GIVEN** an `InventoryValuation` record with `status: active` for
  product `GT-10-2026` in warehouse `Magazijn Noord`
- **WHEN** a second `InventoryValuation` record is created for the same
  product and warehouse with `status: active`
- **THEN** the save MUST fail with a "duplicate active valuation"
  error.

### Requirement: REQ-INV-006: Valuation method change SHALL be blocked when on-hand quantity is non-zero

Changing `InventoryValuation.valuationMethod` from `FIFO` to `average`
(or vice versa) is a lifecycle transition `methodChange`. This
transition MUST be blocked by the
`InventoryValuationMethodGuard::checkZeroStock()` guard when
`InventoryValuation.quantity > 0`. The guard is referenced from
`x-openregister-lifecycle.requires` per ADR-031 §"PHP guards remain a
legitimate seam".

#### Scenario: Method change on stocked item is rejected

- **GIVEN** item `GT-10-2026` with `InventoryValuation.quantity = 50`
  and `valuationMethod: FIFO`
- **WHEN** the operator attempts to change `valuationMethod` to
  `average`
- **THEN** the lifecycle engine MUST reject the transition with a
  "non-zero stock" error and the `valuationMethod` MUST remain `FIFO`.

#### Scenario: Method change on zero-stock item succeeds

- **GIVEN** item `GT-10-2026` with `InventoryValuation.quantity = 0`
  and `valuationMethod: FIFO`
- **WHEN** the operator changes `valuationMethod` to `average`
- **THEN** the transition MUST succeed and `valuationMethod` MUST be
  `average` on the updated record.

### Requirement: REQ-INV-007: On each outbound stock movement, the system SHALL post a COGS JournalEntry to the shillinq GL

`CogsPosterService` MUST post one `JournalEntry` per outbound
`StockMovement`, with:

- **Debit**: COGS account (default `7000 Kostprijs van de omzet`,
  configurable per administration) — amount = computed COGS value.
- **Credit**: Inventory asset account (default `3000 Voorraden`,
  configurable per administration) — same amount.
- `journalCode: "COGS"`, `reference`: `StockMovement.uuid`,
  `description`: `"COGS — {productName} — {qty} × EUR {unitCost}"`.

The `JournalEntry` MUST be a balanced entry (`debitAmount = creditAmount`).
If the GL account numbers are not configured, the service MUST set
`InventoryValuation.status = adjusted` and log a WARNING; it MUST NOT
silently skip the posting without marking the record.

#### Scenario: COGS entry is posted on outbound movement

- **GIVEN** item `HP-200-B` (FIFO) in `Magazijn Zuid` with current
  FIFO lot cost EUR 89,00
- **AND** shillinq GL configured with COGS account `7000` and inventory
  account `3000`
- **WHEN** an outbound `StockMovement` of qty 5 is processed
- **THEN** a `JournalEntry` MUST be created with
  `debitAmount = EUR 445,00`, `creditAmount = EUR 445,00`,
  `journalCode: "COGS"`, referencing the `StockMovement.uuid`.

#### Scenario: Missing GL configuration sets valuation to adjusted

- **GIVEN** shillinq GL account numbers are not yet configured
- **WHEN** an outbound `StockMovement` is processed
- **THEN** `InventoryValuation.status` MUST be set to `adjusted` and a
  WARNING MUST be logged; no silent skip.

### Requirement: REQ-INV-008: Inventory valuation SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry
(`Inventory > Valuation` or equivalent top-level placement confirmed
in the implementing cycle's UX review) with a `type: index` page
binding to the `InventoryValuation` register and a `type: detail` page
for individual valuation records. Both pages MUST be rendered by
`CnIndexPage` / `CnDetailPage` from `@conduction/nextcloud-vue`
driven by manifest config — no bespoke Vue files (per ADR-024 +
existing `customComponents.js` convention).

#### Scenario: Index page lists valuation records

- **GIVEN** the manifest declares the Inventory Valuation pages
- **WHEN** an operator opens
  `/index.php/apps/shillinq/inventory-valuation`
- **THEN** the page MUST render via `CnIndexPage` showing valuation
  records with default columns `warehouse`, `quantity`, `unitCost`,
  `totalValue`, `valuationMethod`, `status`.

#### Scenario: Detail page renders a valuation record

- **GIVEN** a `InventoryValuation` record exists
- **WHEN** the operator drills into it
- **THEN** the detail page MUST render via `CnDetailPage` showing all
  fields from REQ-INV-002 and the lifecycle actions from REQ-INV-009.

### Requirement: REQ-INV-009: `InventoryValuation` SHALL have a declarative active/adjusted/obsolete lifecycle

The `InventoryValuation` schema MUST declare an
`x-openregister-lifecycle` block per ADR-031 with the following states
and transitions:

- `active` — current in-use valuation snapshot; updated on every
  movement.
- `adjusted` — manual correction applied, or COGS posting pending due
  to missing GL config; remains active for stock tracking purposes.
- `obsolete` — the item has been discontinued or moved out; historical
  only, no new movements accepted.

Transitions:

| From | To | Trigger | Guard |
|------|----|---------|-------|
| `active` | `adjusted` | operator manual correction OR system (missing GL config) | none |
| `adjusted` | `active` | operator confirms correction complete | none |
| `active` | `obsolete` | operator action | on-hand quantity = 0 (via `InventoryValuationMethodGuard::checkZeroStock()`) |
| `adjusted` | `obsolete` | operator action | same |

#### Scenario: Active valuation transitions to adjusted on manual correction

- **GIVEN** `InventoryValuation` record for `GT-10-2026` in state
  `active`
- **WHEN** an operator applies a manual quantity correction and triggers
  the `adjusted` transition
- **THEN** `status` MUST become `adjusted` and the audit trail MUST
  record the actor, timestamp, and before/after snapshot.

#### Scenario: Archiving a non-empty valuation is blocked

- **GIVEN** `InventoryValuation` for `KP-A4-500` with `quantity = 200`
  in state `active`
- **WHEN** the operator attempts the `obsolete` transition
- **THEN** the lifecycle engine MUST reject the transition with a
  "non-zero stock" guard error.
