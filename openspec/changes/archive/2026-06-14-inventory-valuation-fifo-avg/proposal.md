# Proposal: inventory-valuation-fifo-avg

`kind: code` per ADR-032 — the centre of mass is business logic for
FIFO layer processing and moving-average recalculation, with a register
declaration for the `InventoryValuation` schema and GL integration for
COGS posting. Thin declarative config (lifecycle, manifest) accompanies
the code surface; total LOC per the thin-glue exception criterion is
well above the threshold, so this is `code` rather than `mixed`.

## Summary

Introduce **inventory valuation using FIFO and moving-average cost
methods** for the Shillinq inventory sub-ledger. This change adds
per-item, per-warehouse valuation snapshots, wires the valuation engine
to the `inventory-stock-movement-ledger` inbound/outbound events, and
posts Cost of Goods Sold (COGS) journal entries to the shillinq general
ledger on each outbound stock movement.

The `InventoryValuation` entity is already declared in
`adr-000-data-model.md` (primary spec: `cost-accounting-allocation`).
This change activates it in the shillinq register and adds the valuation
engine services.

## Motivation

19 of 22 competitors in the market intelligence scan (2026-05-20)
implement an inventory costing method — FIFO and weighted-average being
the dominant pair (ERPNext, Cin7 Core, Tryton, Odoo, NetSuite). Without
a costing method, Shillinq cannot compute COGS, cannot populate the
inventory asset line on the balance sheet, and cannot serve any Dutch
MKB business that sells physical goods. This is a P0 gap.

The minimal viable pair (FIFO + moving-average) is the right first
target: FIFO is required by Dutch tax authority guidance for most goods;
moving-average is the default for consumables and raw materials. LIFO is
not supported by NL GAAP and is explicitly excluded by design (same as
ERPNext policy).

## Affected Projects

- [x] Project: shillinq — adds `InventoryValuation` schema to
  `lib/Settings/shillinq_register.json`, adds valuation engine service
  classes, adds GL COGS posting on outbound movement, extends manifest
- [ ] Project: openregister — no source changes; consumes existing OR
  abstractions (audit, RBAC, `x-openregister-lifecycle`, relations)

## Scope

### In Scope

- One new capability spec (`inventory-valuation-fifo-avg`) — see the
  `specs/` folder.
- **FIFO valuation**: per-item, per-warehouse FIFO layer stack. Each
  inbound movement creates a cost lot. Each outbound movement consumes
  from the oldest lot first; COGS is recorded at the consumed lot cost.
- **Moving-average valuation**: per-item, per-warehouse weighted running
  average. Each inbound receipt recalculates the average unit cost;
  outbound movements use the current average cost.
- **Valuation method selector**: per-item setting via
  `InventoryValuation.valuationMethod` (`FIFO` or `average`). Default
  is the administration-level default. Method change is blocked when
  on-hand quantity > 0.
- **COGS GL posting**: on each outbound `StockMovement`, post a
  `JournalEntry` to the shillinq GL (debit COGS account, credit
  Inventory asset account). Account numbers configured per
  administration with RGS 3.5 defaults.
- **Inventory valuation snapshot**: the `InventoryValuation` record
  stores the current per-item, per-warehouse snapshot (quantity,
  unit cost, total value) for balance sheet consumption and reporting.
- Seed data: 5 example `InventoryValuation` records in Dutch context.

### Out of Scope

- **LIFO** — not supported by NL GAAP; excluded by design per ERPNext
  precedent.
- **Landed cost allocation** — freight and duty apportionment across
  receipts (Cin7, Odoo, ERPNext); deferred to a follow-on change.
- **Multi-currency costing** — buy in USD, hold/report in EUR; deferred
  to the multi-currency tier.
- **Specific identification / lot costing** — deferred; out of MKB
  scope.
- **Standard costing / variance analysis** — T4 territory.

## Approach

One delta adding ADDED requirements to a new spec
**`inventory-valuation-fifo-avg`**:

