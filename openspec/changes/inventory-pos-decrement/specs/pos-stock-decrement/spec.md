# Spec: pos-stock-decrement (delta)

## ADDED Requirements

### Requirement: A completed pipelinq POS sale SHALL decrement shillinq stock and post COGS

When pipelinq commits a POS sale, shillinq MUST decrement the on-hand quantity
of each sold inventory item and post the corresponding COGS journal entry, by
consuming a typed cross-app stock event (`PosStockMovedEvent`) — reusing the
existing `SalesDispatchStockIssueService` + `InventoryGlAdjustmentPoster` path,
not a bespoke decrement engine. Processing MUST be idempotent on the POS
transaction id, and a sold line whose product cannot be matched to a shillinq
inventory item MUST be surfaced to an audit/reconciliation view rather than
silently dropped.

This requirement is BLOCKED until pipelinq emits the producer event; shillinq
MUST NOT ship the consumer listener before then (an unfired-event listener is an
orphaned capability).

#### Scenario: A POS sale decrements the matching inventory item and posts COGS

- **GIVEN** a shillinq inventory item keyed by SKU `SKU-1001` with on-hand quantity 10, and a pipelinq POS sale of 3 units of `SKU-1001`
- **WHEN** pipelinq commits the sale and emits `PosStockMovedEvent` carrying `{productRef: "SKU-1001", qty: 3, posTxnId: "POS-2026-0001"}`
- **THEN** shillinq's listener MUST decrement the item's on-hand quantity to 7 and post a COGS journal entry for the 3 units, via `SalesDispatchStockIssueService` / `InventoryGlAdjustmentPoster`

#### Scenario: A redelivered event does not double-decrement

- **GIVEN** `PosStockMovedEvent` for `posTxnId: "POS-2026-0001"` has already been processed
- **WHEN** the same event is delivered again (at-least-once transport)
- **THEN** shillinq MUST treat it as a no-op and MUST NOT decrement stock or post COGS a second time

#### Scenario: An unmatched product line is audited, not dropped

- **GIVEN** a `PosStockMovedEvent` line whose `productRef` matches no shillinq inventory item
- **WHEN** the listener processes the event
- **THEN** the line MUST be recorded to a reconciliation/audit surface and logged — and MUST NOT silently vanish, so stock drift is always visible
