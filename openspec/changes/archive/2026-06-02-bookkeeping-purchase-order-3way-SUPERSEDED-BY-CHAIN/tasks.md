# Tasks — Purchase Order 3-way Match Implementation

## Architecture & Setup

- [ ] Create OpenRegister schema definitions for 8 new entities in `lib/Settings/shillinq_register.json`:
  - [ ] PurchaseOrder (with lifecycle: draft → approved → sent → partial_received → fully_received → invoiced → closed)
  - [ ] PurchaseOrderLine
  - [ ] GoodsReceiptNote (with lifecycle: draft → received → quality_checked → accepted → rejected)
  - [ ] GoodsReceiptLine
  - [ ] SupplierInvoice (with lifecycle: received → matching → matched → exception → approved → paid → rejected)
  - [ ] ThreeWayMatch
  - [ ] ToleranceProfile
  - [ ] VendorPerformance
- [ ] Create migration adding the 8 new tables to shillinq database
- [ ] Wire up OpenRegister lifecycle extensions (`x-openregister-lifecycle`) on PurchaseOrder, GoodsReceiptNote, SupplierInvoice, ThreeWayMatch

## Purchase Order Core (REQ-PO3W-001, REQ-PO3W-002)

- [ ] Implement `PurchaseOrderService` PHP class with methods:
  - [ ] `createPurchaseOrder()` — validates requester, checks cost_center budget, generates po_number per CBS rules
  - [ ] `determinApprovalChain()` — evaluates PO amount, returns ordered list of approver roles
  - [ ] `blockSendUntilApproved()` — prevents PO status transitions to "sent" if approval_chain incomplete
  - [ ] `sendToPeppol()` — transforms PO → UBL Order, submits to openconnector Peppol Access Point, records peppol_message_id + peppol_sent_at
  - [ ] `sendToPDFEmail()` — fallback if supplier not Peppol-registered; logs peppol_fallback_reason

- [ ] Create Vue component `PurchaseOrderForm.vue`:
  - [ ] Line-item entry (product_code, quantity, unit_price, vat_rate, gl_account)
  - [ ] Cost center + project code picker
  - [ ] Approval chain display (status of each required approver)
  - [ ] Send to Peppol / PDF+email button

- [ ] Create Vue component `PurchaseOrderDetail.vue`:
  - [ ] Display PO header + line items + approval chain + Peppol metadata
  - [ ] Show approval history with timestamps + approver comments
  - [ ] Link to related GoodsReceiptNotes + ThreeWayMatches

- [ ] Write unit tests for approval chain routing logic (amount thresholds: €5k single-approver, €10k double-approver, €50k + procurement manager)
- [ ] Write integration test: PO creation → Peppol transmission → verify peppol_message_id recorded

## Goods Receipt Note Core (REQ-PO3W-003)

- [ ] Implement `GoodsReceiptNoteService` PHP class with methods:
  - [ ] `createGRN()` — accepts po_ids (array), received_at, received_by, carrier, delivery_note_ref
  - [ ] `addGRNLine()` — po_line_id, quantity_received, quantity_accepted, quantity_rejected, rejection_reason, batch_ref
  - [ ] `qualityCheckPass()` — transitions GRN to "quality_checked" status
  - [ ] `acceptGRN()` — finalizes GRN, triggers GR/IR posting (see REQ-PO3W-009)
  - [ ] `uploadPhotos()` — stores delivery condition photos via docudesk

- [ ] Integrate with `inventory-stock-tracking`:
  - [ ] On GRN accept: credit inventory for quantity_accepted at gl_account from PO line
  - [ ] On GRN reject: do NOT decrement inventory for quantity_rejected

- [ ] Create Vue component `GoodsReceiptNoteForm.vue` (mobile-optimized):
  - [ ] PO selection (single or multi-PO consolidation)
  - [ ] Per-line quantity entry (received, accepted, rejected) with real-time visual feedback
  - [ ] Rejection reason picker (schade, verkeerd_product, expired, niet_besteld)
  - [ ] Carrier + delivery_note_reference entry
  - [ ] Photo upload + preview

- [ ] Create Vue component `GoodsReceiptNoteDetail.vue`:
  - [ ] Header + line-item table with received/accepted/rejected columns
  - [ ] Photos gallery
  - [ ] Link to related ThreeWayMatches

