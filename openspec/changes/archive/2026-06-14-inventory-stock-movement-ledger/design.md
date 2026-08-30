# Design — Immutable Stock-move Ledger

## Context

Inventory is a material asset requiring immutable, auditable transaction trails
per Dutch corporate accounting standards (CTR) and IAS 2. The Tryton Stock Move
primitive — debit source location, credit destination location per atomic move —
prevents location divergence and enables drill-down from GL to physical inventory.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire stock-move surface as **declarative metadata** — schema +
  lifecycle + GL materialisation + manifest entries — per ADR-031.
- Enforce **immutability** of posted moves (locked flag, no retroactive edits).
- Enable **multi-warehouse** stock tracking via Location FK, supporting receipt,
  transfer, issue, manufacture, and repack flow types.
- Consume OR's **lifecycle** and **materialisation** abstractions — zero parallel
  stock-move table in PHP.
- Make the spec a **competent-accountant-readable contract** — Dutch SMB stock
  flow recognisable end-to-end (receipt, transfer, issue, repack, GL posting,
  audit trail).
- Declare the **GL posting pattern** for inventory asset + COGS so financial
  reporting (T3) can reconcile variance.

## Non-Goals

- No PHP `StockMoveService`; no bespoke `receiveGoods()` / `issueStock()` methods.
- No barcode/serial number tracking — future `inventory-barcode-sku` capability.
- No replenishment execution — future `inventory-reorder-automation` capability.
- No variance analysis (standard vs. actual costing) — T3 financial-reporting.
- No BOM (bill of materials) explosion — future `inventory-manufacturing` spec.

## Decisions

### D1 — Stock move is a double-entry primitive; every move atomically debits + credits

Symmetric to general-ledger double-entry: a `StockMove` always touches two
locations (source, destination). Physical meaning: quantity leaves source, enters
destination. Null destination = issue-to-scrap; null source = receipt-from-supplier.
Prevents location balance divergence.

### D2 — Stock move lifecycle is declarative with immutability lock

`draft → posted → cancelled`. On posting, a `locked: true` flag prevents retroactive
edits. Cancellation creates an offsetting `StockMove` (not a patch); immutability
preserved. Audit trail captures every state transition + reason code.

### D3 — GL materialisation: receipt = asset increase, issue = COGS posting

On posting a StockMove:
- **Receipt** (null source): debit Inventory Asset GL account, credit
  PO Payable / Goods-in-Transit (GL account configurable per item category).
- **Transfer** (both source & destination in-warehouse): no GL posting
  (lateral movement, no value change).
- **Issue** (null destination): debit COGS GL account, credit Inventory Asset.
  Unit cost from StockMove.unitCost (manual entry or PO reference).
- **Manufacture** (assembly from components): debit Finished Goods, credit Raw
  Materials (assumes BOM in future spec; T2 move just tracks the output).
- **Repack** (consolidation): no GL posting (lateral movement).

No new service; OR's materialisation extension executes the GL pattern.

### D4 — Reserved quantity workflow prevents over-allocation

Draft `StockMove` reserves qty from source InventoryStock via OR's optimistic-lock
pattern (version compare-and-swap). Posting commits the reservation. Cancellation
releases it. No overbooking; high-concurrency collisions bubble up to operator.

### D5 — Stock ledger is a queryable aggregation: trace InventoryStock balance to moves

`InventoryStock.quantity = SUM(moves where destinationLocation = X) -
SUM(moves where sourceLocation = X)` over all posted, non-cancelled moves.
Index on (sourceLocation, destinationLocation, itemId) for fast tracing.

### D6 — Audit trail with reason codes on every move

