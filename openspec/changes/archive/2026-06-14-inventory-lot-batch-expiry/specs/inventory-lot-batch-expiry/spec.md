# Spec: inventory-lot-batch-expiry

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T2 (inventory operations)  
**Depends on:** inventory-stock-movement-ledger

## ADDED Requirements

### Requirement: REQ-LOT-001: The system SHALL store inventory lots as an OpenRegister-managed `InventoryLot` register

The lot/batch tracking surface MUST be declared as a register in
`lib/Settings/shillinq_register.json` per ADR-024, with the `InventoryLot`
schema as the canonical entity. No custom PHP model, no custom database
table, no parallel link table (per ADR-022 anti-pattern list). The
register is exposed through OpenRegister's generic CRUD HTTP surface;
shillinq adds no per-app lot-CRUD endpoint.

#### Scenario: Warehouse operator retrieves lot list via the OpenRegister API

- **GIVEN** shillinq is installed and the `InventoryLot` register is
  seeded with demo data
- **WHEN** an authenticated warehouse operator calls
  `GET /index.php/apps/openregister/api/objects/shillinq/InventoryLot`
- **THEN** the response MUST list seeded `InventoryLot` records,
  paginated per OR's standard list contract, with no shillinq-side
  controller in the call path.

#### Scenario: Reviewer confirms no parallel lot storage

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or `appinfo/info.xml`
  table declarations naming `inventory_lots` or `lots`
- **THEN** no such classes or declarations SHALL exist.

### Requirement: REQ-LOT-002: The `InventoryLot` schema SHALL declare a fixed minimum field set

The `InventoryLot` schema MUST declare the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `lotNumber` | string | Yes | Unique lot identifier (e.g. `LOT-2026-001`) |
| `batchCode` | string | No | Supplier-assigned batch reference |
| `productSku` | string | Yes | FK to `InventoryItem.sku` — identifies the product |
| `manufactureDate` | date | No | Date of manufacture |
| `expiryDate` | date | No | Legal expiry date; drives FEFO sort |
| `bestBeforeDate` | date | No | Best-before quality date; may precede `expiryDate` |
| `quantity` | number | Yes | Current available quantity in this lot |
| `unitCode` | string | No | UN/CEFACT unit of measure (e.g. `ZAK`, `BLIK`, `KG`) |
| `unitCost` | number | No | Cost per unit at time of receipt in EUR |
| `warehouseLocation` | string | No | Physical bin/rack location code |
| `lotStatus` | enum | Yes | One of `active`, `quarantined`, `expired`, `exhausted` |
| `receivedDate` | date | No | Date this lot was received into the warehouse |
| `goodsReceiptId` | string | No | FK to `GoodsReceipt.id` — receipt event that created this lot |
| `notes` | string | No | Operator-authored free text |

OpenRegister built-in fields (`id`, `uuid`, `version`, `createdAt`,
`updatedAt`, `owner`, `auditTrail`, `relations`, …) are not redeclared
per `adr-000-data-model.md`'s top-of-file note.

#### Scenario: Schema validator accepts a minimal lot

- **GIVEN** the `InventoryLot` schema is loaded
- **WHEN** an object `{lotNumber: "LOT-2026-001", productSku: "DV-KAT-SENIOR-2KG", quantity: 240, lotStatus: "active"}` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an unknown lotStatus

- **GIVEN** the schema
- **WHEN** an object with `lotStatus: "damaged"` is validated
- **THEN** validation MUST fail with an enum-violation error.

#### Scenario: Schema validator rejects a negative quantity

- **GIVEN** the schema
- **WHEN** an object with `quantity: -5` is validated
- **THEN** validation MUST fail with a minimum-value error.

### Requirement: REQ-LOT-003: The `InventoryLot` schema SHALL declare cross-schema relations via `x-openregister-relations`

`InventoryLot` MUST declare the following FK relations using OR's
`x-openregister-relations` extension:

- `productSku` → `InventoryItem.sku` (many-to-one, required)
- `goodsReceiptId` → `GoodsReceipt.id` (many-to-one, optional)
- Reverse relation: `InventoryLot` ← `StockMovement` (one-to-many;
  declared on `StockMovement.lotId` in the `inventory-stock-movement-ledger`
  change)

#### Scenario: Lot resolves its parent InventoryItem

- **GIVEN** an `InventoryLot` with `productSku: "DV-KAT-SENIOR-2KG"`
- **AND** an `InventoryItem` with `sku: "DV-KAT-SENIOR-2KG"` exists
- **WHEN** the lot is retrieved via the OR API with `?expand=productSku`
- **THEN** the response MUST embed the resolved `InventoryItem` object.

