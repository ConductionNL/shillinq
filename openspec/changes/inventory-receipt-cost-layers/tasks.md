# Tasks: inventory-receipt-cost-layers

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Receipts

- [x] 1.1 Create receipt moves as drafts and post them through the transition engine with the dispatch path's fallback (REQ-IRCL-001, REQ-IRCL-002). Verify: PHPUnit asserting one layer and one GR/IR entry per accepted line; the FIFO example costed at EUR 932.50. Built: `lib/Service/StockMovePosting.php` saves the move as an unlocked draft and fires `post` through OpenRegister's TransitionEngine, falling back to a posted, locked write with a warning when the engine is unavailable or refuses; `GoodsReceiptNoteService::postReceiptStockMove()` uses it. Tests: `StockMovePostingTest` (engine, no engine, failing engine), `GoodsReceiptNoteIntegrationTest::testAcceptPostsEachReceiptThroughTheTransitionEngine` (one draft and one `post` per accepted line), `ReceiptCostLayersFlowTest` (the Tarwebloem example: 40 bags worth EUR 740 after the first receipt, the 50-bag issue costed EUR 932.50, 30 bags worth EUR 577.50 left). Not asserted here: one GR/IR entry per line; that entry is posted by `GRIRClearingListener` on the GRN's acceptance, which this change does not touch.

## 2. Backfill

- [x] 2.1 `BackfillReceiptCostLayers` repair with the undercosted issue report (REQ-IRCL-003). Verify: PHPUnit on a seeded register; `npm run check:job-registration` if it is scheduled. Built: `lib/Service/Inventory/StockLedgerReplay.php` replays one product's posted receipts and issues at one location in posting order at FIFO or moving average; `lib/Service/Inventory/ReceiptCostLayerBackfill.php` writes the snapshot the layers give (posting nothing) and lists every issue whose posted COGS (the debit lines `CogsPosterService` wrote under its `sourceReference`) differs from the layer-based one; `lib/Repair/BackfillReceiptCostLayers.php` runs it once (app config `receipt_cost_layers_backfilled`), logs each listed issue with both amounts and is registered in post-migration. The report is the log and the upgrade output, not a report object: no schema holds one, and adding a schema for a one-off list was not worth a register bump. Tests: `StockLedgerReplayTest`, `ReceiptCostLayerBackfillTest` (seeded register: the flour's layers land, 30 bags worth EUR 577.50, the zero-costed issue listed with EUR 932.50, nothing posted), `BackfillReceiptCostLayersTest`. Not scheduled, so `check:job-registration` is unaffected (it passes).

## 3. Page, end to end and strings

- [x] 3.1 Stock valuation page over `/api/inventory/valuation-report` with a date (REQ-IRCL-004). Verify: `npm run check:manifest`, nav reachability. Built: page `InventoryValuationReport` (`/inventory/valuation-report`, Inventory > Stock valuation) in `src/manifest.d/inventory-valuation-fifo-avg.json`: a date input writing `@workspace.valuationDate`, the stock value tile and the per product and location table over the endpoint. The endpoint now falls back to `buildContext()['activeAdministrationId']` when the request names no administration, and still asks `canAccess()` for it (`InventoryValuationReportControllerTest`: active one read, a named one not replaced, none still 400, an inaccessible active one 404). `check:manifest`, `check:nav-reachability`, `check:manifest-shell` and `check:manifest-budget` pass.
- [ ] 3.2 Playwright `tests/e2e/inventory-receipt-cost-layers.spec.ts`: receive, dispatch, read the valuation. Verify: passes locally.
- [ ] 3.3 Live check on an instance: accept a GRN and read the layer (the matrix asked for one). Verify: object ids in the PR body.
- [x] 3.4 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`. The page's labels in `l10n/en.json` and `l10n/nl.json`, `.js` rebuilt; both checks and `test:l10n-dutch-tokens` pass.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