Immutable `auditTrail` field captures: operator, timestamp, movement reason
(damaged, expired, shrinkage, normal receipt, inter-warehouse transfer, etc.),
previous state JSON. Reason codes are admin-configurable but mandatory on post.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Stock move lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `StockMove` (`draft → posted → cancelled`); immutability enforced via `locked` flag |
| GL materialisation (receipt, issue, etc.) | T1 `JournalEntry` materialisation pattern (GLTransaction via OR's extension) | Same lifecycle action shape; StockMove posting triggers configurable GL entries |
| Reserved quantity workflow | OR `x-openregister-optimistic-lock` (version + CAS) | Draft move reserves qty; posting commits; cancellation releases |
| Stock ledger trace | OR `x-openregister-aggregations` | Aggregation query: SUM(moves) by location to reconcile InventoryStock.quantity |
| Audit trail | T2 `bookkeeping-audit-trail` (OR built-in `auditTrail` field) | Automatic on lifecycle transitions; reason code mandatory on post |
| Location reference | Budget spec (already exists) | StockMove.sourceLocation → Location FK; StockMove.destinationLocation → Location FK |
| Item reference | InventoryStock from `inventory-stock-tracking` | StockMove.itemId → Product FK (via InventoryStock) |
| Cost tracking | Manual entry + PO reference | StockMove.unitCost (entered or auto-filled); GL posting uses this cost |
| Manifest navigation | T1 manifest pattern | 3 entries (Stock Movements, Stock Ledger, Reserved Stock) + their pages |

**Net new code in implementation cycle**: 1 schema declaration + 1 lifecycle block
+ 1 GL materialisation block + 2 aggregations + 3 manifest entry pairs. Zero PHP
service classes (per ADR-031).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Stock move lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| GL materialisation (receipt/issue/etc.) | Declarative (`x-openregister-materialisation` rule) | Pure mapping: move type → GL account + debit/credit |
| Reserved quantity conflict | OR optimistic-lock + operator resolution | Rare; no service needed |
| Reason code capture | Audit trail capture (mandatory enum on post) | No computation; metadata only |
| Stock ledger aggregation | Declarative (`x-openregister-aggregations`) | SUM + GROUP BY |

No service class authored in this envelope (per ADR-031).

## Seed Data

None. Stock moves are operator-authored on first use (receipt, transfer, issue, etc.);
no templates. However, **movement reason codes** (damaged, expired, shrinkage, normal
receipt, inter-warehouse transfer, etc.) are configurable by administration; defaults
resolved in implementing cycle's UX review.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Location entity missing warehouse-level fields | Confirm Location has (name, code, address); if not, ADR-032 review adds them. Zero risk. |
| GL materialisation: who owns account routing (receipt asset vs. COGS)? | Per-item-category config or per-admin config? Resolved during implementing cycle's UX design. Spec declares the pattern; routing is a manifest entry + config field. |
| Reserved qty conflict in high-concurrency warehouse (same source, 100 ops/sec) | OR's optimistic-lock + operator resolution (rare, manual); concurrency testing in impl cycle. Alternative: pessimistic lock (future T3 optimisation if gates trip). |
| Cost method variance (FIFO vs. weighted average for issue COGS) | Spec moves are costed with supplied unitCost; FIFO/average variance reconciliation deferred to T3 financial-reporting. Zero breakage in T2. |
| Manufacture moves assume future BOM capability | T2 spec captures the output-movement pattern; future `inventory-manufacturing` spec fills in BOM explosion. Spec shape-neutral for now. |
| Repack (consolidation) creates orphan "waste" moves in some ERPs | T2 supports repack as neutral (no GL posting). If waste tracking needed, future spec adds waste-location pattern. For now, consignor manages splits offline. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the `StockMove` schema (additive).
2. `src/manifest.json` is patched with 3 new menu entries + their pages (additive).
3. Reason code enum is populated in administration settings (future UX cycle).

Down-direction: registers are non-destructive — reverting removes the manifest
entries; stock moves remain queryable but unreferenced.

## Open Questions

1. **Location entity warehouse-level fields** — does it already have name, code,
   address for warehouse identity? Resolved in ADR-032 / architecture review.
2. **GL account routing** — who owns the decision logic (receipt asset account,
   issue COGS account) per item category / per admin? Resolved in implementing
   cycle's UX design.
3. **Default movement reason codes** — standard set (damaged, expired, shrinkage,
   normal, inter-warehouse) or free-text? Resolved in `opsx-ff` discovery.
4. **BOM integration** — does manufacture move assume a BOM sub-ledger or just
   material inputs on the move itself? Future `inventory-manufacturing` spec?
   Resolved post-T2 roadmap.
5. **Variance reconciliation** — how does financial-reporting (T3) reconcile FIFO
   variance between T2 actual cost + T3 standard cost? Resolved in T3 spec.