- [ ] Write unit tests for GRN line allocation (partial receipt, multi-PO matching)
- [ ] Write integration test: GRN creation → stock mutation → GR/IR GL posting

## Supplier Invoice Ingestion (REQ-PO3W-004, REQ-PO3W-007)

- [ ] Integrate with `openconnector`:
  - [ ] Subscribe to Peppol-received UBL Invoice events
  - [ ] Extract UBL fields → SupplierInvoice record (invoice_number, dates, amounts, currency, line items)
  - [ ] Call openconnector OCR service if PDF-attached; store ocr_confidence_score

- [ ] Implement `SupplierInvoiceService` PHP class with methods:
  - [ ] `ingestUBLInvoice()` — parses UBL Invoice XML, creates SupplierInvoice + line-item records
  - [ ] `ingestPDFInvoice()` — receives PDF, calls OCR extraction, creates SupplierInvoice
  - [ ] `setStatus()` — transitions invoice through lifecycle states

- [ ] Create Vue component `SupplierInvoiceDetail.vue`:
  - [ ] Header info (supplier, invoice_number, dates, amounts)
  - [ ] Line-item table
  - [ ] OCR confidence indicator
  - [ ] Related ThreeWayMatch status

- [ ] Write unit tests: UBL Invoice → SupplierInvoice mapping
- [ ] Write integration test: Peppol-received UBL Invoice → SupplierInvoice creation

## 3-way Matching Engine (REQ-PO3W-004, REQ-PO3W-005, REQ-PO3W-006)

- [ ] Implement `ThreeWayMatchingEngine` PHP class with core algorithm:
  - [ ] `evaluateMatch(invoiceId)` — main entry point
  - [ ] `matchLineItems()` — for each invoice line, find candidate (PO line, GRN line) tuples
  - [ ] `calculateDivergence()` — compute price_delta, quantity_delta, vat_delta, date_delta
  - [ ] `evaluateTolerance()` — check divergence against applicable ToleranceProfile
  - [ ] `routeToException()` — create ThreeWayMatch with exception status + notification to crediteuren-administrateur

- [ ] Implement `ToleranceProfileService` PHP class:
  - [ ] `getApplicableProfile()` — returns most-specific profile (supplier > category > gl_account > global)
  - [ ] `evaluateWithinTolerance()` — checks price_delta against (absolute OR percentage) tolerance
  - [ ] `evaluateQuantityVariance()` — checks qty against percentage tolerance
  - [ ] `evaluateDateVariance()` — checks delivery date against days tolerance

- [ ] Implement multi-PO consolidation matching:
  - [ ] `disambiguateAmbiguousMatches()` — presents UI to crediteuren-administrateur if multiple (PO, GRN) candidates per invoice line
  - [ ] Store disambiguation choice in ThreeWayMatch record

- [ ] Create Vue component `ThreeWayMatchExceptionPanel.vue`:
  - [ ] Side-by-side comparison: PO line ↔ GRN line ↔ Invoice line (quantities, prices, VAT, dates)
  - [ ] Display divergence_details JSON in human-readable format
  - [ ] Provide three action buttons: "Accept with Motivation", "File Dispute", "Reject"
  - [ ] Text input for motivation/notes
  - [ ] Post resolution, update ThreeWayMatch with resolution_action + resolved_by + notes

- [ ] Create Vue component `ThreeWayMatchIndex.vue`:
  - [ ] Filterable table: match_status (auto_approved, within_tolerance, exception_price, exception_quantity, etc.)
  - [ ] Invoice number, supplier, amount, match date columns
  - [ ] Quick-action buttons (view detail, resolve exception)

- [ ] Write unit tests:
  - [ ] Line-level matching algorithm (product code matching, date proximity)
  - [ ] Tolerance evaluation (absolute vs % logic, "more permissive" selection)
  - [ ] Multi-PO consolidation matching
  - [ ] Scope resolution for ToleranceProfile (supplier overrides global, etc.)

- [ ] Write integration tests:
  - [ ] Auto-approve case (tolerance within limits)
  - [ ] Exception routing case (variance exceeds tolerance)
  - [ ] Multi-PO consolidated invoice

## Vendor Performance Scoring (REQ-PO3W-008)

