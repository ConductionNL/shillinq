# Design — Member 02: Purchase Order Core (code)

## Context

`kind: code` member consuming the `PurchaseOrder` / `PurchaseOrderLine`
registers declared in member 01. Implements PO creation, amount-threshold
approval-chain routing, and the send-block guard, plus the PO form and
detail views.

## Decisions

### D1 — PurchaseOrder is a controlled sub-ledger with amount-based approval

Carried from the giant's D1. PO records are read/written through
OpenRegister `ObjectService` (ADR-001) — no bespoke mapper. The
`approval_chain[]` field (declared in member 01) is populated by
`determineApprovalChain()` evaluating the PO total against thresholds:
€5k single-approver (Teamleider), €10k double-approver (Teamleider +
Facility Manager), €50k adds procurement manager.

### D2 — Send-block guard is server-authoritative (ADR-005)

`blockSendUntilApproved()` is enforced in the service layer, not the
client. A PO cannot transition to lifecycle state `sent` until every
`ApprovalTask` in the chain is signed with a timestamp. The Vue layer
only reflects the server's decision; it never grants the transition.

### D3 — po_number generation is CBS-conform and server-side

`createPurchaseOrder()` generates `po_number` deterministically
server-side; the client never supplies it. cost_center budget is checked
at create time.

## Security (ADR-005)

- Approval-chain evaluation and the send-block are server-side only.
- PO mutation endpoints follow NC auth defaults; per-object guards ensure
  the requester/approver identity is validated against the record, not
  trusted from the request body.

## Reuse
- OR `x-openregister-lifecycle` (declared in member 01) for PO states
- OR `ObjectService` for CRUD (ADR-001)
- Notification service for approver notifications
- nextcloud-vue form/detail components for the two views
