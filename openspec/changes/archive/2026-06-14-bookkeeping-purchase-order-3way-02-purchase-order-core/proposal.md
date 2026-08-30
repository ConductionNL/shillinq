---
kind: code
depends_on: [bookkeeping-purchase-order-3way-01-schemas-and-registers]
chain:
  - bookkeeping-purchase-order-3way-01-schemas-and-registers
  - bookkeeping-purchase-order-3way-02-purchase-order-core
  - bookkeeping-purchase-order-3way-03-peppol-transmission
  - bookkeeping-purchase-order-3way-04-goods-receipt-note
  - bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion
  - bookkeeping-purchase-order-3way-06-matching-engine
  - bookkeeping-purchase-order-3way-07-multi-po-consolidation
  - bookkeeping-purchase-order-3way-08-exception-workflow
  - bookkeeping-purchase-order-3way-09-gl-gr-ir-clearing
  - bookkeeping-purchase-order-3way-10-vendor-performance
  - bookkeeping-purchase-order-3way-11-audit-trail-export
---

# Proposal: bookkeeping-purchase-order-3way-02-purchase-order-core

Member 2 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-01-schemas-and-registers`
(which declared the `PurchaseOrder` / `PurchaseOrderLine` registers and
lifecycle). This member is the first `kind: code` member: it consumes the
`PurchaseOrder` register to implement **PO creation + amount-threshold
approval-chain routing + the block-send-until-approved guard**, plus the
PO form and detail Vue views.

## Why (carried from the giant)

REQ-PO3W-001: the 3-way match begins with a controlled purchase order.
An Inkoper creating a PO for €18,500 must have the system determine the
required approval chain by amount threshold (>€10k = Teamleider +
Facility Manager), notify approvers, and block transmission to the
supplier until every approval is signed with a timestamp. This is the AP
fraud-prevention control's first gate.

## What this member does

- `PurchaseOrderService`: `createPurchaseOrder()` (validates requester,
  checks cost_center budget, generates po_number per CBS rules),
  `determineApprovalChain()` (evaluates amount, returns ordered approver
  roles), `blockSendUntilApproved()` (prevents transition to "sent"
  while approval_chain incomplete)
- `PurchaseOrderForm.vue` (line-item entry, cost-center/project picker,
  approval-chain display)
- `PurchaseOrderDetail.vue` (header + lines + approval chain + approval
  history with timestamps; links to related GRNs + matches)
- Unit tests for approval-chain routing across amount thresholds (€5k
  single-approver, €10k double-approver, €50k + procurement manager)
- Integration test: PO creation → approval-chain materialisation

Peppol/PDF transmission of the approved PO is deferred to member 03.

## Scope

### In Scope
- `PurchaseOrderService` (create, approval-chain routing, send-block guard)
- `PurchaseOrderForm.vue`, `PurchaseOrderDetail.vue`
- Approval-chain routing unit tests + PO-creation integration test

### Out of Scope
- Peppol / PDF transmission — member 03
- GRN, matching, GL, vendor scoring, audit export — members 04-11
- Register/manifest declaration — member 01 (predecessor)

## Impact
- `lib/Service/PurchaseOrderService.php`
- `src/components/PurchaseOrderForm.vue`, `src/components/PurchaseOrderDetail.vue`
- `tests/` unit + integration for approval-chain routing
