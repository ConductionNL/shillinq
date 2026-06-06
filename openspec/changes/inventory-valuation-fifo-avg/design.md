# Design — Inventory Valuation (FIFO + moving-average)

**Status:** pr-created

## Context

Shillinq's inventory sub-ledger tracks stock movements via the upstream
`inventory-stock-movement-ledger` change. That change provides an
immutable ledger of inbound and outbound `StockMovement` records, each
carrying item identifier, warehouse, quantity, and per-unit cost at the
time of movement.

This change adds the **valuation engine** on top of the movement ledger:

- **FIFO (First-In, First-Out)**: outbound movements consume cost layers
  in the order received. The oldest inbound lot is debited first. COGS
  is recorded at the consumed lot cost.
- **Moving-average (gewogen voortschrijdend gemiddelde)**: each inbound
  receipt recalculates the running weighted average unit cost:
  `new_avg = (current_qty × current_avg + receipt_qty × receipt_cost) / (current_qty + receipt_qty)`.
  All outbound movements use the current average.

Both methods write COGS `JournalEntry` objects to the shillinq GL on
each outbound movement, closing the loop between inventory and the
P&L statement.

The `InventoryValuation` entity is declared in `adr-000-data-model.md`
(Schema.org: `schema:Product`, Primary spec: `cost-accounting-allocation`).
This change formalises it in the shillinq register.

## Goals

- Compute accurate per-item, per-warehouse cost snapshots using FIFO or
  moving-average.
- Drive COGS posting to the shillinq GL on every outbound movement.
- Implement within a single Hydra `code` cycle (≤400 turns Sonnet).
- Reuse existing ADR-000 entities; no new schema entities introduced.

## Non-Goals

- LIFO, specific identification, standard costing — excluded per NL
  GAAP + proposal scope.
- Landed cost allocation — follow-on change.
- Multi-currency cost conversion — multi-currency tier.

## Decisions

### D1 — `InventoryValuation` from ADR-000 as the valuation snapshot

The ADR-000 `InventoryValuation` entity already carries the right
fields: `quantity`, `unitCost`, `totalValue`, `valuationMethod`, `date`,
`warehouse`, `status`. Relations: → Product, → CostCenter. This change
formalises it in the shillinq register without new entities.

**Alternative considered**: Create a separate FIFO lot entity with
per-lot cost/quantity. Rejected — FIFO lot tracking lives in
`StockMovement` records in the upstream ledger; the valuation snapshot
is a derived summary, not a lot registry.

### D2 — FIFO layer traversal queries the upstream movement ledger

On each outbound `StockMovement`, the FIFO engine queries the upstream
ledger for all open inbound lots (inbound movements with remaining
quantity, ordered oldest-first by `date`). It applies the outbound
quantity against those lots sequentially, recording the weighted average
COGS cost for the batch. `InventoryValuation` is updated to reflect
remaining lots.

**Alternative considered**: Persist FIFO layers as a new `FifoLot`
entity in shillinq. Rejected — the movement ledger already tracks this;
duplicating it violates ADR-022 (consume OR abstractions, do not
re-implement storage).

### D3 — Moving-average recalculation on every inbound receipt

On each inbound `StockMovement` where `InventoryValuation.valuationMethod
= 'average'`:

```
new_unit_cost = (current_qty × current_unit_cost + receipt_qty × receipt_unit_cost)
                / (current_qty + receipt_qty)
```

Update `InventoryValuation.unitCost`, recalculate `totalValue`. All
subsequent outbound movements use the updated average until the next
receipt.

Rounding: intermediate `unitCost` stored to 4 decimal places;
`totalValue` rounded to 2 decimal places (standard Dutch bookkeeping
precision).

**Alternative considered**: Batch-recalculate at period-end. Rejected —
real-time update required for accurate COGS posting on each outbound
movement.

### D4 — COGS posting via `CogsPosterService` (documented ADR-031 exception)

COGS posting requires a cross-schema side effect: write a `JournalEntry`
in the GL register when an inventory outbound movement event fires,
carrying a computed monetary amount (cost × quantity). OpenRegister's
`x-openregister-notifications` handles simple event-driven writes, but
parameterised cross-register writes with runtime-computed amounts are
outside its current expression range.

**ADR-031 exception**: A thin `CogsPosterService` PHP class is used as
the COGS integration point. It is ≤50 LOC, stateless, single-method
(`postCogs(StockMovement $movement, InventoryValuation $valuation):
void`), called from the valuation services. This is an external-system
adapter pattern (GL is a peer sub-system) per ADR-003.

When OR's notification engine gains parameterised cross-register write
support, this service class is the migration target. Issue to be opened
on the `openregister` repo referencing ADR-031.

### D5 — Method change permitted only at zero on-hand stock

