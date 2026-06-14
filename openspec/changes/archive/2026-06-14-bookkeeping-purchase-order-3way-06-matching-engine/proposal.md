---
kind: code
depends_on: [bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion]
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

# Proposal: bookkeeping-purchase-order-3way-06-matching-engine

Member 6 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion`.
This `kind: code` member implements the **core 3-way matching engine** —
line-level matching of PO ↔ GRN ↔ Invoice with configurable tolerance
evaluation (REQ-PO3W-004, REQ-PO3W-006). The auto-approve / within-tolerance
path lands here; exception resolution UI is member 08, consolidation is 07.

## Why (carried from the giant)

REQ-PO3W-004: an invoice for €18,547 against an €18,500 PO with a 180-unit
GRN must be evaluated line-level on (product_code, quantity, price, vat).
A €47 delta = 0.25% is within the €10-absolute-OR-0.5%-relative tolerance
(whichever is more permissive), so the match auto-approves and routes to
payment with no human review. REQ-PO3W-006: a controller can override the
global tolerance per supplier/category/GL-account, and the engine applies
the most-specific profile.

## What this member does

- `ThreeWayMatchingEngine`: `evaluateMatch(invoiceId)`, `matchLineItems()`,
  `calculateDivergence()`, `evaluateTolerance()`, `routeToException()`
- `ToleranceProfileService`: `getApplicableProfile()` (most-specific:
  supplier > category > gl_account > global), `evaluateWithinTolerance()`,
  `evaluateQuantityVariance()`, `evaluateDateVariance()`
- Writes a `ThreeWayMatch` record with match_status + divergence_details
- `ThreeWayMatchIndex.vue` (filterable table by match_status)
- Unit tests for line matching, tolerance logic (absolute vs %,
  "more permissive"), and profile scope resolution; integration tests for
  auto-approve and exception-routing cases

## Scope

### In Scope
- `ThreeWayMatchingEngine` (single-PO line matching + tolerance evaluation)
- `ToleranceProfileService` (scope resolution + tolerance checks)
- `ThreeWayMatchIndex.vue`
- Matching + tolerance unit/integration tests

### Out of Scope
- Multi-PO consolidated-invoice disambiguation — member 07
- Exception resolution panel + actions — member 08
- GL settlement posting — member 09

## Impact
- `lib/Service/ThreeWayMatchingEngine.php`, `lib/Service/ToleranceProfileService.php`
- `src/components/ThreeWayMatchIndex.vue`
- `tests/` matching + tolerance