- [ ] Implement `VendorPerformanceAggregation` PHP class:
  - [ ] `calculateMonthlyScore(supplierId, period)` — runs at month-end
  - [ ] `on_time_delivery_rate` — (GRNs where received_at ≤ expected_delivery_date) / (total GRNs)
  - [ ] `quantity_accuracy_rate` — (GRN lines where qty_received = qty_ordered) / (total lines)
  - [ ] `price_accuracy_rate` — (invoice lines within tolerance) / (total lines)
  - [ ] `invoice_accuracy_rate` — (invoices matched on first try, no exceptions) / (total invoices)
  - [ ] `overall_score` — weighted average: 40% on_time + 30% qty + 20% price + 10% invoice
  - [ ] `score_trend` — compare with prior month (improving, stable, declining)
  - [ ] `setAutoReviewEligible()` — set TRUE if overall_score ≥ 96
  - [ ] `autoRelaxToleranceProfile()` — if eligible, increase supplier tolerance (or flag for manual approval)

- [ ] Create scheduled job (cron) to run VendorPerformanceAggregation monthly
- [ ] Create Vue component `VendorPerformanceDetail.vue`:
  - [ ] Display monthly scores (on_time, qty, price, invoice accuracy)
  - [ ] Overall score + score trend chart
  - [ ] Auto-review eligibility badge
  - [ ] Link to related POs + invoices for this supplier

- [ ] Write unit tests:
  - [ ] Score calculation logic (weighted average)
  - [ ] Eligibility evaluation (96%+ threshold)
  - [ ] Score trend detection (improving/stable/declining)

- [ ] Write integration test: Run monthly aggregation, verify scores recorded and auto-review flag set

## GL Integration (REQ-PO3W-009)

- [ ] Implement `GRIRClearingService` PHP class:
  - [ ] `createGRIRPosting()` — on GRN accept, materialize: DR [gl_account from PO line] / CR [GR/IR clearing account from profile]
  - [ ] `settleGRIRPosting()` — on invoice approval, materialize: DR [GR/IR clearing] / CR [AP liability + VAT payable]
  - [ ] Both postings preserve cost_center + project_code from PO line

- [ ] Create GL account configuration:
  - [ ] Define GR/IR clearing account code (e.g., 2910) at administration level
  - [ ] Make configurable per ToleranceProfile (optional override)

- [ ] Write unit tests:
  - [ ] GR/IR posting creation (balanced entries, proper GL codes)
  - [ ] GR/IR settlement (clearing to AP + VAT)
  - [ ] Cost center preservation

- [ ] Write integration test:
  - [ ] GRN accept → verify GR/IR clearing posting in GL
  - [ ] Invoice approval → verify settlement posting
  - [ ] GR/IR saldo reconciliation (should sum to zero at period-end)

## Exception Resolution Workflow (REQ-PO3W-005)

- [ ] Implement `ExceptionResolutionService` PHP class:
  - [ ] `acceptWithMotivation()` — crediteuren-administrateur confirms exception, provides notes; updates ThreeWayMatch.resolution_action + resolved_by + notes
  - [ ] `fileDispute()` — auto-generate UBL CreditNote request via openconnector, escalate to Inkoper notification queue
  - [ ] `rejectAndBlockPayment()` — mark invoice as rejected, reverse any GR/IR postings if partial GRN, restore stock if needed

- [ ] Integrate with notification service:
  - [ ] Send exception alert to crediteuren-administrateur on match_status = exception_*
  - [ ] Include side-by-side comparison in notification body
  - [ ] Deep-link to ThreeWayMatchExceptionPanel in notification

- [ ] Write unit tests:
  - [ ] Accept with motivation (audit-trail capture)
  - [ ] Dispute filing (UBL CreditNote generation)
  - [ ] Rejection (GL reversal logic)

- [ ] Write integration tests:
  - [ ] Full exception → resolution → GL posting flow

## Audit Trail & Compliance (REQ-PO3W-010)

- [ ] Leverage existing `bookkeeping-audit-trail` capability:
  - [ ] Ensure all lifecycle transitions on PO, GRN, SupplierInvoice, ThreeWayMatch are audit-logged
  - [ ] Record approver identities + timestamps on approval chain
  - [ ] Record exception resolution details (resolver + action + notes + timestamp)

- [ ] Implement `AuditExportService` PHP class:
  - [ ] `generateAuditPackage(invoiceId)` — exports complete lifecycle history
  - [ ] Generates PDF summary + JSON ledger + file attachments (photos, signed approval records)
  - [ ] Creates ZIP archive for external auditor review

