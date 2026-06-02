# Design — Purchase Order 3-way Match

## Context

The 3-way match (Purchase Order + Goods Receipt Note + Supplier Invoice) is the golden standard in accounts payable fraud prevention and cost control. Dutch MKB law (Wta artikel 26 lid 1c) requires documented internal controls on procurement; auditors (NV COS 230) expect evidence of matched invoices before payment authorization. Yet most Dutch software only offers 2-way matching (PO ↔ Invoice) or ignores GRN entirely.

This change formalizes the complete 3-way cycle as declarative entities + matching rules + exception workflow + GL integration, enabling 99% automation for compliant suppliers while maintaining forensic audit capability.

This is a **spec-only change**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline.

## Goals

- Express the entire **3-way match surface as declarative metadata** — registers + lifecycle + approval chaining + GL triggers + aggregations (vendor performance)
- Support **Peppol BIS Ordering 3.0 + Billing 3.0** for cross-border e-procurement (UBL Order transmission, incoming UBL Invoice receipt)
- Implement **line-level matching** with header reconciliation, not just header-to-header
- Enable **configurable tolerance management** (absolute + percentage) per supplier/category/GL account, with exception routing to crediteuren-administrateur
- Capture **vendor performance metrics** (on-time delivery, quantity accuracy, invoice accuracy) and auto-approve qualified suppliers (96%+ score)
- Provide **complete audit trail** for external auditors (NV COS 230 compliance) with time-stamped approval chain + GRN photos + matching report
- Achieve **99% automation** of invoice approval for compliant suppliers while maintaining forensic control for exceptions

## Non-Goals

- **Implementation code** — services, Vue components, tests
- **Upstream purchase requisition approval** — that lives in `purchaseq` app; shillinq consumes approved requisitions
- **Advanced e-procurement** — RFQ, bidding, vendor selection; those are future-state enhancements
- **Multi-currency FX conversion** — T5
- **Reverse auction / dynamic pricing** — future capability

## Decisions

### D1 — PurchaseOrder is a sub-ledger that drives approval chain + stock reservation + GR/IR posting

Symmetric to the AP core pattern: `PurchaseOrder` is a controlled register with approval chain based on amount thresholds. Creating a PO reserves stock in inventory; issuing (after approval) generates a GR/IR clearing posting.

### D2 — Goods Receipt Note (GRN) is the source-of-truth for goods physically received

`GoodsReceiptNote` is the magazijn-medewerker's capture: what was actually received, in what condition, with photo evidence. GRN is independent of PO status (you can receive partial shipments across many POs). On GRN creation, inventory is credited (IFRS goods-in-receipt).

### D3 — 3-way match happens at LINE level, not header level

`ThreeWayMatch` matches individual PO lines to GRN lines to SupplierInvoice line items on (product_code, quantity, price, vat). This allows partial matches (one invoice covering lines from multiple POs) and partial receipts (GRN for 180 of 200 ordered).

### D4 — Automated matching with configurable tolerances drives 99% approval rate

`ToleranceProfile` (scope: global / supplier / category / GL account) defines:
- Price tolerance: €10 absolute OR 0.5% relative (whichever is MORE permissive)
- Quantity tolerance: ±2%
- Date tolerance: ±3 days early/late
- Currency rounding tolerance: ±€0.01

Lines within tolerance auto-approve. Lines outside tolerance route to crediteuren-administrateur with side-by-side comparison.

### D5 — Vendor performance scoring unlocks automated review for 96%+ performers

`VendorPerformance` tracks (monthly rolling window):
- on_time_delivery_rate: (GRNs delivered by expected date) / (GRNs received)
- quantity_accuracy_rate: (received = ordered) / (lines received)
- price_accuracy_rate: (invoiced price = PO price ±tolerance) / (lines invoiced)
- invoice_accuracy_rate: (invoices matched on first try) / (invoices received)

Score = weighted avg (40% on-time, 30% quantity, 20% price, 10% invoice accuracy). Score 96%+ → auto-approve matches, relax tolerances, flag for account manager relationship review.

### D6 — GR/IR clearing per IFRS goods-in-receipt

At GRN creation time, a balanced posting is materialized:
- Debit: Inventory (or expense if direct-cost PO) [GL account per PO line]
- Credit: GR/IR Clearing [GL account per tolerance_profile]

