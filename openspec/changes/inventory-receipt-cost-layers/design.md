# Design: inventory-receipt-cost-layers

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Receipts skip the engine.** `GoodsReceiptNoteService::postReceiptStockMove()` (`lib/Service/GoodsReceiptNoteService.php:540`) writes a `StockMove` with `lifecycleState: posted`, `locked: true`, `movementType: receipt` and `unitCost` from the PO line (lines 569-592), straight through `saveObject` (line 609). Its docblock (lines 26-29) names the direct-posted convention.
- **The engine listens to transitions.** `StockMoveTransitionedListener::handle()` (`lib/Listener/StockMoveTransitionedListener.php:103`) acts only on `ObjectTransitionedEvent` to `posted` for `StockMove`, runs `FifoValuationService::processStockMove()` or `MovingAverageValuationService::processStockMove()` for receipts and issues, and calls `CogsPosterService::postCogs()` for issues. OpenRegister dispatches no transition event for a state set at create (`lib/Listener/GRIRClearingListener.php:18-26` says so).
- **Issues already transition.** `SalesDispatchStockIssueService` (docblock at line 476) creates a draft issue move and drives it to `posted` through the transition engine, falling back to the direct-posted create when the engine is unavailable.
- **Receipt ledger entry.** `GRIRClearingListener` posts the GR/IR entry when a goods receipt note is accepted.
- **Report.** `GET /api/inventory/valuation-report` (`appinfo/routes.php:249`, `InventoryValuationReportController::report`) replays the stock move ledger; no page calls it.
- **Declared action.** `StockMove.post` declares `materialise-gl-transaction` (`register.d/inventory-stock-movement-ledger.json`), removed by `ledger-posting-path` D4.

## Goals / Non-Goals

**Goals**
- Every accepted receipt line adds a FIFO layer or updates the average cost.
- Issues are costed from those layers.
- The valuation can be read on a page.

**Non-Goals**
- Reposting past COGS, cycle counts.

## Decisions

### D1. Receipts are created as drafts and transitioned

`postReceiptStockMove()` creates the move with `lifecycleState: draft` and
`locked: false`, then calls the transition engine with `post`, exactly as
`SalesDispatchStockIssueService` does, including its fallback. The move is
locked by the `post` transition, not by the create.

Alternative considered: call the valuation service from
`GoodsReceiptNoteService` directly. Rejected: it would be a second entry
point into valuation, and the listener is already the one the issue path
uses.

### D2. A repair adds missing layers once

`lib/Repair/BackfillReceiptCostLayers.php` finds posted receipt moves with
no layer, in `postedAt` order per product and location, and feeds them to
the valuation service with a flag that skips any posting. It writes a
report object listing issue moves costed before their receipts' layers
existed, with the COGS they carried and the COGS the layers now give.

### D3. A page for the valuation report

`InventoryValuationReport` page under the inventory menu reads the existing
endpoint and shows quantity, value and unit cost per product and location,
with a date.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Stock move posting | Declarative lifecycle, unchanged | The `post` transition. |
| Valuation on post | Imperative, the existing listener (ADR-078) | Unchanged; receipts now reach it. |
| Backfill | Imperative, a repair step | One-off data repair. |

## Seed Data

Bakkerij Jansen, product "Tarwebloem 25 kg" (FIFO): receipt of 40 bags at
EUR 18.50 on 2026-09-02 and 40 bags at EUR 19.25 on 2026-09-16; an issue of
50 bags on 2026-09-20 is costed 40 × 18.50 + 10 × 19.25 = EUR 932.50.

## Risks / Trade-offs

- [The transition engine is unavailable] → the fallback keeps today's behaviour and logs that the layer was not added, which the valuation page shows as a warning.

## Migration Plan

The repair step of D2 runs once on upgrade.

## Open Questions

None.
