# Tasks — prestatieverklaring-service-receipt

## Registers (declarative, ADR-031)

- [x] Declare `SvcReceipt` schema (lifecycle `draft → confirmed → accepted / rejected`) in member-12 register.d fragment
- [x] Declare `SvcReceiptLine` schema (poLineId, period, percentage/quantity/amount confirmation fields) in the same fragment

## ServiceReceiptService

- [x] Implement `createServiceReceipt()` — poIds[], approver (session-derived), period
- [x] Implement `addServiceReceiptLine()` — derive quantityAccepted from percentage/quantity/amount confirmation
- [x] Implement `confirmServiceReceipt()` — transition draft → confirmed
- [x] Implement `acceptServiceReceipt()` — transition confirmed → accepted, recompute PO receipt lifecycle

## ThreeWayMatchingEngine integration

- [x] Resolve accepted `SvcReceipt` as an alternative third leg alongside `GoodsReceiptNote` in `evaluateMatch()`
- [x] Merge `SvcReceiptLine` rows into the same tuple pool `matchLineItems()` matches against PO lines

## Controller + routes

- [x] Implement `ServiceReceiptController` (create, addLine, confirm, accept) mirroring `GoodsReceiptNoteController`
- [x] Register routes in `appinfo/routes.php`

## Tests

- [x] Unit tests for `ServiceReceiptService` (create, add line per confirmation mode, confirm, accept, PO lifecycle recompute)
- [x] New `ThreeWayMatchingEngineTest` case proving a service PO + supplier invoice now reaches a matched state
- [x] Test proving partial periodic confirmation accumulates correctly (landed as `ServiceReceiptServiceTest::testAcceptAccumulatesAcrossPeriodicReceipts` rather than a `ThreeWayMatchingEngineTest` case — the accumulation logic lives entirely in `ServiceReceiptService::updatePurchaseOrderReceiptLifecycle()`, so that is where it is most directly exercised)

## Spec maintenance

- [x] Add REQ-PO3W-011 to `openspec/specs/bookkeeping-purchase-order-3way/spec.md` on archive
- [x] Update canonical spec's OpenSpec changes list
