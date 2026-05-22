# Proposal: bookkeeping-purchase-order-3way

`kind: feature` per ADR-001 — the 3-way match is a complex financial control workflow combining procurement, inventory, and accounting processes. This change adds:
- `PurchaseOrder` + `PurchaseOrderLine` entities with approval chaining and Peppol integration
- `GoodsReceiptNote` + `GoodsReceiptLine` entities for goods receipt verification
- `SupplierInvoice` entity for vendor invoice receipt and matching
- `ThreeWayMatch` entity with automated matching logic and exception handling
- `ToleranceProfile` entity for configurable matching tolerances
- `VendorPerformance` entity for supplier performance scoring
- Full audit trail and GL integration for invoice approval

## Summary

Introduce the **Purchase Order 3-way Match** capability for Shillinq as part of the T2/T3 accounts payable workflow (per `adr-001-bookkeeping-tier-roadmap.md`). This implements the industry-leading **3-way matching** control — the golden standard in AP fraud prevention: purchase order + goods receipt note + supplier invoice must align on header and line level across quantity, price, and VAT before payment authorization.

The change declares six core registers (`PurchaseOrder`, `PurchaseOrderLine`, `GoodsReceiptNote`, `GoodsReceiptLine`, `SupplierInvoice`, `ThreeWayMatch`), plus configuration registers for matching rules (`ToleranceProfile`, `VendorPerformance`). It includes:

- **Peppol BIS Ordering 3.0** integration for PO transmission to suppliers and incoming Peppol invoice receipt
- **Approval chaining** based on PO amount (teamleider + facility-manager for >€10k)
- **Automated 3-way matching** at header and line level with configurable tolerances (absolute + percentage)
- **Exception workflow** routing price/quantity discrepancies to crediteuren-administrateur
- **Vendor performance scoring** with auto-review eligibility (96%+ on-time delivery unlocks auto-approval)
- **GR/IR clearing** GL postings per IFRS goods-in-receipt principle
- **Audit trail** compliance per NV COS 230 documentation requirements

This change conforms to Dutch MKB bookkeeping best practices (SETU Inkoop, EN-16931, Peppol-BIS) and enables 99%+ automation of the invoice approval process for low-risk suppliers.

**Depends on:** `bookkeeping-accounts-payable-core` (GL posting), `inventory-stock-tracking` (GRN effect on stock), `purchaseq` (upstream purchase requisition).

## Motivation

The 3-way match is the golden standard in AP fraud prevention and cost control. ACFE research shows factuurfraude + duplicate-payment cost 5% of annual turnover at organizations without structured intake controls. Yet most Dutch MKB software only offers 2-way matching (PO vs invoice) or ignores GRN entirely. For productiebedrijven, großhandels, bouw, and zorginstellingen with high material intensity, this is a critical control gap.

The legacy AP/AR draft cluster from intelligence-db (`competitor_features` with `app_slug=shillinq`) explicitly calls out 3-way matching as a tier-one feature.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-purchase-order-3way`); declares 8 new registers with lifecycles, approval routing, GL integration, Peppol translation, exception workflow; adds manifest entries (Purchase Orders, Goods Receipts, Supplier Invoices, 3-way Matches, Exceptions).
- [ ] Project: openconnector — no source changes; consumes Peppol Access Point for PO transmission and incoming UBL invoice receipt + OCR extraction.
- [ ] Project: inventory — consumes `GoodsReceiptNote` to mutate stock levels and reserve expected receipts against open PO lines.
- [ ] Project: docudesk — no source changes; 3-way match evidence packages (PO PDF, GRN photos, supplier invoice, matching report) archived per BW2 art 2:10 (7-year retention).

## Scope

### In Scope

- One new capability spec (`bookkeeping-purchase-order-3way`)
- `PurchaseOrder` register: po_number (auto-generated CBS-conform), supplier_reference, requester, cost_center, project_code, payment_terms, delivery_address, expected_delivery_date, status lifecycle (draft → approved → sent → partial_received → fully_received → invoiced → closed), approval_chain[] based on amount thresholds, Peppol metadata (peppol_sent_at, peppol_message_id)
- `PurchaseOrderLine`: po_id, line_number, product_code, description, quantity_ordered, unit_of_measure (UN/ECE Rec 20 conform), unit_price, currency, vat_rate, expected_delivery_date, gl_account (kostenkrekening or voorraadrekening), tolerance_override
- `GoodsReceiptNote` register: grn_number, po_id (multi-PO for consolidated deliveries), received_at, received_by (magazijn-medewerker), delivery_note_reference, carrier, lot_numbers[], serial_numbers[], temperature_log (gekoelde leveringen), quality_check_passed, photos[]
- `GoodsReceiptLine`: grn_id, po_line_id, quantity_received, quantity_accepted, quantity_rejected, rejection_reason (schade, verkeerd_product, expired, niet_besteld), inspector, batch_reference
- `SupplierInvoice` register: invoice_number, supplier, invoice_date, due_date, total_excl_vat, total_vat, total_incl_vat, currency, payment_reference, ubl_source_uri, peppol_received_at, ocr_confidence_score, status (received → matching → matched → exception → approved → paid)
- `ThreeWayMatch` register: invoice_id, matched_po_ids[], matched_grn_ids[], match_status (auto_approved, within_tolerance, exception_price, exception_quantity, exception_missing_grn, exception_missing_po, fraud_alert), divergence_details (json), resolved_by, resolution_action, resolution_notes
- `ToleranceProfile` register (configuration): scope (global, supplier, category, gl_account), price_tolerance_amount (€10), price_tolerance_percentage (0.5%), quantity_tolerance_percentage (2%), date_tolerance_days (3), currency_rounding_tolerance, exception_routing (rol/persoon)
- `VendorPerformance` register: supplier_id, period, on_time_delivery_rate, quantity_accuracy_rate, price_accuracy_rate, invoice_accuracy_rate, dispute_count, average_resolution_days, overall_score (0-100), score_trend (improving, stable, declining), automated_review_eligible
- Automated matching at header + line level with divergence detail capture
- Exception workflow routing price/quantity deviations to crediteuren-administrateur with side-by-side comparison UI
- GL integration: GR/IR clearing posting at GRN time, final booking at invoice match approval
- Audit trail capture per REQ-010 (complete lifecycle history for external auditors)
- Vendor performance scoring triggering auto-approval elevation for 96%+ performers

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers, tests are deliberately not in this proposal
- **Advanced e-procurement features** — RFQ/bidding/vendor selection upstream of PO; purchase requisition comes from purchaseq app
- **Multi-currency conversion & FX** — T5
- **Reverse auction / dynamic pricing** — future enhancement
- **Consolidation of multi-line consolidated invoices** — this change supports multi-PO-to-one-invoice (REQ-007); further consolidation scenarios defer to T4

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-purchase-order-3way`** — declares the eight registers, approval chaining logic, automated 3-way matching with tolerance handling, exception routing, vendor-performance aggregation, GR/IR GL posting, and Peppol contract shape.

