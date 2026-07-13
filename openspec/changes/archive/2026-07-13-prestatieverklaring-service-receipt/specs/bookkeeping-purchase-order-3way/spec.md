# bookkeeping-purchase-order-3way Specification (delta)

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- prestatieverklaring-service-receipt

## ADDED Requirements

### Requirement: REQ-PO3W-011 — Service-entry-sheet (prestatieverklaring) as the third leg for service PO lines

The system SHALL satisfy this requirement: a purchase-order line for a
service (consultancy, maintenance, subscription, contract labour) MUST be
able to reach a matched `ThreeWayMatch` state via a **prestatieverklaring**
(`SvcReceipt`) confirming service delivery, without requiring a
`GoodsReceiptNote` that would never physically exist for that line.

**Demand**: An approver named on the service PO confirms delivery for a
period (start/end date), expressed as a percentage complete, a confirmed
quantity, or a confirmed euro amount; the confirmation may be partial and
repeated across multiple billing periods (e.g. monthly for a 12-month
contract). Once a `SvcReceipt` is `accepted`, `ThreeWayMatchingEngine`
MUST treat it exactly as it treats an accepted `GoodsReceiptNote` — as
satisfying the matching engine's third leg — so a service invoice can
reach `auto_approved` / `within_tolerance` instead of being permanently
stuck in `exception_missing_grn`.

#### Scenario: A monthly consultancy retainer confirms delivery and matches the supplier invoice

@e2e exclude pure backend/service matching logic — not browser-testable
(mirrors REQ-PO3W-004's own `@e2e exclude`)

- GIVEN a PurchaseOrder for a monthly consultancy retainer with one
  PurchaseOrderLine (`quantityOrdered: 1`, `unitPrice: 500000`)
- AND an approver creates a `SvcReceipt` for July, adds a `SvcReceiptLine`
  against that PO line with `percentageComplete: 10000` (100%), and
  transitions the receipt `draft → confirmed → accepted`
- WHEN a SupplierInvoice for the same PO arrives with a matching line and
  `ThreeWayMatchingEngine::evaluateMatch()` runs
- THEN the engine resolves the accepted `SvcReceipt` as the third leg (no
  `GoodsReceiptNote` exists or is required), computes divergence the same
  way it would for a goods receipt, and the invoice reaches
  `auto_approved` or `within_tolerance` — a state that was unreachable
  before this change (the only prior outcome for a service PO was
  `exception_missing_grn`)

#### Scenario: Partial periodic confirmation accumulates across billing periods

@e2e exclude pure backend/service matching logic — not browser-testable

- GIVEN a 3-month service PO line with `quantityOrdered: 3` (one unit per
  month)
- WHEN an approver accepts a `SvcReceipt` confirming month 1
  (`quantityAccepted: 1`) and later a second `SvcReceipt` confirming month
  2 (`quantityAccepted: 1`)
- THEN the originating PurchaseOrder's receipt lifecycle recomputes to
  `partial_received` (2 of 3 accepted, mirroring
  `GoodsReceiptNoteService::updatePurchaseOrderReceiptLifecycle()`'s
  existing partial-goods-receipt behaviour) and transitions to
  `fully_received` once month 3 is also accepted

---

## Acceptance Criteria (delta)

- [ ] A service PO line with an accepted `SvcReceipt` can reach
      `auto_approved`/`within_tolerance` on `ThreeWayMatchingEngine::evaluateMatch()`
- [ ] `SvcReceiptLine` confirmation accepts percentage, quantity, or amount
      and derives a comparable `quantityAccepted` server-side
- [ ] Partial/periodic confirmation across multiple `SvcReceipt` records
      accumulates correctly against the PO line's `quantityOrdered`
- [ ] A mixed goods+service PO resolves each PO line against whichever
      receipt pool (GRN or SvcReceipt) has a matching line, independently
