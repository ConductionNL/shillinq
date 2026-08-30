# Tasks — Member 02: Purchase Order Core (code)

## PurchaseOrderService

- [x] Implement `PurchaseOrderService::createPurchaseOrder()` — validate requester, check cost_center budget, generate po_number per CBS rules
- [x] Implement `determineApprovalChain()` — evaluate PO amount, return ordered list of approver roles
- [x] Implement `blockSendUntilApproved()` — prevent transition to "sent" while approval_chain incomplete
- [x] Assign ApprovalTask records to each required approver and notify them via the notification service

## Vue views

- [x] Create `PurchaseOrderForm.vue` — line-item entry (product_code, quantity, unit_price, vat_rate, gl_account)
- [x] PurchaseOrderForm: cost center + project code picker
- [x] PurchaseOrderForm: approval chain display (status of each required approver)
- [x] Create `PurchaseOrderDetail.vue` — header + line items + approval chain + approval history with timestamps
- [x] PurchaseOrderDetail: link to related GoodsReceiptNotes + ThreeWayMatches

## Tests

- [x] Write unit tests for approval-chain routing (€5k single-approver, €10k double-approver, €50k + procurement manager)
- [x] Write integration test: PO creation → approval-chain materialisation + approver notification