- [ ] Create Vue component `AuditTrailDetail.vue`:
  - [ ] Timeline view: PO creation → approval chain → GRN → invoice receipt → match evaluation → exception resolution → GL postings → payment
  - [ ] Each event shows timestamp + actor + details
  - [ ] Export as PDF/ZIP button

- [ ] Write unit tests:
  - [ ] Audit-trail capture on all lifecycle transitions
  - [ ] Audit package generation + ZIP creation
  - [ ] Timestamp + actor recording

- [ ] Write integration test:
  - [ ] Full PO → approval → GRN → invoice → match → exception → approval → GL → payment lifecycle with audit-trail verification

## Manifest & Navigation (All)

- [ ] Create manifest entries in `src/manifest.json`:
  - [ ] Purchase Orders (index + detail view)
  - [ ] Goods Receipts (index + detail view)
  - [ ] Supplier Invoices (index + detail view)
  - [ ] 3-way Matches (index + detail view)
  - [ ] Exceptions (filtered index of match_status ∈ {exception_price, exception_quantity, exception_missing_grn, exception_missing_po, fraud_alert})

- [ ] Ensure navigation between related entities (PO → GRNs → Matches, Invoice → Matches, etc.)

## Testing & QA

- [ ] Unit test coverage:
  - [ ] Approval chain routing (all amount thresholds)
  - [ ] Tolerance evaluation (absolute, %, "more permissive" logic)
  - [ ] Vendor performance scoring (all metrics)
  - [ ] GL posting (balance check, cost center preservation)
  - [ ] Audit trail (all lifecycle events captured)

- [ ] Integration test coverage:
  - [ ] PO creation → approval → Peppol send (+ fallback to PDF)
  - [ ] GRN creation → stock mutation → GL posting
  - [ ] UBL invoice ingestion (Peppol + PDF+OCR)
  - [ ] 3-way match auto-approve case
  - [ ] 3-way match exception case → resolution → GL settlement
  - [ ] Multi-PO consolidated invoice matching
  - [ ] Vendor performance monthly aggregation
  - [ ] Audit package export

- [ ] End-to-end scenario tests (manual + automated):
  - [ ] Complete PO → GRN → Invoice → Match → Payment cycle (high-volume supplier, auto-approve)
  - [ ] Exception pricing scenario (crediteuren-administrateur accepts with motivation)
  - [ ] Short-shipment scenario (partial GRN, exception quantity, dispute filed)
  - [ ] Fraud-alert scenario (impossible quantity variance, manual review required)

- [ ] Compliance validation:
  - [ ] Audit trail captures all NV COS 230 required elements
  - [ ] GL postings conform to IFRS goods-in-receipt
  - [ ] Peppol transmission logs Peppol BIS Ordering 3.0 conformance

- [ ] Performance testing:
  - [ ] 3-way matching engine on 100-line consolidated invoice
  - [ ] Vendor performance aggregation on 1M GRN + invoice records
  - [ ] Audit export on large invoice (100+ related documents)

## Documentation & Training

- [ ] Update user guides:
  - [ ] Purchasing Agent: PO creation + approval routing + Peppol transmission
  - [ ] Warehouse Staff: GRN entry via mobile app
  - [ ] Crediteuren Administrator: Exception workflow + vendor performance review
  - [ ] Controller: Tolerance profile configuration + vendor onboarding

- [ ] Create training videos (Dutch + English):
  - [ ] PO creation & approval chain
  - [ ] GRN entry & photo upload
  - [ ] Exception resolution workflow
  - [ ] Audit trail review for external auditors

- [ ] Document configuration:
  - [ ] Approval chain thresholds (make configurable)
  - [ ] Default tolerance profiles
  - [ ] GR/IR clearing account setup
  - [ ] Vendor performance calculation parameters

## Final Sign-Off

- [ ] Spec review + team sign-off (architect, backend lead, frontend lead)
- [ ] QA regression test suite passes
- [ ] External auditor review of audit trail + GL postings
- [ ] Performance acceptance criteria met (matching engine < 5s for 100-line invoice, etc.)
- [ ] Documentation complete + training materials reviewed
- [ ] Merge to development branch
- [ ] Release notes prepared
