---
status: pr-created
---

# Design — Inventory Lot/Batch + Expiry with FEFO

## Context

Shillinq targets pet food distributors, pharmaceutical wholesalers, and
food & beverage producers as primary inventory personas. All three
require lot/batch-level stock identification with expiry date tracking and
First-Expiry-First-Out (FEFO) picking to comply with HACCP, EU food-law,
and shelf-life management obligations.

This change is a **single `kind: config` slice** — declarative schema
declarations only, consistent with ADR-032. The FEFO pick-list service
and recall workflow land in separate downstream specs
(`inventory-fefo-pick-list`, `inventory-lot-recall`).

This change **depends on** `inventory-stock-movement-ledger`. The
`StockMovement` register declared in that change is the ledger that
records every quantity in/out against a lot; without it, lot balances
cannot be computed.

## Goals

- Declare `InventoryLot` and `ExpiryAlert` registers as **fully
  declarative metadata** — schemas + `x-openregister-lifecycle` rules
  + manifest entries — per ADR-031. No new PHP service classes (with
  the documented ADR-031 exception path for FEFO sort enforcement if
  `x-openregister-sort` cannot be enforced at the API layer).
- Consume every OpenRegister abstraction that already exists for audit
  trail, RBAC — per ADR-022. No reimplementation in shillinq.
- Make the spec a **warehouse-operator readable contract** — a Dutch
  WMS operator should recognise the model as a faithful lot-tracking
  register with FEFO semantics, no surprises.
- Keep the config slice narrow enough that Tier 2 (FEFO pick-list) and
  Tier 3 (recall workflow) can each add their surface without reshaping
  the core lot schemas.

## Non-Goals

- No StockMovement register (owned by `inventory-stock-movement-ledger`).
- No FEFO pick-list generation service — Tier 2 spec.
- No lot recall workflow — separate `inventory-lot-recall` spec.
- No serial-number-per-piece tracking — separate spec.
- No frontend Vue components beyond the generic `CnIndexPage`/`CnDetailPage`
  driven by `src/manifest.json`.

## Decisions

### D1 — InventoryLot as the atomic unit for FEFO and expiry tracking

FEFO requires comparing expiry dates across available lots. Each
`InventoryLot` record represents one lot — a discrete, homogeneous
quantity of a product received together from one supplier on one date,
carrying a single `expiryDate`.

The `InventoryStock` entity in `adr-000-data-model.md` tracks aggregate
quantity per SKU per location; it does NOT carry expiry or lot identity.
`InventoryLot` sits *below* `InventoryStock` — it adds the per-lot
granularity needed for FEFO and recall. The aggregate-stock view is
the sum of `InventoryLot.quantity` values grouped by `productSku` and
`warehouseLocation`; this aggregation is declared via
`x-openregister-aggregations` on `InventoryStock` in the implementing
cycle.

**Alternative considered**: A flat `expiryDate` field added directly
to `InventoryStock`. Rejected — `InventoryStock` is a position-level
aggregate; a single stock record can span multiple received lots with
different expiry dates. Flattening expiry onto the stock record forces
a "worst case" (earliest) expiry and makes FEFO sort impossible.

### D2 — FEFO order declared via `x-openregister-sort`, ADR-031 exception fallback

FEFO sort (`ORDER BY expiryDate ASC NULLS LAST`) is declared on
`InventoryLot` as:

```json
"x-openregister-sort": [{"field": "expiryDate", "direction": "asc", "nulls": "last"}]
```

If OpenRegister's sort directive is enforced at the API query layer,
all `GET /objects/shillinq/InventoryLot` responses return lots in FEFO
order by default. The pick-list tier (spec 2) simply paginates and
allocates.

If the directive is advisory, a thin PHP guard on the lot-list endpoint
applies the sort before returning. The guard is single-method, ≤20 LOC,
and explicitly cited as an ADR-031 exception. Resolution happens in
`opsx-ff` discovery before the implementing cycle begins.

**Alternative considered**: Sort in the pick-list service. Rejected —
moving the sort to a consumer breaks the separation: every consumer
(pick list, expiry dashboard, batch reports) would need to apply the
same sort. Declaring it on the schema ensures all consumers get it for
free.

### D3 — Four-state lifecycle for lots: active → quarantined/expired/exhausted

Lot lifecycle mirrors real warehouse practice:

