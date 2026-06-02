# Tasks — Member 02: Purchase Order Core (code)

## PurchaseOrderService

- [ ] Implement `PurchaseOrderService::createPurchaseOrder()` — validate requester, check cost_center budget, generate po_number per CBS rules
- [ ] Implement `determineApprovalChain()` — evaluate PO amount, return ordered list of approver roles
- [ ] Implement `blockSendUntilApproved()` — prevent transition to "sent" while approval_chain incomplete
- [ ] Assign ApprovalTask records to each required approver and notify them via the notification service

## Vue views

- [ ] Create `PurchaseOrderForm.vue` — line-item entry (product_code, quantity, unit_price, vat_rate, gl_account)
- [ ] PurchaseOrderForm: cost center + project code picker
- [ ] PurchaseOrderForm: approval chain display (status of each required approver)
- [ ] Create `PurchaseOrderDetail.vue` — header + line items + approval chain + approval history with timestamps
- [ ] PurchaseOrderDetail: link to related GoodsReceiptNotes + ThreeWayMatches

## Tests

- [ ] Write unit tests for approval-chain routing (€5k single-approver, €10k double-approver, €50k + procurement manager)
- [ ] Write integration test: PO creation → approval-chain materialisation + approver notification
