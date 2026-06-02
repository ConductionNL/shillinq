---
kind: config
depends_on: []
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

# Proposal: bookkeeping-purchase-order-3way-01-schemas-and-registers

Member 1 of 11 in the `bookkeeping-purchase-order-3way` chain (ADR-032
decomposition of the original oversized `bookkeeping-purchase-order-3way`
change). No predecessor — this is the chain's foundation. This member
**declares** the eight OpenRegister schemas, their lifecycles, and the
manifest navigation surface that every downstream code member consumes;
no consumer code is written here.

This is a `kind: config` member: it only touches declarative JSON
(`lib/Settings/shillinq_register.json`, `src/manifest.json`) plus a seed
fixture and a single integration test verifying the materialised
registers. The "declare → consume → delete imperative" shape of ADR-032
puts the schema declaration first so the new registers are
read-only-available to every downstream member before any controller,
service, or guard is written.

## Why (carried from the giant)

The 3-way match (Purchase Order + Goods Receipt Note + Supplier Invoice)
is the golden standard in accounts payable fraud prevention. ACFE
research shows factuurfraude + duplicate-payment cost ~5% of annual
turnover at organisations without structured intake controls, yet most
Dutch MKB software only offers 2-way matching or ignores GRN entirely.
The intelligence-db AP/AR draft cluster (`competitor_features`,
`app_slug=shillinq`) calls out 3-way matching as a tier-one feature.

The whole feature rests on eight registers. Declaring them — with
lifecycles and dimensional fields (cost_center, project_code) — is the
expand step of an expand-then-contract rollout: existing consumers ignore
the new registers, and the downstream chain members opt-in incrementally.

## What this member declares

- `PurchaseOrder` register: po_number (auto-generated, CBS-conform),
  supplier_reference, requester, cost_center, project_code, currency,
  payment_terms, delivery_address, expected_delivery_date, status
  lifecycle (draft → approved → sent → partial_received →
  fully_received → invoiced → closed → cancelled), approval_chain[],
  Peppol metadata (peppol_sent_at, peppol_message_id, peppol_fallback_reason)
- `PurchaseOrderLine`: po_id, line_number, product_or_service_code,
  description, quantity_ordered, unit_of_measure (UN/ECE Rec 20),
  unit_price, currency, line_total, vat_rate, vat_amount,
  expected_delivery_date, gl_account, tolerance_override
- `GoodsReceiptNote`: grn_number, po_ids[], received_at, received_by,
  delivery_note_reference, carrier, lot_numbers[], serial_numbers[],
  temperature_log, quality_check_passed, photos[], status lifecycle
  (draft → received → quality_checked → accepted → rejected)
- `GoodsReceiptLine`: grn_id, po_line_id, quantity_received,
  quantity_accepted, quantity_rejected, rejection_reason, inspector,
  batch_reference
- `SupplierInvoice`: invoice_number, supplier, invoice_date, due_date,
  total_excl_vat, total_vat, total_incl_vat, currency, payment_reference,
  ubl_source_uri, peppol_received_at, ocr_confidence_score, status
  lifecycle (received → matching → matched → exception → approved →
  paid → rejected)
- `ThreeWayMatch`: invoice_id, matched_po_ids[], matched_grn_ids[],
  match_status, divergence_details, resolved_by, resolution_action,
  resolution_notes, created_at, resolved_at
- `ToleranceProfile` (config): scope, scope_reference, price_tolerance_amount,
  price_tolerance_percentage, quantity_tolerance_percentage,
  date_tolerance_days, currency_rounding_tolerance, exception_routing, status
- `VendorPerformance`: supplier_id, period, on_time_delivery_rate,
  quantity_accuracy_rate, price_accuracy_rate, invoice_accuracy_rate,
  dispute_count, average_resolution_days, overall_score, score_trend,
  automated_review_eligible
- `src/manifest.json` navigation: Purchase Orders, Goods Receipts,
  Supplier Invoices, 3-way Matches, Exceptions (each index + detail)
- Seed fixtures (3-5 examples per entity) and one integration test
  asserting the materialised registers + lifecycle states are correct.

## Scope

### In Scope
- Eight register schemas + lifecycle metadata in `lib/Settings/shillinq_register.json`
- Five manifest navigation entries (+ index/detail pages) in `src/manifest.json`
- Seed data fixtures and an integration test verifying materialised values

### Out of Scope
- Any PHP service / controller / guard — deferred to members 02-11
- Any Vue component — deferred to members 02-11
- Matching, GL posting, Peppol, vendor scoring logic — downstream members

## Impact
- `lib/Settings/shillinq_register.json` — adds 8 schemas + lifecycles
- `src/manifest.json` — adds 5 navigation entries + index/detail pages
- Integration test fixture for the new registers