| State | Meaning | Allowed transitions |
|---|---|---|
| `active` | Available for picking | → `quarantined`, → `expired`, → `exhausted` |
| `quarantined` | Held for quality inspection; not available for picking | → `active` (released), → `expired` |
| `expired` | Past expiry date; cannot be picked; awaiting disposal | → (terminal, no further transition) |
| `exhausted` | Quantity fully consumed; lot is closed | → (terminal) |

The `expired` transition is triggered automatically by a scheduled
check when `today > expiryDate`; the `exhausted` transition is triggered
when `StockMovement` reduces `InventoryLot.quantity` to zero (declared
as an `x-openregister-lifecycle` postcondition on stock-movement posts,
or a thin guard per ADR-031).

**Alternative considered**: No lifecycle, just a `status` field with
no enforced transitions. Rejected — the ADR-031 pattern ensures the
state machine is enforceable from the schema, not from imperative
guards scattered across services.

### D4 — ExpiryAlert as a separate register, not embedded on InventoryLot

`ExpiryAlert` is a distinct entity so that:
1. Multiple alerts at different thresholds can exist per lot (e.g.,
   30-day alert AND 7-day alert).
2. Alert acknowledgement is tracked independently from lot state.
3. Alert history remains queryable after a lot is exhausted or expired.

Alert generation (daily cron comparing `expiryDate - today` against
configured thresholds) is a `kind: code` concern — filed as chain
spec 2 per ADR-032 if a Nextcloud `IJob` is needed.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Product/SKU identity | `InventoryItem` (`sku`, `name`, `category`) in ADR-000 | `InventoryLot.productSku` FKs into `InventoryItem.sku` via `x-openregister-relations` |
| Goods receipt link | `GoodsReceipt` (`receiptNumber`, `receivedDate`, `quantity`) in ADR-000 | `InventoryLot.goodsReceiptId` FKs into `GoodsReceipt.id` |
| Aggregate stock position | `InventoryStock` (`sku`, `quantity`, `location`) in ADR-000 | `InventoryStock.quantity` is an aggregation of lot quantities; Tier 2 wires this via `x-openregister-aggregations` |
| Stock movement ledger | `StockMovement` declared in `inventory-stock-movement-ledger` | `InventoryLot` one-to-many → `StockMovement` via FK; depends on that change landing first |
| Person for alert recipient | `Person` (`givenName`, `familyName`, `email`) in ADR-000 | `ExpiryAlert.recipientId` FKs into `Person.id` |
| Audit trail | OR audit-trail-immutable | Consumed automatically — every lot state transition writes an audit event |
| RBAC | OR authorization | Per-schema role definitions: `warehouse-operator` create/read/update; `warehouse-manager` all transitions; `auditor` read-only |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | `InventoryLot` declares active/quarantined/expired/exhausted state machine |
| Sort order | `x-openregister-sort` | FEFO sort on `expiryDate ASC NULLS LAST` |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (ADR-024) | 1 menu entry + 1 index page + 1 detail page |

**Net new code in implementation cycle**: 2 schema declarations +
1 schema patch + 1 manifest entry pair. Possibly 1 short PHP sort
guard (≤20 LOC, single method) if Risk 1 confirms `x-openregister-sort`
is advisory.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Lot state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine; fits the extension |
| FEFO sort order | Declarative if `x-openregister-sort` enforces at query layer; otherwise single-method PHP guard per ADR-031 exception | Resolution in `opsx-ff` discovery; spec is shape-neutral |
| `expired` auto-transition | Declarative postcondition on daily cron job (OR job-queue) OR `IJob` (kind: code, chain spec 2) | Depends on OR job-queue maturity |
| `exhausted` auto-transition | Declarative postcondition on `StockMovement` post (quantity = 0) | Declared on `StockMovement` schema via `x-openregister-lifecycle` postcondition |
| Audit trail | Consumed from OR's audit-trail-immutable | ADR-022 — no app-local audit |

## Seed Data

Five example `InventoryLot` objects with Dutch pet-food product values
for development and demo environments. To be shipped as
`lib/Settings/seeds/inventory-lots-demo.json`.

