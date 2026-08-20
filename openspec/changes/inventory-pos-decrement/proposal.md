# Change: inventory-pos-decrement

## Why

A POS sale rung up in **pipelinq** (retail/point-of-sale) must immediately
decrement on-hand stock and post COGS in **shillinq** (bookkeeping/inventory),
so the two systems agree on inventory in real time. 20/22 catalogued
competitors treat POS↔inventory decrement as table stakes (P0). Today the two
apps do not talk about stock at all.

## ⚠️ Reality check — this is a TWO-REPO feature, and the producer side does not exist yet

The original triage brief assumed shillinq could consume a
`pipelinq.PosLine.stockMovement` event. **That event does not exist.** Verified
on `origin/development`:

- **pipelinq** has `posTransaction` / `posTransactionLine` / `PosTenderService`,
  and they are entirely **stock/inventory-unaware** (`grep -n "Stock\|Inventory"
  lib/Service/PosTransactionService.php` → zero hits). Its only cross-app event,
  `TenderPostedEvent` (`nl.pipelinq.pos.tender.posted`), is a **GL/payment**
  posting event — it carries tender/payment lines, not sold-item quantities.
- **shillinq** already has a real, working outbound-stock pipeline for its own
  B2B flow: `DeliveryDispatchListener` → `SalesDispatchStockIssueService` →
  `InventoryGlAdjustmentPoster`, driven by shillinq's own `Delivery` object
  transitions (PR #404 + the archived `block-unsellable-stock-dispatch`). This
  is the pattern the POS consumer should reuse — but it is triggered by a
  shillinq Delivery, not by a pipelinq POS sale.

So this cannot be built in shillinq alone. It requires a **companion pipelinq
change** to emit a sold-line stock event, and this change to consume it.

## What changes

**Pipelinq side (companion change — MUST land first or in lockstep):**
- Emit a typed cross-app event on POS-sale completion — e.g.
  `PosStockMovedEvent` (`nl.pipelinq.pos.stock.moved`) — carrying, per sold
  line: product identifier (the SKU/`productRef` shillinq keys inventory on),
  quantity sold, unit, location/administration, POS transaction id, and
  timestamp. Fire it from the same commit path as `TenderPostedEvent` so a sale
  posts payment AND stock atomically.

**Shillinq side (this change):**
- A `PosStockDecrementListener` consuming that event (fail-closed
  `class_exists()` guard like the signing/delegation listeners), mapping each
  POS line's product identifier to the shillinq inventory item and calling the
  existing `SalesDispatchStockIssueService` decrement + `InventoryGlAdjustmentPoster`
  COGS-post path — NOT a new bespoke decrement engine.
- Idempotency keyed on the POS transaction id (a retried event must not
  double-decrement), and a reconciliation/audit surface for lines whose product
  can't be matched to a shillinq item (fail-soft, logged, surfaced — never a
  silent stock drift).
- Registered in `Application.php`; RBAC/tenant scoping via the existing
  ObjectService path.

## Out of scope / dependencies

- Requires the pipelinq companion change above. Without it this change has
  nothing to listen to and MUST NOT be built as an orphaned listener.
- `inventory-stock-tracking` and `inventory-cogs-posting` (both already
  archived) provide the decrement + COGS primitives this reuses.
- Multi-location transfer semantics, negative-stock policy, and POS returns
  (re-increment) are follow-ups once the forward decrement path is proven.

## Status

**Both sides built (2026-07-23), NOT yet archivable.** The companion pipelinq
producer (`PosStockMovedEvent`, branch `wip/pipelinq-pos-stock-moved-event`)
and this shillinq consumer (`PosStockDecrementListener`, branch
`wip/shillinq-i504`) landed in the same session. Both are unit-tested
(pipelinq: 9 new tests; shillinq: 8 new tests including an end-to-end
in-memory correctness proof of decrement + COGS + posTxnId idempotency) and
statically wiring-verified (matching FQCN on both ends, proper `use` import
on the listener registration — not a #507-class phantom).

**Still open before archiving**: a LIVE end-to-end run on a running instance
with both apps installed — a real pipelinq POS sale settling and shillinq's
matching inventory item decrementing + COGS posting. Not attempted this
session. Do not archive until that live verification is done.

Also note a correction against this proposal's own text above: the COGS post
is NOT via `InventoryGlAdjustmentPoster` (that service is the landed-cost/NRV
valuation adapter, unrelated to sales COGS) — it is `CogsPosterService`,
fired automatically by the pre-existing `StockMoveTransitionedListener`
pipeline once `SalesDispatchStockIssueService::issueForDelivery()` posts the
StockMove. See `tasks.md` §2 and the listener's docblock for the full
correction.