At invoice match time, a second posting settles the clearing:
- Debit: GR/IR Clearing
- Credit: Accounts Payable + VAT [per SupplierInvoice + ToleranceProfile]

If no GRN → no invoice approval (enforces goods-receipt discipline). If GRN ≠ Invoice → exception routing or partial clearing.

### D7 — Exception workflow with three resolutions

When match_status ∈ {exception_price, exception_quantity, exception_missing_grn, exception_missing_po, fraud_alert}:
1. **Accept with motivation** → crediteuren-administrateur confirms and provides reason (price increase authorized, quantity short-ship accepted, etc.)
2. **Dispute with supplier** → auto-generate UBL CreditNote request via Peppol, escalate to inkoper for follow-up
3. **Reject and block payment** → invoice marked rejected; UBL CreditNote request sent; PO marked back to received state; stock restored if needed

All paths audit-trailed with timestamp + decision-maker + reason.

### D8 — Peppol BIS Ordering 3.0 + Billing 3.0 for cross-border e-procurement

`PurchaseOrder` can emit as UBL Order document (when issued) → openconnector Peppol Access Point → supplier's inbox (Peppol particpant network). Incoming UBL Invoice from supplier → openconnector OCR extraction → `SupplierInvoice` record creation.

Fallback: if supplier not Peppol-registered, PO emits as PDF + email (logged as fallback_reason in `PurchaseOrder.peppol_sent_at`).

### D9 — Multi-PO consolidated invoice (REQ-007) via line-level matching

One supplier invoice can invoice lines from 10 different POs. Matching algorithm matches each invoice line to candidate (PO line, GRN line) tuples; if multiple matches possible, crediteuren-administrateur disambiguates. Each matched trio gets its own ThreeWayMatch record.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| PO approval chain | OR `x-openregister-lifecycle` + custom routing | Lifecycle on `PurchaseOrder` (draft → approved → sent → partial_received → fully_received → invoiced → closed); approval_chain[] based on amount thresholds; routes to roles per `ApprovalRoute` |
| GRN receipt workflow | OR `x-openregister-lifecycle` | Lifecycle on `GoodsReceiptNote` (draft → received → quality_checked → accepted); quality check can auto-pass or route to inspector |
| Matching rules engine | OR `x-openregister-aggregations` | `ThreeWayMatch` uses aggregation to sum line-level divergences; tolerance evaluation is declarative precondition |
| Vendor performance metrics | OR `x-openregister-aggregations` | Monthly rolling-window aggregation: (on_time_count / received_count), (matching_on_first_try_count / invoices), etc. |
| GR/IR GL postings | T1 `JournalEntry` materialisation pattern | Same trigger-based materialization as AP core |
| Peppol/UBL transmission | openconnector Peppol Access Point | PO → UBL Order, incoming UBL Invoice → SupplierInvoice record |
| Invoice OCR extraction | openconnector OCR module | Incoming UBL Invoice confidence_score + line-item extraction |
| Stock mutation on GRN | inventory-stock-tracking | GRN creation increments received quantity; expected-receipts decremented against open PO |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all lifecycle transitions + approval chain decisions + exception resolutions |
| Exception workflow UI | manifest form-builder pattern | crediteuren-administrateur panel showing exceptions with side-by-side PO/GRN/Invoice comparison + accept/dispute/reject actions |
| Cost-center tracking | T1 chart of accounts | PO line gl_account ties to cost_center; GR/IR posting preserves cost center |
| Manifest navigation | T1 manifest pattern | 5 entries (POs, GRNs, Invoices, Matches, Exceptions) + index/detail pages |

## Seed Data (3-5 examples per entity)

### PurchaseOrder examples

