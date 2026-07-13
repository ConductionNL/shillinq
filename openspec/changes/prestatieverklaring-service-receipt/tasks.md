# Tasks — prestatieverklaring-service-receipt

## Registers (declarative, ADR-031)

- [ ] Declare `SvcReceipt` schema (lifecycle `draft → confirmed → accepted / rejected`) in member-12 register.d fragment
- [ ] Declare `SvcReceiptLine` schema (poLineId, period, percentage/quantity/amount confirmation fields) in the same fragment

## ServiceReceiptService

- [ ] Implement `createServiceReceipt()` — poIds[], approver (session-derived), period
- [ ] Implement `addServiceReceiptLine()` — derive quantityAccepted from percentage/quantity/amount confirmation
- [ ] Implement `confirmServiceReceipt()` — transition draft → confirmed
- [ ] Implement `acceptServiceReceipt()` — transition confirmed → accepted, recompute PO receipt lifecycle

## ThreeWayMatchingEngine integration

- [ ] Resolve accepted `SvcReceipt` as an alternative third leg alongside `GoodsReceiptNote` in `evaluateMatch()`
- [ ] Merge `SvcReceiptLine` rows into the same tuple pool `matchLineItems()` matches against PO lines

## Controller + routes

- [ ] Implement `ServiceReceiptController` (create, addLine, confirm, accept) mirroring `GoodsReceiptNoteController`
- [ ] Register routes in `appinfo/routes.php`

## Tests

- [ ] Unit tests for `ServiceReceiptService` (create, add line per confirmation mode, confirm, accept, PO lifecycle recompute)
- [ ] New `ThreeWayMatchingEngineTest` case proving a service PO + supplier invoice now reaches a matched state
- [ ] New `ThreeWayMatchingEngineTest` case proving partial periodic confirmation accumulates correctly

## Spec maintenance

- [ ] Add REQ-PO3W-011 to `openspec/specs/bookkeeping-purchase-order-3way/spec.md` on archive
- [ ] Update canonical spec's OpenSpec changes list