The spec follows the conduction-schema format (RFC 2119, `### REQ-XXX-NNN: <name>`, `#### Scenario:` with GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-PO3W-*` for traceability.

## New Dependencies

- **openconnector** (Peppol gateway) — for PO transmission (UBL Order → AS4) and incoming invoice receipt (UBL Invoice → OCR extraction)
- **inventory-stock-tracking** — for GRN stock mutation and expected-receipt reservation
- **purchaseq** — upstream purchase requisition approval feeds PO creation
- Existing **openregister abstractions** for lifecycle, aggregations, audit trail
- **@conduction/nextcloud-vue** for approval-chain UI and exception-workflow panels

## Impact

- `lib/Settings/shillinq_register.json` — adds 8 new schemas (`PurchaseOrder`, `PurchaseOrderLine`, `GoodsReceiptNote`, `GoodsReceiptLine`, `SupplierInvoice`, `ThreeWayMatch`, `ToleranceProfile`, `VendorPerformance`); declares lifecycle on PO, GRN, SupplierInvoice, ThreeWayMatch; approval_chain routing; GL posting triggers
- `src/manifest.json` — adds 5 navigation entries (Purchase Orders, Goods Receipts, Invoices, 3-way Matches, Exceptions) + their index/detail pages
- One PHP guard service `PurchaseOrderApprovalChain` (approval-chain routing per amount threshold) if OR's approval-routing extension is not yet stable
- No bespoke Vue components (exception workflow uses existing form builder + notification patterns)

## Cross-Project Dependencies

- **openconnector** — Peppol AS4 gateway for PO UBL Order transmission + incoming UBL Invoice receipt
- **inventory-stock-tracking** — consumes GoodsReceiptNote to mutate stock; reserves expected receipts on open PO
- **purchaseq** — approved requisitions feed into PO creation
- **bookkeeping-accounts-payable-core** (T2) — GL posting integration for invoice approval
- **T1 general-ledger** — JournalEntry materialisation for GR/IR clearing

## Risks

### Risk 1: Multi-PO consolidated invoices (REQ-007) complexity

**Severity**: Medium
**Mitigation**: The spec captures REQ-007 (one invoice → many POs/GRNs) as line-level matching. The implementation cycle tests the consolidation matching algorithm thoroughly; if complexity exceeds tolerance, the change ships single-PO-per-invoice in T2 and upgrades to consolidation in a follow-on T3 cycle.

### Risk 2: Vendor performance scoring data availability

**Severity**: Low
**Mitigation**: Vendor-performance aggregations (on_time_delivery_rate, quantity_accuracy_rate) depend on 12 months of clean GRN + SupplierInvoice history. During the implementing cycle, the spec allows 90-day bootstrap period before auto-review eligibility kicks in.

### Risk 3: Peppol/openconnector stability

**Severity**: Low-Medium
**Mitigation**: The spec assumes openconnector can emit PO as UBL Order + parse incoming UBL Invoice. If openconnector Peppol support lands after shillinq PO spec, the implementing cycle ships a fallback: PDF + email transmission with explicit logging. Users opt into Peppol when openconnector is ready.

### Risk 4: OCR confidence for invoice line matching

**Severity**: Low
**Mitigation**: REQ-007 & REQ-004 depend on reliable OCR extraction of invoice line items. The spec captures ocr_confidence_score; lines with confidence < 85% route to crediteuren-administrateur for manual confirmation, not auto-approval.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation, rollback follows the standard pattern: revert the implementing PR; registers are non-destructive — open POs + matched invoices remain queryable; the ability to *create new* POs is blocked by the app config until the change is re-enabled.

## Open Questions

1. **Peppol AS4 readiness in openconnector** — resolved in `opsx-ff` discovery; timeline agreed before spec → implementation handoff
2. **Vendor performance bootstrap period** — 90 days with default high tolerance, then switch to vendor-calculated tolerance?
3. **GR/IR posting policy** — post at GRN time (British practice) or at invoice match time (German practice)? Default British (GRN posting) with admin toggle; clarified in design phase
4. **Exception approval authority** — crediteuren-administrateur or controller or both? Default to both with escalation tier configurable per tolerance_profile.exception_routing