#### Scenario: Lot with unknown productSku fails relation guard

- **GIVEN** an `InventoryLot` referencing `productSku: "NONEXISTENT"`
- **WHEN** the object is saved
- **THEN** OR's relation validator SHOULD reject the save with a
  resolvable error message naming the missing `InventoryItem`.

### Requirement: REQ-LOT-004: The `InventoryLot` schema SHALL carry a Schema.org type annotation

For interoperability with shared catalogues and the MCP discovery
endpoint, the schema MUST carry:

```
x-schema-org-type: schema:Product
```

`InventoryLot` is a product-lot — a specific physical quantity of a
`schema:Product` received on a specific date. `schema:Product` is the
canonical mapping per `adr-000-data-model.md`'s existing `InventoryItem`
entry.

#### Scenario: Schema annotation surfaces in MCP discovery output

- **GIVEN** the `InventoryLot` schema is loaded
- **WHEN** OR's MCP discovery endpoint is queried
- **THEN** the schema's Schema.org type MUST be exposed as `schema:Product`.

### Requirement: REQ-LOT-005: Lots SHALL be returned in FEFO order (First-Expiry-First-Out) by default

The `InventoryLot` schema MUST declare:

```json
"x-openregister-sort": [{"field": "expiryDate", "direction": "asc", "nulls": "last"}]
```

All `GET /objects/shillinq/InventoryLot` responses MUST return lots
sorted by `expiryDate` ascending. Lots without an `expiryDate` MUST
sort after all lots with an expiry date (NULL-last semantics).

If `x-openregister-sort` is not enforced at the API layer, a single-
method PHP guard SHALL be registered per ADR-031 §"PHP guards remain a
legitimate seam" and documented in `design.md` under
"Declarative-vs-imperative decision".

#### Scenario: Three lots with different expiry dates are returned in FEFO order

- **GIVEN** three lots exist for SKU `DV-KAT-SENIOR-2KG` with
  `expiryDate` values `2026-06-15`, `2027-01-15`, and `2027-09-01`
  respectively
- **WHEN** a warehouse operator calls
  `GET /api/objects/shillinq/InventoryLot?productSku=DV-KAT-SENIOR-2KG`
- **THEN** the lots MUST be returned in ascending `expiryDate` order:
  `2026-06-15` first, then `2027-01-15`, then `2027-09-01`.

#### Scenario: Lots without expiry date sort last

- **GIVEN** two lots exist: one with `expiryDate: "2027-01-15"` and
  one with `expiryDate: null`
- **WHEN** the lot list is retrieved
- **THEN** the null-expiry lot MUST appear after the dated lot in the
  response.

### Requirement: REQ-LOT-006: `InventoryLot` SHALL have a declarative four-state lifecycle

The `InventoryLot` schema MUST declare an `x-openregister-lifecycle` block
with the following states and transitions (per ADR-031):

- `active` — available for picking; default on create
- `quarantined` — held for quality inspection; not available for picking
- `expired` — past legal expiry date; not pickable; awaiting disposal
- `exhausted` — quantity fully consumed; lot is closed

Transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `active` | `quarantined` | operator or automated quality flag | none |
| `quarantined` | `active` | operator quality release | none |
| `quarantined` | `expired` | operator or scheduled check | `today > expiryDate` |
| `active` | `expired` | scheduled daily check | `today > expiryDate` |
| `active` | `exhausted` | automated — stock movement reduces quantity to 0 | `quantity == 0` |

`expired` and `exhausted` are terminal states; no outgoing transitions.

#### Scenario: Quarantining a lot prevents it from appearing in pick lists

- **GIVEN** lot `LOT-2025-099` is in state `active`
- **WHEN** the operator transitions it to `quarantined`
- **THEN** the lot's `lotStatus` MUST be `quarantined`; **AND** the lot
  MUST NOT appear in FEFO pick-list queries that filter for
  `lotStatus: active`.

#### Scenario: Operator releases a quarantined lot back to active

- **GIVEN** lot `LOT-2025-099` is in state `quarantined`
- **WHEN** the operator transitions it to `active`
- **THEN** the lot's `lotStatus` MUST be `active`; **AND** the lot MUST
  appear in subsequent FEFO pick-list queries.

#### Scenario: Transitioning to expired from an active lot with future expiry fails

- **GIVEN** lot `LOT-2026-001` has `expiryDate: "2027-01-15"` and today
  is before that date
- **WHEN** the operator tries to manually transition it to `expired`
- **THEN** the transition MUST be rejected with an "expiry date not yet
  reached" error; the lot MUST remain `active`.

