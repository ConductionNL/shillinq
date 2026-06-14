# Proposal: inventory-stock-movement-ledger

`kind: spec` per ADR-032 — immutable ledger of stock movements with double-entry
pattern. New register `StockMove` tracking source/destination location, quantity,
cost, movement type (receipt, transfer, issue, manufacture, repack), and audit trail.
Follows Odoo's double-entry + Tryton's Stock Move pattern.

## Summary

Introduce the **immutable stock-move ledger** capability for Shillinq inventory
management as a foundational T2 capability. This capability enables auditable,
reversible stock tracking across multi-warehouse environments. The change declares
the `StockMove` register; the stock-move lifecycle (`draft → posted → cancelled`);
the double-entry pattern (debit source location, credit destination location per
movement); automatic GL integration via materialisation (COGS posting on material
issue, stock asset adjustment on receipt); reserved quantity reservation workflow;
and audit trail with reason codes for every move. Supports material receipt, inter-warehouse
transfer, customer issue, manufacture, and repack flow types. Immutability enforced
via `locked` flag on posted moves.

This change conforms to the shared `nextcloud-app` spec for app structure.

**Depends on:** `inventory-stock-tracking` (stock quantities per location),
`add-shillinq-general-ledger` (GL materialisation for inventory valuations).

## Motivation

Inventory is a material asset requiring immutable, auditable transaction trails
per Dutch corporate accounting standards (CTR) and IAS 2. Competitors (20/22 in
intelligence-db) provide multi-type stock-movement patterns (receipt, transfer,
issue, manufacture, repack) with automated GL posting. The Tryton Stock Move
primitive — debit source, credit destination per atomic move — prevents location
stock-level divergence and enables drill-down from GL to physical inventory.

Per ADR-022 (consumes shared abstractions) and ADR-031 (declarative-first), stock
move lifecycle, GL materialisation, and reservation workflow are declarative; no
bespoke PHP `StockMoveService`.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`inventory-stock-movement-ledger`); declares 1 new register (`StockMove`) with
  lifecycle, GL materialisation, and manifest navigation entries (Stock Movements,
  Stock Ledger, Reserved Stock).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-materialisation` extensions.

## Scope

### In Scope

- One new capability spec (`inventory-stock-movement-ledger`) — see the `specs/` folder.
- The `StockMove` register with source/destination location, item reference, quantity,
  unit cost, movement type (receipt, transfer, issue, manufacture, repack), reference document
  URI (PO/sales order/production plan), reason code, created timestamp, and posted timestamp.
- The stock-move lifecycle (`draft → posted → cancelled`) with immutability lock on posted moves.
- Double-entry pattern: every move atomically debits source location and credits destination
  location (Tryton pattern); GL materialisation on posting (inventory asset adjustment +
  COGS posting for material issues).
- Reserved quantity workflow: draft `StockMove` reserves qty from source location; posting
  consumes the reservation; cancellation releases the reservation.
- Audit trail: immutable `auditTrail` on every move with reason code, operator, timestamp,
  previous state (per OR built-in fields).
- Multi-warehouse support via Location entity (from Budget spec).
- Movement type taxonomy: receipt (increase asset, debit inventory GL), transfer (neutral,
  inter-location), issue (decrease asset, debit COGS), manufacture (increase asset,
  standard cost calculation), repack (neutral, consolidation).
- Stock ledger aggregation: queryable drill-down from any InventoryStock → all moves
  that comprise its balance.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers,
  tests, and CI changes are deliberately not in this proposal; the task list references them
  but the implementation lands via a separate `opsx-apply` cycle.
- **Variance analysis / standard costing** — out-of-spec advanced costing; receipt cost can
  be manual entry or PO reference; actual vs. standard variance calcs deferred to financial
  reporting tier (T3 variance analysis, future).
- **Barcode/serial number tracking** — future inventory-barcode-sku capability.
- **Replenishment execution** — future inventory-reorder-automation capability.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`inventory-stock-movement-ledger`** — declares the `StockMove` register with
double-entry semantics, the lifecycle (draft → posted → cancelled), GL materialisation
(inventory asset + COGS), reserved-quantity workflow, and audit trail. Supports receipt,
transfer, issue, manufacture, repack movement types. Aggregation: stock ledger trace
from InventoryStock balance to individual moves.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is
prefixed `REQ-SM-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions (`x-openregister-lifecycle`,
`x-openregister-materialisation`), InventoryStock from `inventory-stock-tracking`,
and GL materialisation pattern from `add-shillinq-general-ledger`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 new schema (`StockMove`) with
  lifecycle and GL materialisation rules.
- `src/manifest.json` — adds 3 navigation entries (Stock Movements, Stock Ledger, Reserved Stock).
- No new PHP services (per ADR-031 — stock move lifecycle and GL materialisation are
  declarative or use existing OR extensions).
- No new bespoke Vue components beyond index/detail navigation pages.

## Cross-Project Dependencies

- **InventoryStock** — from `inventory-stock-tracking` spec; StockMove updates
  InventoryStock.quantity on posting via OR's materialisation extension.
- **GL materialisation** — from `add-shillinq-general-ledger` (T1); StockMove posting
  materialises balanced GL entries (inventory asset debit/credit, COGS debit/inventory
  credit for issues).
- **OpenRegister** — depends on `x-openregister-lifecycle` and `x-openregister-materialisation`
  extensions for the lifecycle and GL posting pattern.

## Risks

### Risk 1: Location entity scope clash

**Severity**: Low
**Mitigation**: The Location entity already exists in ADR-000 (from budget-planning-control).
Confirm it carries the minimum fields (name, code, address) for warehouse/site tracking; if
not, ADR-032 review adds fields. StockMove simply references Location by FK. No new entity.

### Risk 2: GL materialisation performance at scale

**Severity**: Low-Medium
**Mitigation**: Each StockMove posting triggers a balanced GL entry (up to 2 lines per move).
At 100k moves/year (22 per business day), GL postings scale linearly. OR's materialisation
extension handles batching; per-spec optimisation in implementing cycle if CI gates trip.

### Risk 3: Reserved quantity collision in high-concurrency

**Severity**: Low
**Mitigation**: Draft StockMove reserves qty from InventoryStock via OR's optimistic-lock
pattern (version + compare-and-swap). Collisions bubble up to the operator for manual
resolution (rare). Resolved in implementing cycle's concurrency testing.

### Risk 4: Cost method (FIFO vs. average) for material issue COGS

**Severity**: Low
**Mitigation**: StockMove.unitCost is manual entry or auto-filled from receipt cost. Issue
moves use supplied unitCost (full flexibility). FIFO/average variance calculated downstream
in financial-reporting tier (T3). T2 moves are costed; T3 reconciles variance.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no
runtime impact. After implementation (separate cycle), rollback follows the standard
pattern: revert the implementing PR; registers are non-destructive — stock moves
remain queryable but unreferenced.

## Open Questions

1. **Location entity fields** — confirm it has warehouse/site identity (name, code, address).
   Resolved in ADR-032 / architecture review.
2. **GL account coding for receipt vs. issue COGS** — who owns the routing logic? Per
   admin config? Per inventory item category? Resolved during implementing cycle's UX design.
3. **Default movement reason codes** — standard set (damaged, expired, shrinkage, etc.)?
   Or free-text? Resolved in `opsx-ff` discovery + UX review.
4. **Manufacture BOM integration** — does T2 assume a BOM sub-ledger or just material
   list in the repack move? Future `inventory-manufacturing` spec dependency?
   Resolved post-T2 roadmap.
