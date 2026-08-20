# Design: inventory-pos-decrement

## Producer/consumer split

```
pipelinq (POS)                          shillinq (inventory/bookkeeping)
──────────────                          ────────────────────────────────
POS sale committed
  → PosStockMovedEvent  ──(NC event)──▶  PosStockDecrementListener
    { lines: [{productRef, qty,           → map productRef → inventory item
       unit, location, posTxnId }],       → SalesDispatchStockIssueService (decrement)
      administrationId, ts }              → InventoryGlAdjustmentPoster (COGS post)
                                          → idempotent on posTxnId; unmatched → audit
```

## Key decisions

1. **Reuse, don't rebuild.** The decrement + COGS math already exists
   (`SalesDispatchStockIssueService`, `InventoryGlAdjustmentPoster`) and is
   tested. The listener is a thin adapter from POS-line shape to that path.
2. **Product identity is the hard part.** pipelinq keys POS lines by its own
   product id; shillinq keys inventory by SKU/`productRef`. The event MUST carry
   the shared key (SKU), or the companion pipelinq change must resolve to it
   before emitting. A line that can't be matched is fail-soft + audited, never a
   silent drop.
3. **Idempotency on `posTxnId`** — event delivery is at-least-once; a redelivery
   must be a no-op. Mirror the `migratedFrom`/dedup marker pattern used by the
   fold/signing listeners.
4. **Atomicity on the producer.** Emit stock alongside `TenderPostedEvent` in the
   same commit path so a POS sale never posts payment without stock (or vice
   versa).

## Why not build the listener now

Building the shillinq listener before the pipelinq event exists produces an
**orphaned capability** (a listener for an event nothing fires) — the exact
anti-pattern this program has been removing. The listener lands only once the
producer event is defined.