- Declare `InventoryValuation` in `lib/Settings/shillinq_register.json`
  with `x-openregister-lifecycle` (`active → adjusted → obsolete`).
- Author `FifoValuationService` that listens to `StockMovement` events
  from the upstream ledger and updates `InventoryValuation` using FIFO
  layer traversal.
- Author `MovingAverageValuationService` that recalculates the running
  average on each inbound receipt.
- Author `CogsPosterService` that posts a COGS `JournalEntry` on each
  outbound movement.
- Extend `src/manifest.json` with Inventory > Valuation index + detail
  pages via `CnIndexPage` / `CnDetailPage`.
- Seed 5 example `InventoryValuation` records via the repair step.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Requirements are prefixed `REQ-INV-*` for
traceability.

## New Dependencies

- **`inventory-stock-movement-ledger`** — provides the `StockMovement`
  event stream (inbound/outbound movements with item, warehouse,
  quantity, unitCost, date). This change MUST NOT be implemented before
  the ledger change is merged.
- **`add-shillinq-general-ledger`** (T1 per `adr-001`) — provides
  `JournalEntry` and `Account` for COGS postings.

## Impact

- `lib/Settings/shillinq_register.json` — adds `InventoryValuation`
  schema with FIFO/average lifecycle declaration.
- `lib/Service/FifoValuationService.php` — FIFO layer processing on
  inbound/outbound `StockMovement` events.
- `lib/Service/MovingAverageValuationService.php` — moving-average
  recalculation on inbound `StockMovement` events.
- `lib/Service/CogsPosterService.php` — posts COGS `JournalEntry` on
  outbound movement.
- `lib/Lifecycle/InventoryValuationMethodGuard.php` — thin guard (≤15
  LOC) for the zero-stock method-change precondition.
- `lib/Settings/seeds/inventory-valuation-examples.json` — 5 example
  `InventoryValuation` seed records.
- `src/manifest.json` — adds Inventory > Valuation navigation, index
  page, and detail page.
- Repair step — seeds example records on first install.

## Cross-Project Dependencies

- **OpenRegister** — `x-openregister-lifecycle` (ADR-031),
  audit-trail-immutable (ADR-022), relations engine. No new OR features
  required.
- **Shillinq GL** — `JournalEntry` + `Account` registers must be
  declared (T1 foundation).

## Risks

### Risk 1: StockMovement event shape mismatch

**Severity**: Medium
**Mitigation**: The spec explicitly declares the `StockMovement` fields
this change consumes (`movementType`, `quantity`, `unitCost`,
`warehouse`, `itemId`, `date`). If the upstream ledger produces a
different field shape, this change blocks until reconciled. Coordinate
spec field names before the implementation cycle.

### Risk 2: Concurrent valuation updates

**Severity**: Low
**Mitigation**: Use OR's optimistic-lock `version` field on
`InventoryValuation`. Concurrent outbound movements for the same item
will cause one to retry; acceptable at MKB throughput.

### Risk 3: Method change on partial stock

**Severity**: Medium
**Mitigation**: Method change blocked at `InventoryValuation.quantity >
0` via a lifecycle precondition (`InventoryValuationMethodGuard`).
Operator must first process all stock out before switching method.

## Rollback Strategy

Spec-only change at this stage. After implementation:

- Revert `lib/Settings/shillinq_register.json` patch (removes schema).
- Revert service classes and manifest entries.
- `JournalEntry` COGS records already posted to the GL CANNOT be
  auto-reversed; manual GL correction entries are required. Low risk at
  spec stage (no entries until implementation runs).

## Open Questions

1. **COGS account number default** — suggest `7000 Kostprijs van de
   omzet` (RGS 3.5 MKB standard). Confirm with bookkeeper persona.
2. **Inventory asset account default** — suggest `3000 Voorraden` (RGS
   3.5 MKB standard). Confirm with bookkeeper persona.
3. **Moving-average rounding precision** — 4 decimal places for
   intermediate unit cost, 2 decimal places for `totalValue`. Confirm.