```
PO-2026-0001
  supplier: NieuweLeverancierBV (Peppol participant, vendor_score 45%, new supplier)
  requester: M. Jansen (Inkoper)
  cost_center: FAC-2026 (Facility Management)
  delivery_address: Warehouse A, Rotterdam
  expected_delivery_date: 2026-06-15
  po_number_auto_generated: TRUE
  status: approved (awaiting Peppol send)
  approval_chain: [Teamleider, Facility Manager]
  approval_timestamps: [{approver: "W. van der Berg", role: "Teamleider", at: "2026-05-20 10:30", comment: ""},
                        {approver: "B. de Vries", role: "Facility Manager", at: "2026-05-20 14:15", comment: "Budget confirmed"}]
  peppol_sent_at: NULL (awaiting send)
  currency: EUR
  total: 18500.00 (incl VAT 21%)
  payment_terms: net 30

PO-2026-0002
  supplier: ErenteSchreuders (Peppol participant, vendor_score 98%, established supplier)
  requester: P. Koolstra (Inkoper)
  cost_center: MAG-2026 (Magazijn)
  delivery_address: Warehouse B, Almere
  expected_delivery_date: 2026-06-10
  status: sent (Peppol transmitted)
  approval_chain: [Teamleider]
  approval_timestamps: [{approver: "W. van der Berg", role: "Teamleider", at: "2026-05-19 09:00"}]
  peppol_sent_at: 2026-05-19 09:15
  peppol_message_id: urn:uuid:550e8400-e29b-41d4-a716-446655440000
  currency: EUR
  total: 4250.00 (incl VAT 21%)
  payment_terms: net 30

PO-2026-0003
  supplier: ErenteSchreuders
  requester: P. Koolstra
  cost_center: MAG-2026
  delivery_address: Warehouse B, Almere
  expected_delivery_date: 2026-06-20
  status: partial_received (180 of 200 items received)
  approval_chain: [Teamleider]
  approval_timestamps: [{approver: "W. van der Berg", role: "Teamleider", at: "2026-05-15 11:00"}]
  peppol_sent_at: 2026-05-15 11:45
  peppol_message_id: urn:uuid:550e8400-e29b-41d4-a716-446655440001
  currency: EUR
  total: 18500.00 (incl VAT 21%)
  payment_terms: net 30
```

### GoodsReceiptNote examples

```
GRN-2026-0012
  po_ids: [PO-2026-0003]
  received_at: 2026-05-22 10:30
  received_by: J. Vermeulen (magazijn-medewerker)
  delivery_note_reference: DEEL-20260522-1
  carrier: DHL Express
  quality_check_passed: TRUE
  photos: [photo_pallet_1.jpg, photo_pallet_2.jpg]
  status: accepted
  grn_lines: [
    {po_line_id: PO-2026-0003-L1, quantity_ordered: 200, quantity_received: 180, quantity_accepted: 180, quantity_rejected: 20, rejection_reason: "Delivery short-shipped, 20 chairs expected next week", inspector: "J. Vermeulen", batch_reference: "BATCH-ERS-20260520"}
  ]
  
GRN-2026-0011
  po_ids: [PO-2026-0002]
  received_at: 2026-05-21 14:00
  received_by: J. Vermeulen
  delivery_note_reference: DEEL-20260521-3
  carrier: GLS
  quality_check_passed: TRUE
  photos: [photo_unopened_box.jpg]
  status: accepted
  grn_lines: [
    {po_line_id: PO-2026-0002-L1, quantity_ordered: 100, quantity_received: 100, quantity_accepted: 100, quantity_rejected: 0, batch_reference: "BATCH-ERS-20260520"}
  ]
```

### SupplierInvoice examples

```
INV-ERS-2026-00445
  supplier: ErenteSchreuders
  invoice_number: 2026-00445
  invoice_date: 2026-05-20
  due_date: 2026-06-19
  total_excl_vat: 3521.49
  total_vat: 739.51
  total_incl_vat: 4261.00 (PO: 4250.00, delta +11.00)
  currency: EUR
  payment_reference: INV-2026-00445
  ubl_source_uri: urn:uuid:550e8400-e29b-41d4-a716-446655440100
  peppol_received_at: 2026-05-21 09:15
  ocr_confidence_score: 0.95
  status: matched
  
INV-NL-2026-18547
  supplier: NieuweLeverancierBV
  invoice_number: 2026-18547
  invoice_date: 2026-05-21
  due_date: 2026-06-20
  total_excl_vat: 15288.43
  total_vat: 3209.57
  total_incl_vat: 18498.00 (PO: 18500.00, delta -2.00)
  currency: EUR
  payment_reference: INV-2026-18547
  ubl_source_uri: urn:uuid:550e8400-e29b-41d4-a716-446655440101
  peppol_received_at: 2026-05-21 10:45
  ocr_confidence_score: 0.92
  status: matched
```

### ThreeWayMatch examples