Switching `valuationMethod` (FIFO ↔ average) is allowed only when
`InventoryValuation.quantity = 0`. This prevents cost distortion from
re-valuing mid-inventory. Enforced via `x-openregister-lifecycle`
precondition referencing `InventoryValuationMethodGuard::checkZeroStock()`
(thin PHP guard, ≤15 LOC, per ADR-031 §"PHP guards remain a legitimate
seam").

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Valuation snapshot | `InventoryValuation` in ADR-000 | Formalise in shillinq register; no new entity |
| FIFO cost layers | `StockMovement` records from upstream `inventory-stock-movement-ledger` | Query from ledger in date order; no new lot table |
| GL COGS posting | `JournalEntry` entity + `Account` entity (T1 GL change) | Write `JournalEntry` via OR CRUD; `CogsPosterService` wrapper |
| Current stock levels | `InventoryStock` entity in ADR-000 | Read `quantity` from existing `InventoryStock` records |
| Product master | `Product` entity in ADR-000 | Foreign key from `InventoryValuation` → `Product` |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| RBAC | OR authorization | `bookkeeper`: create/read/write on `InventoryValuation`; `auditor`: read-only |
| Lifecycle state machine | `x-openregister-lifecycle` per ADR-031 | `active → adjusted → obsolete` lifecycle |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` | Adds Inventory > Valuation index + detail pages |

**Net new code**: `FifoValuationService` + `MovingAverageValuationService`
+ `CogsPosterService` + `InventoryValuationMethodGuard` + 1 schema
declaration + 1 manifest entry pair + 1 seed file. No new entities.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| `InventoryValuation` state machine (`active → adjusted → obsolete`) | Declarative (`x-openregister-lifecycle`) | Pure state machine, fits the extension |
| FIFO cost layer traversal | Imperative (`FifoValuationService`) | Ordered multi-record traversal across schemas; no declarative equivalent in OR |
| Moving-average recalculation | Imperative (`MovingAverageValuationService`) | Mathematical recalculation with side-effect write; no declarative equivalent |
| COGS GL posting | Imperative (`CogsPosterService`) | Cross-schema write with computed monetary amount; ADR-031 exception documented under D4 |
| Zero-stock method-change guard | Thin PHP guard (`InventoryValuationMethodGuard`) called from `x-openregister-lifecycle.requires` | ADR-031 §"PHP guards remain a legitimate seam" |

## Seed Data

5 example `InventoryValuation` records in
`lib/Settings/seeds/inventory-valuation-examples.json`, loaded via
`ConfigurationService::importFromApp()` on repair (idempotent):

| # | SKU | Artikelnaam (NL) | Magazijn | Aantal | Eenheidsprijs (EUR) | Totaalwaarde (EUR) | Methode |
|---|-----|-----------------|----------|--------|---------------------|--------------------|---------|
| 1 | GT-10-2026 | Gereedschapset 10-delig | Magazijn Noord | 50 | 12,50 | 625,00 | FIFO |
| 2 | KP-A4-500 | Kantoorpapier A4 500 vel | Centraal Depot | 200 | 3,75 | 750,00 | average |
| 3 | HP-200-B | Hydraulische pomp HP-200 | Magazijn Zuid | 15 | 89,00 | 1.335,00 | FIFO |
| 4 | SM-5L-PRO | Schoonmaakmiddel Pro 5L | Centraal Depot | 100 | 8,40 | 840,00 | average |
| 5 | VHG-S-M | Veiligheidshandschoenen S/M | Magazijn Noord | 300 | 1,20 | 360,00 | FIFO |

All records: `status: active`, `date: 2026-05-01`, related to
`CostCenter` `CC-MAGAZIJN-001`.

3 example `InventoryStock` records (cross-reference, seeded by the
upstream `inventory-stock-movement-ledger` change; included here for
seed verification):

| # | SKU | Aantal | Locatie | Eenheidsprijs | Status |
|---|-----|--------|---------|---------------|--------|
| 1 | GT-10-2026 | 50 | Magazijn Noord | 12,50 | active |
| 2 | KP-A4-500 | 200 | Centraal Depot | 3,75 | active |
| 3 | HP-200-B | 15 | Magazijn Zuid | 89,00 | active |

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| FIFO layer query performance on large movement history | Upstream ledger adds index on `StockMovement(itemId, warehouse, date)`; FIFO engine queries only open lots |
| Concurrent FIFO updates (two simultaneous outbound movements for same item) | OR optimistic-lock `version` field on `InventoryValuation`; one request retries on conflict |
| Moving-average rounding drift | Round unit cost to 4 decimal places; `totalValue` to 2; acceptable precision for MKB scope |
| GL account numbers not configured | Service validates account numbers at boot; logs a `WARNING` and skips COGS posting with a `pendingCogs` flag on the `InventoryValuation` record until configuration is complete |

## Migration Plan

Spec-only — no runtime migration in this change. Implementation via
`opsx-apply`:

1. Additive patch to `lib/Settings/shillinq_register.json` — adds
   `InventoryValuation` schema.
2. Additive patch to `src/manifest.json` — adds Inventory > Valuation
   navigation.
3. Repair step seeds 5 example records (idempotent on re-run).
4. New service classes — no migration to existing data.

Down-direction: disable service hooks, revert register and manifest
patches. COGS `JournalEntry` records already posted remain in the GL;
manual reversal entries required if full rollback after first use.

## Open Questions

1. **COGS account number default** — suggest `7000 Kostprijs van de
   omzet` (RGS 3.5 MKB). Confirm with bookkeeper persona before
   `opsx-apply`.
2. **Inventory asset account default** — suggest `3000 Voorraden` (RGS
   3.5 MKB). Confirm.
3. **Moving-average rounding** — 4 decimal places for unit cost, 2 for
   totals. Confirm.
