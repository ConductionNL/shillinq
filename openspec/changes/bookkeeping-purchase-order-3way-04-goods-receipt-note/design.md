# Design — Member 04: Goods Receipt Note (code)

## Context

`kind: code` member implementing GRN capture, consuming the
`GoodsReceiptNote` / `GoodsReceiptLine` registers (member 01) and the
`PurchaseOrder` lines (member 02).

## Decisions

### D2 — GRN is the source-of-truth for goods physically received

Carried from the giant's D2. The GRN is independent of PO status: a single
GRN can span multiple POs (po_ids[]), and partial shipments are first-class.
GRN lifecycle draft → received → quality_checked → accepted → rejected is
declared in member 01; this member drives the transitions.

### D6 (boundary) — accept triggers GR/IR, but the posting lands in member 09

On `acceptGRN()` the lifecycle transition fires the GR/IR clearing
posting. The posting logic (DR Inventory / CR GR/IR Clearing) is specified
and implemented in member 09; this member only invokes the transition so
the chain stays atomic per member.

### D3 — Inventory credit is for accepted quantity only

On accept, inventory is credited for quantity_accepted at the PO line
gl_account. Rejected quantities never decrement or credit inventory.

## Security (ADR-005)

- GRN mutation is server-authoritative; received_by / inspector identities
  are validated server-side, not trusted from the request body.
- Photo upload goes through docudesk with the receiving user's context.

## Reuse
- OR `x-openregister-lifecycle` (member 01) for GRN states
- inventory-stock-tracking for accepted-quantity credit
- docudesk for delivery-condition photos
- nextcloud-vue for the mobile-optimised form