```
MATCH-2026-001
  invoice_id: INV-ERS-2026-00445
  matched_po_ids: [PO-2026-0002]
  matched_grn_ids: [GRN-2026-0011]
  match_status: auto_approved (price delta +€11 = 0.26% < 0.5% tolerance)
  divergence_details: {price_delta: 11.00, price_pct: 0.26, quantity_delta: 0, vat_delta: 0}
  resolved_by: NULL (auto-approved)
  resolution_action: auto_approve
  resolution_notes: NULL
  
MATCH-2026-002
  invoice_id: INV-NL-2026-18547
  matched_po_ids: [PO-2026-0003]
  matched_grn_ids: [GRN-2026-0012] (partial, 180 received vs 200 ordered)
  match_status: within_tolerance (price delta -€2 = -0.01%, quantity delta -20 items = -10% but GRN notes "delivery short-shipped, rest next week")
  divergence_details: {price_delta: -2.00, price_pct: -0.01, quantity_delta: -20, quantity_pct: -10.0, grn_notes: "short-shipped, balance expected"}
  resolved_by: A. de Kock (crediteuren-administrateur)
  resolution_action: accepted_with_motivation
  resolution_notes: "Supplier confirmed balance 20 chairs shipping 2026-05-30 per email ref DEV-20260521-456. Short shipment acceptable per cost center manager approval."
  created_at: 2026-05-22 14:30
  resolved_at: 2026-05-22 15:00
```

### ToleranceProfile examples

```
TP-GLOBAL-DEFAULT
  scope: global
  price_tolerance_amount: 10.00
  price_tolerance_percentage: 0.5
  quantity_tolerance_percentage: 2.0
  date_tolerance_days: 3
  currency_rounding_tolerance: 0.01
  exception_routing: crediteuren-administrateur

TP-SUPPLIER-NieuweLeverancierBV
  scope: supplier=NieuweLeverancierBV
  price_tolerance_amount: 0.00 (zero tolerance)
  price_tolerance_percentage: 0.0
  quantity_tolerance_percentage: 0.0
  date_tolerance_days: 0
  currency_rounding_tolerance: 0.01
  exception_routing: crediteuren-administrateur + controller
  notes: "New supplier, zero-tolerance regime until vendor_score > 80"

TP-CATEGORY-ElectricalEquipment
  scope: category=electrical
  price_tolerance_amount: 25.00
  price_tolerance_percentage: 1.0
  quantity_tolerance_percentage: 5.0
  date_tolerance_days: 7
  currency_rounding_tolerance: 0.01
  exception_routing: crediteuren-administrateur
```

### VendorPerformance examples

```
VENDOR-ERS-2026-05
  supplier_id: ErenteSchreuders
  period: 2026-05
  on_time_delivery_rate: 98.0 (49 of 50 GRNs on time)
  quantity_accuracy_rate: 99.0 (198 of 200 line items exact)
  price_accuracy_rate: 97.0 (97 of 100 lines within tolerance)
  invoice_accuracy_rate: 100.0 (all invoices matched first try)
  dispute_count: 1 (one dispute resolved favorably in Apr)
  average_resolution_days: 2.0
  overall_score: 98.5 (weighted avg)
  score_trend: stable
  automated_review_eligible: TRUE

VENDOR-NIEUW-2026-05
  supplier_id: NieuweLeverancierBV
  period: 2026-05
  on_time_delivery_rate: 85.0 (17 of 20 GRNs on time)
  quantity_accuracy_rate: 90.0 (18 of 20 line items exact)
  price_accuracy_rate: 80.0 (16 of 20 lines within tolerance)
  invoice_accuracy_rate: 90.0 (9 of 10 invoices matched first try)
  dispute_count: 3
  average_resolution_days: 5.5
  overall_score: 86.0 (weighted avg)
  score_trend: improving
  automated_review_eligible: FALSE (needs 96+ score)
```

## Implementation Sequence (guidance for opsx-apply)

1. **Phase 1**: Create the 8 register schemas in `shillinq_register.json` + base lifecycle definitions
2. **Phase 2**: Implement approval-chain routing (PurchaseOrder) + GRN receipt workflow
3. **Phase 3**: Implement 3-way matching algorithm (line-level, tolerance evaluation, exception routing)
4. **Phase 4**: GL posting materialization (GR/IR clearing)
5. **Phase 5**: Peppol integration (PO emission, incoming invoice receipt)
6. **Phase 6**: Vendor performance aggregation + auto-review eligibility
7. **Phase 7**: Manifest entries + exception workflow UI
8. **Phase 8**: Testing + audit trail validation per NV COS 230
