# Design — Member 06: 3-way Matching Engine (code)

## Context

`kind: code` member implementing the core matching engine, consuming the
`PurchaseOrder`/`PurchaseOrderLine` (members 01-02), `GoodsReceiptNote`/
`GoodsReceiptLine` (member 04), `SupplierInvoice` (member 05),
`ThreeWayMatch`, and `ToleranceProfile` registers (member 01).

## Decisions

### D3 — Matching happens at LINE level

Carried from the giant's D3. The engine matches individual PO lines to GRN
lines to invoice line items on (product_code, quantity, price, vat),
computing price_delta, quantity_delta, vat_delta, date_delta per line.

### D4 — Configurable tolerances, "more permissive" rule

Carried from the giant's D4. `ToleranceProfileService.getApplicableProfile()`
resolves the most-specific profile (supplier > category > gl_account >
global). Price tolerance is "€X absolute OR Y% relative, whichever is MORE
permissive". Lines within tolerance set match_status to auto_approved /
within_tolerance; lines outside route to exception (the routing call lands
here, but the resolution UI is member 08).

### Boundary — single-PO here, consolidation in member 07

This member handles the single-PO matching path. Multi-PO consolidated
invoices (one invoice → many POs with ambiguous candidates) are member 07.

## Security (ADR-005)

- Tolerance evaluation and match_status are server-authoritative; the
  client never asserts a match outcome.
- The applicable ToleranceProfile is resolved server-side from the record,
  not supplied by the request.

## Reuse
- OR `x-openregister-aggregations` for line-level divergence sums
- `ThreeWayMatch` + `ToleranceProfile` registers (member 01)
- nextcloud-vue for the index view
