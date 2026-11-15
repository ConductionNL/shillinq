# Tasks: inventory-receipt-cost-layers

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Receipts

- [x] 1.1 Create receipt moves as drafts and post them through the transition engine with the dispatch path's fallback (REQ-IRCL-001, REQ-IRCL-002). Verify: PHPUnit asserting one layer and one GR/IR entry per accepted line; the FIFO example costed at EUR 932.50. Built: `lib/Service/StockMovePosting.php` saves the move as an unlocked draft and fires `post` through OpenRegister's TransitionEngine, falling back to a posted, locked write with a warning when the engine is unavailable or refuses; `GoodsReceiptNoteService::postReceiptStockMove()` uses it. Tests: `StockMovePostingTest` (engine, no engine, failing engine), `GoodsReceiptNoteIntegrationTest::testAcceptPostsEachReceiptThroughTheTransitionEngine` (one draft and one `post` per accepted line), `ReceiptCostLayersFlowTest` (the Tarwebloem example: 40 bags worth EUR 740 after the first receipt, the 50-bag issue costed EUR 932.50, 30 bags worth EUR 577.50 left). Not asserted here: one GR/IR entry per line; that entry is posted by `GRIRClearingListener` on the GRN's acceptance, which this change does not touch.

## 2. Backfill

- [ ] 2.1 `BackfillReceiptCostLayers` repair with the undercosted issue report (REQ-IRCL-003). Verify: PHPUnit on a seeded register; `npm run check:job-registration` if it is scheduled. (not built yet: left for a follow-up build)

## 3. Page, end to end and strings

- [ ] 3.1 Stock valuation page over `/api/inventory/valuation-report` with a date (REQ-IRCL-004). Verify: `npm run check:manifest`, nav reachability. (not built: the endpoint requires `administration_id` and the manifest has no token for the active administration; the page needs the endpoint to fall back to `buildContext()['activeAdministrationId']` the way BankRuleController does, an access-path change for its own review)
- [ ] 3.2 Playwright `tests/e2e/inventory-receipt-cost-layers.spec.ts`: receive, dispatch, read the valuation. Verify: passes locally.
- [ ] 3.3 Live check on an instance: accept a GRN and read the layer (the matrix asked for one). Verify: object ids in the PR body.
- [ ] 3.4 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
