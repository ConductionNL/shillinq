# Tasks: inventory-pos-decrement

> Companion pipelinq change landed in lockstep (same build session, 2026-07-23):
> `pipelinq` branch `wip/pipelinq-pos-stock-moved-event`
> (`openspec/changes/pos-stock-moved-event` in the pipelinq repo). Both sides
> unit-tested and statically wiring-verified (matching FQCN both ends); a
> LIVE running-instance end-to-end run is still open — see §3.

## 1. Companion pipelinq change (separate repo — prerequisite)
- [x] Define + emit `PosStockMovedEvent` (`nl.pipelinq.pos.stock.moved`) on POS-sale commit, carrying per-line {productRef/SKU, qty, unit, location}, posTxnId, administrationId, ts — fired atomically with `TenderPostedEvent` (same commit path inside `settleTransaction()`). Landed on pipelinq `wip/pipelinq-pos-stock-moved-event`, pushed to both codeberg + github.

## 2. Shillinq consumer (this change — after §1)
- [x] `PosStockDecrementListener` consuming the event, fail-closed `class_exists()` guard, registered in `Application.php` **with a proper `use` import** (verified — not a #507-class phantom).
- [x] Map POS `productRef` → shillinq inventory item (InventoryStock existence probe by `(administrationId, sku)`); delegate to `SalesDispatchStockIssueService::issueForDelivery()` (decrement). **Correction vs. this change's draft design.md**: COGS is NOT posted via `InventoryGlAdjustmentPoster` — that service is the landed-cost/NRV valuation adapter, unrelated to sales COGS. The real COGS poster (`CogsPosterService`) fires automatically once `issueForDelivery()` posts the StockMove, via the pre-existing `StockMoveTransitionedListener` pipeline (same one `DeliveryDispatchListener` already drives). Calling `InventoryGlAdjustmentPoster` in addition would double-post. See the listener's docblock for the full correction.
- [x] Idempotency keyed on `posTxnId`; redelivery is a no-op — reused via `issueForDelivery()`'s own `referenceDocumentUri` dedup (synthetic delivery id `pos-{posTxnId}`), no separate marker.
- [x] Unmatched lines: fail-soft + audit surface — logged at error level AND raised as a Nextcloud notification to every `admin` group member (`PosStockUnmatchedLineNotifier`), never silently dropped. Also covers a lot-unsellable line (`blockedLines` from `issueForDelivery()`'s result).
- [x] Unit tests (8, all green): non-event / empty-event fail-closed, matched-line delegation, downstream-exception fail-soft, unmatched-line audit (incl. empty productRef), mixed matched+unmatched, and one correctness-proof test wiring REAL `SalesDispatchStockIssueService` + `StockMoveTransitionedListener` + `FifoValuationService` + `CogsPosterService` (only ObjectService/TransitionEngine faked) proving decrement + COGS + posTxnId-idempotent redelivery end-to-end in-memory.

## 3. Verification
- [x] Static wiring verified both repos: pipelinq's `emitStockMovedEvent()` dispatch site inside `settleTransaction()`; shillinq's `registerEventListener(event: \OCA\Pipelinq\Event\PosStockMovedEvent::class, listener: PosStockDecrementListener::class)` with matching FQCN + proper `use` import.
- [ ] **Still open**: live end-to-end on a running instance with BOTH apps installed — a real pipelinq POS sale settling and shillinq's matching inventory item decrementing + COGS posting. Not attempted this session (would require deploying both apps' new code to the shared dev instance and is a larger, separate live-verification pass — do not archive this change until that is done).