```json
[
  {
    "lotNumber": "LOT-2026-001",
    "batchCode": "PF-KAT-A001",
    "productSku": "DV-KAT-SENIOR-2KG",
    "manufactureDate": "2026-01-15",
    "expiryDate": "2027-01-15",
    "bestBeforeDate": "2026-12-15",
    "quantity": 240,
    "unitCode": "ZAK",
    "unitCost": 8.75,
    "warehouseLocation": "REK-A3-VAK2",
    "lotStatus": "active",
    "receivedDate": "2026-01-20",
    "notes": "Droogvoer kat senior, leverancier Prins Petfoods"
  },
  {
    "lotNumber": "LOT-2026-002",
    "batchCode": "PF-HOND-B042",
    "productSku": "NV-HOND-ADULT-400G",
    "manufactureDate": "2026-02-01",
    "expiryDate": "2028-02-01",
    "bestBeforeDate": "2027-12-01",
    "quantity": 576,
    "unitCode": "BLIK",
    "unitCost": 2.15,
    "warehouseLocation": "REK-B1-VAK4",
    "lotStatus": "active",
    "receivedDate": "2026-02-10",
    "notes": "Natvoer hond adult, rund en groenten"
  },
  {
    "lotNumber": "LOT-2025-099",
    "batchCode": "PF-HOND-C019",
    "productSku": "DV-HOND-PUPS-KLEIN-1KG",
    "manufactureDate": "2025-10-01",
    "expiryDate": "2026-06-15",
    "bestBeforeDate": "2026-05-01",
    "quantity": 48,
    "unitCode": "ZAK",
    "unitCost": 12.40,
    "warehouseLocation": "REK-A1-VAK1",
    "lotStatus": "quarantined",
    "receivedDate": "2025-10-20",
    "notes": "Puppyvoer klein ras — in quarantaine i.v.m. kwaliteitscontrole batch C019"
  },
  {
    "lotNumber": "LOT-2026-003",
    "batchCode": "AG-KONIJN-D007",
    "productSku": "KORN-KONIJN-STD-5KG",
    "manufactureDate": "2026-03-01",
    "expiryDate": "2027-09-01",
    "bestBeforeDate": "2027-06-01",
    "quantity": 120,
    "unitCode": "ZAK",
    "unitCost": 6.90,
    "warehouseLocation": "REK-C2-VAK3",
    "lotStatus": "active",
    "receivedDate": "2026-03-05",
    "notes": "Konijnenkorrels standaard, leverancier Versele-Laga"
  },
  {
    "lotNumber": "LOT-2026-004",
    "batchCode": "AG-VOGEL-E003",
    "productSku": "ZAADHM-VOGEL-PREM-2KG",
    "manufactureDate": "2026-03-15",
    "expiryDate": "2027-03-15",
    "bestBeforeDate": "2027-01-15",
    "quantity": 300,
    "unitCode": "ZAK",
    "unitCost": 5.25,
    "warehouseLocation": "REK-C1-VAK2",
    "lotStatus": "active",
    "receivedDate": "2026-03-20",
    "notes": "Vogelzaad mix premium, zonnebloempitten en gierst"
  }
]
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| `x-openregister-sort` advisory only | Document gap as OR issue; thin PHP guard per ADR-031 exception (see D2). Spec is shape-neutral. |
| FEFO sort assumption: lots have expiry dates | NULL-last semantics declared on schema; `requiresLotTracking: true` items trigger `missing_expiry_date` alert on receipt without an expiry date |
| `InventoryLot` schema locks downstream recall spec | Recall spec adds a `traceability` sub-schema and `recall` lifecycle transition additively; no InventoryLot field renaming required |
| Expiry alert scheduling needs `IJob` | If OR job-queue doesn't support daily cron, a Nextcloud `IJob` is a `kind: code` chain spec 2. This config spec is not blocked. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with 2 new schemas
   (`InventoryLot`, `ExpiryAlert`) and 1 additive field (`requiresLotTracking`
   on `InventoryItem`) — all additive; no existing schema changes.
2. `src/manifest.json` is patched with 1 menu entry + 1 index/detail
   page pair (additive).
3. Demo seed data is shipped as
   `lib/Settings/seeds/inventory-lots-demo.json` and loaded by the
   repair step when `APP_ENV=development`.
4. `openspec/architecture/adr-000-data-model.md` gains two new entity
   entries (`InventoryLot`, `ExpiryAlert`) and an additive-field note on
   `InventoryItem`.

Down-direction: registers are non-destructive. Disabling the manifest
navigation leaves stranded but queryable lot records. No destructive
rollback needed.

## Open Questions

1. **`x-openregister-sort` enforcement** — resolved in `opsx-ff` discovery
   before the implementing cycle begins.
2. **Alert scheduling mechanism** — OR job-queue vs Nextcloud `IJob`;
   determines whether chain spec 2 is needed.
3. **Dutch terminology** — "lot" vs "batch" vs "partij" vs "charge" in
   the UI; settled in the implementing cycle's i18n review with the Dutch
   bookkeeper persona.
