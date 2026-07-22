# Tasks: inventory-pos-decrement

> **BLOCKED** on the companion pipelinq change (the `PosStockMovedEvent`
> producer). Do not implement the shillinq listener until that event exists —
> an unfired-event listener is an orphaned capability.

## 1. Companion pipelinq change (separate repo — prerequisite)
- **[BLOCKED — pipelinq]** Define + emit `PosStockMovedEvent` (`nl.pipelinq.pos.stock.moved`) on POS-sale commit, carrying per-line {productRef/SKU, qty, unit, location, posTxnId}, administrationId, ts — fired atomically with `TenderPostedEvent`.

## 2. Shillinq consumer (this change — after §1)
- **[BLOCKED — needs §1]** `PosStockDecrementListener` consuming the event, fail-closed `class_exists()` guard, registered in `Application.php`.
- **[BLOCKED — needs §1]** Map POS `productRef` → shillinq inventory item; delegate to `SalesDispatchStockIssueService` (decrement) + `InventoryGlAdjustmentPoster` (COGS).
- **[BLOCKED — needs §1]** Idempotency keyed on `posTxnId`; redelivery is a no-op.
- **[BLOCKED — needs §1]** Unmatched lines: fail-soft + audit surface (no silent stock drift).
- **[BLOCKED — needs §1]** Unit tests: decrement path, COGS post, idempotency, unmatched-line audit, event-absent fail-closed.

## 3. Verification
- **[BLOCKED — needs §1+§2]** Live end-to-end: a pipelinq POS sale decrements the matching shillinq inventory item and posts COGS, on a running instance.
