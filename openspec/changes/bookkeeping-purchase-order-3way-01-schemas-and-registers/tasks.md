# Tasks — Member 01: Schemas & Registers (config)

## Register schemas

- [x] Declare `PurchaseOrder` schema (lifecycle draft → approved → sent → partial_received → fully_received → invoiced → closed → cancelled; approval_chain[], Peppol metadata) in `lib/Settings/shillinq_register.json`
- [x] Declare `PurchaseOrderLine` schema (po_id, line_number, product_or_service_code, quantity_ordered, unit_of_measure, unit_price, vat_rate, vat_amount, gl_account, tolerance_override)
- [x] Declare `GoodsReceiptNote` schema (lifecycle draft → received → quality_checked → accepted → rejected; po_ids[], photos[], temperature_log)
- [x] Declare `GoodsReceiptLine` schema (grn_id, po_line_id, quantity_received/accepted/rejected, rejection_reason, inspector, batch_reference)
- [x] Declare `SupplierInvoice` schema (lifecycle received → matching → matched → exception → approved → paid → rejected; UBL/OCR/Peppol metadata)
- [x] Declare `ThreeWayMatch` schema (invoice_id, matched_po_ids[], matched_grn_ids[], match_status enum, divergence_details, resolution fields)
- [x] Declare `ToleranceProfile` schema (scope, price/quantity/date tolerances, exception_routing, status)
- [x] Declare `VendorPerformance` schema (supplier_id, period, rate metrics, overall_score, score_trend, automated_review_eligible)
- [x] Wire `x-openregister-lifecycle` extensions on PurchaseOrder, GoodsReceiptNote, SupplierInvoice, ThreeWayMatch
- [x] Ensure cost_center + project_code propagate as dimensional fields on PO, line, GRN, invoice, and match records

## Manifest navigation

- [x] Add manifest entry: Purchase Orders (index + detail) in `src/manifest.json`
- [x] Add manifest entry: Goods Receipts (index + detail)
- [x] Add manifest entry: Supplier Invoices (index + detail)
- [x] Add manifest entry: 3-way Matches (index + detail)
- [x] Add manifest entry: Exceptions (filtered index of match_status ∈ exception_*)

## Seed data + integration test

- [x] Add seed fixtures (3-5 examples per entity) per design.md seed-data section
- [x] Write integration test: registers materialise with declared lifecycles + dimensional fields round-trip
- [x] Write integration test: manifest exposes the 5 navigation entries and seeded records are queryable
