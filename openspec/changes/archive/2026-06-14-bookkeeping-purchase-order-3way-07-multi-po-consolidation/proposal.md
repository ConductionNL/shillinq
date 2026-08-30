---
kind: code
depends_on: [bookkeeping-purchase-order-3way-06-matching-engine]
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

# Proposal: bookkeeping-purchase-order-3way-07-multi-po-consolidation

Member 7 of 11 in the `bookkeeping-purchase-order-3way` chain.
Predecessor: `bookkeeping-purchase-order-3way-06-matching-engine`. This
`kind: code` member extends the matching engine to handle a **single
invoice covering many POs/GRNs** (REQ-PO3W-007), with
crediteuren-administrateur disambiguation when candidate (PO line, GRN
line) tuples are ambiguous.

## Why (carried from the giant)

REQ-PO3W-007: a supplier's monthly maand-factuur can cover 12 different
POs placed throughout the month. Each invoice line must be matched to
candidate (PO line, GRN line) tuples by product_code + date proximity;
when ambiguous, the crediteuren-administrateur clarifies via the UI. Each
matched trio gets its own `ThreeWayMatch` record, processed independently
(some auto-approve, some go to exception).

## What this member does

- `ThreeWayMatchingEngine` consolidation methods:
  `disambiguateAmbiguousMatches()` (presents candidate tuples to the
  crediteuren-administrateur when multiple (PO, GRN) candidates match one
  invoice line), storing the disambiguation choice in the `ThreeWayMatch`
  record; one `ThreeWayMatch` per matched trio
- Unit tests for multi-PO consolidation matching; integration test for a
  multi-PO consolidated invoice

## Scope

### In Scope
- Multi-PO consolidation matching + disambiguation in `ThreeWayMatchingEngine`
- Disambiguation-choice persistence on `ThreeWayMatch`
- Consolidation unit + integration tests

### Out of Scope
- Single-PO matching + tolerance core — member 06 (predecessor)
- Exception resolution panel — member 08
- GL settlement — member 09

## Impact
- `lib/Service/ThreeWayMatchingEngine.php` (consolidation methods)
- `tests/` multi-PO consolidation
