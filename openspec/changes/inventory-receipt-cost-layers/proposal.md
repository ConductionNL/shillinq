---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: inventory-receipt-cost-layers

## Summary

Stock is valued at FIFO or average cost, and the cost of goods sold is read
from that value when stock leaves. In shillinq the engines and the COGS
posting exist, but goods received through a goods receipt note never reach
the valuation engine, so issues are costed against layers that purchases
never filled. This change routes receipts through the same transition as
issues, so every receipt adds its cost layer, and shows the valuation report
on a page.

## Motivation

Two inventory rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) share this
missing half; the OpenSpec pass of 2026-09-27 decided `build` for both.

**`inv-valuation`**, "Value stock at FIFO or average cost and post it to the
ledger." Rated partial, built. Matrix evidence:
"lib/Listener/StockMoveTransitionedListener.php:103-112 runs
FifoValuationService/MovingAverageValuationService only on an
ObjectTransitionedEvent to 'posted'; GoodsReceiptNoteService.php:26-29/606
creates receipt StockMoves already posted via saveObject, and
GRIRClearingListener.php:24 notes OR fires no transition event for a state
set at create, so purchase receipts never add FIFO layers;
/api/inventory/valuation-report is absent from" the frontend. Two
competitors rate it yes: snelstart
(https://kennisplein.snelstart.nl/snelstartpolaris/uitgebreid-voorraadbeheer,
"financieel voorraadbeheer") and odoo
(`addons/stock_account/models/product.py:14`, `cost_method` fifo and
average).

**`inv-cogs`**, "Post the cost of goods sold when stock leaves." Rated
partial, built: "The trigger and posting are wired; the cost figure depends
on receipt layers that GRN receipts never create." Three competitors rate it
yes: exact-online
(https://support.exactonline.com/community/s/article/All-All-HNO-Reference-financial-glaccounts-fingl-createdgltransr?language=en_GB),
snelstart ("Bij verkopen wordt de kostprijs van de omzet geboekt") and odoo
(`addons/l10n_nl/models/template_nl.py`, anglo-saxon accounting with
account 7000).

## Affected Projects

- [ ] Project: `shillinq`: receipt stock moves created as drafts and posted through the transition engine, a backfill of missing layers, and a valuation report page.

## Scope

### In Scope

- `GoodsReceiptNoteService` creating the receipt `StockMove` as a draft and posting it through OpenRegister's transition engine, as the dispatch path already does.
- A repair step that adds the missing cost layers for receipts posted before the change, in posting order, and lists issues costed without layers.
- A page for the valuation report.

### Out of Scope

- Reposting past COGS. The repair lists the issues costed without layers with the difference; correcting them is an accountant's journal entry.
- Cycle count adjustments, which use the same direct-posted convention but a movement type the valuation listener does not handle.

## Approach

Create-then-transition, the pattern `SalesDispatchStockIssueService` uses
for issues. Details are in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/GoodsReceiptNoteService.php`: `postReceiptStockMove()`.
- `lib/Repair/`: one repair step.
- `src/manifest.d/`: the valuation report page.

## Cross-Project Dependencies

None. Depends on `ledger-posting-path` (this repo): once receipt moves are
transitioned, `StockMove.post` would fire its declared
`materialise-gl-transaction`; that change removes the declaration because
the listeners already book stock.

## Risks

### Risk 1: A receipt is booked twice
**Severity:** High. **Mitigation:** the receipt's ledger entry stays with `GRIRClearingListener` on acceptance; the valuation listener posts COGS on issues only (`StockMoveTransitionedListener`); a test asserts one GR/IR entry and one layer per accepted receipt line.

## Rollback Strategy

Return `postReceiptStockMove()` to the direct-posted create. Layers already
added stay valid.

## Open Questions

None.