#### Scenario: Lot is auto-exhausted when stock movement reduces quantity to zero

- **GIVEN** lot `LOT-2026-001` has `quantity: 5`
- **WHEN** a `StockMovement` of type `pick` reduces the lot quantity to 0
- **THEN** the lot's `lotStatus` MUST automatically transition to
  `exhausted` and no further picks against this lot MUST be allowed.

### Requirement: REQ-LOT-007: The `ExpiryAlert` register SHALL track approaching-expiry and missing-expiry notifications

The `ExpiryAlert` schema MUST declare the following fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `lotId` | string | Yes | FK to `InventoryLot.id` — the lot triggering the alert |
| `alertType` | enum | Yes | One of `approaching_expiry`, `expired`, `missing_expiry_date` |
| `daysBeforeExpiry` | number | No | Days before expiry at which alert was generated |
| `alertDate` | date | Yes | Date the alert was generated |
| `status` | enum | Yes | One of `pending`, `acknowledged`, `resolved` |
| `resolvedDate` | date | No | Date the alert was acknowledged or resolved |
| `recipientId` | string | No | FK to `Person.id` — the operator notified |
| `notes` | string | No | Operator acknowledgement notes |

#### Scenario: An approaching-expiry alert is generated for a lot nearing its expiry date

- **GIVEN** lot `LOT-2025-099` has `expiryDate: "2026-06-15"` and today
  is 2026-05-16 (30 days before expiry)
- **AND** the expiry alert threshold is configured to 30 days
- **WHEN** the daily expiry check runs
- **THEN** an `ExpiryAlert` record MUST be created with
  `alertType: approaching_expiry`, `daysBeforeExpiry: 30`, `status: pending`,
  and `lotId` pointing to `LOT-2025-099`.

#### Scenario: An alert is generated when a lot is received without an expiry date on a tracked item

- **GIVEN** `InventoryItem` `DV-HOND-PUPS-KLEIN-1KG` has
  `requiresLotTracking: true`
- **WHEN** a new `InventoryLot` is created for that SKU without
  an `expiryDate`
- **THEN** an `ExpiryAlert` of type `missing_expiry_date` MUST be
  created with `status: pending` linked to that lot.

### Requirement: REQ-LOT-008: `InventoryItem` SHALL have an additive `requiresLotTracking` boolean field

The existing `InventoryItem` schema in `lib/Settings/shillinq_register.json`
MUST be patched with one additive optional field:

| Field | Type | Required | Default | Purpose |
|---|---|---|---|---|
| `requiresLotTracking` | boolean | No | `false` | When `true`, every receipt of this SKU MUST be assigned to a lot |

The patch is additive — existing `InventoryItem` objects remain valid
without this field (treated as `false`).

#### Scenario: Items with requiresLotTracking true cannot be received without a lot

- **GIVEN** `InventoryItem` `DV-KAT-SENIOR-2KG` has `requiresLotTracking: true`
- **WHEN** a `GoodsReceipt` is created for that SKU without an associated
  `InventoryLot`
- **THEN** the save MUST be rejected with a "lot number required for
  tracked item" error.

#### Scenario: Items with requiresLotTracking false can be received without a lot

- **GIVEN** `InventoryItem` `VERPAKKINGSDOOS-MEDIUM` has `requiresLotTracking: false`
  (or the field is absent)
- **WHEN** a `GoodsReceipt` is created for that SKU without an `InventoryLot`
- **THEN** the save MUST succeed normally.

### Requirement: REQ-LOT-009: Lot/batch tracking SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Inventory >
Lots & Batches`) with a `type: index` page binding to the `InventoryLot`
register and a `type: detail` page for individual lots. Both pages MUST
be rendered by the generic `@conduction/nextcloud-vue` `CnIndexPage` /
`CnDetailPage` components driven by manifest config — no bespoke Vue
files (per ADR-024 + the `customComponents.js` "empty on purpose"
convention).

#### Scenario: The index page lists lots in FEFO order

- **GIVEN** the manifest declares the Lots & Batches pages
- **WHEN** a warehouse operator opens
  `/index.php/apps/shillinq/inventory/lots`
- **THEN** the page MUST render via `CnIndexPage` showing lots sorted
  by `expiryDate` ascending with default columns: `lotNumber`, `productSku`,
  `expiryDate`, `quantity`, `lotStatus`.

#### Scenario: The detail page renders a lot with its movement history

- **GIVEN** a lot exists and has linked `StockMovement` records
- **WHEN** the operator drills into the lot
- **THEN** the detail page MUST render via `CnDetailPage` showing
  all fields from REQ-LOT-002 and the lifecycle-state actions permitted
  by REQ-LOT-006.
