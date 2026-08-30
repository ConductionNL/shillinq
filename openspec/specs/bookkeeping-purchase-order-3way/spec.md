---
status: done
---

# Spec: Purchase Order 3-way Match

**Primary spec for:** `bookkeeping-purchase-order-3way`

**Status**: done
**OpenSpec changes**:
- [prestatieverklaring-service-receipt](../../changes/archive/2026-07-13-prestatieverklaring-service-receipt/) _(archived 2026-07-13)_

## Purpose

@e2e exclude pure backend/schema: 3-way match purchase order — not browser-testable

### PurchaseOrder

**Schema.org:** `schema:Order`
_Purchase order representing a commitment to buy goods/services with approval chain, Peppol transmission, and lifecycle tracking._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| po_number | string | Yes | Auto-generated unique identifier per CBS richtlijn |
| supplier_reference | string | No | Supplier's reference number for this order |
| requester | string | Yes | FK to Person; employee initiating the PO |
| cost_center | string | No | Cost center code (e.g., FAC-2026) |
| project_code | string | No | Project code if applicable |
| currency | string | Yes | ISO 4217 code; default EUR |
| payment_terms | string | Yes | Payment conditions (e.g., net 30) |
| delivery_address | string | Yes | Physical delivery destination |
| expected_delivery_date | date | Yes | Anticipated delivery date |
| status | enum | Yes | draft, approved, sent, partial_received, fully_received, invoiced, closed, cancelled |
| approval_chain | array | Yes | Ordered list of approver roles/users required based on PO amount |
| peppol_sent_at | datetime | No | Timestamp when PO was sent via Peppol Access Point |
| peppol_message_id | string | No | Peppol unique message identifier (URN format) |
| peppol_fallback_reason | string | No | If sent via PDF+email instead of Peppol, reason logged here |

**Relations:**
- → PurchaseOrderLine (one-to-many)
- → Supplier (many-to-one)
- → ApprovalTask (one-to-many)
- → GoodsReceiptNote (one-to-many)
- → ThreeWayMatch (one-to-many)

### PurchaseOrderLine

**Schema.org:** `schema:OrderItem`
_Individual line item on a purchase order specifying product, quantity, price, and GL account._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| po_id | string | Yes | FK to PurchaseOrder |
| line_number | integer | Yes | Sequential line number on PO |
| product_or_service_code | string | Yes | Product/service code from opencatalogi |
| description | string | Yes | Item description |
| quantity_ordered | number | Yes | Quantity ordered |
| unit_of_measure | enum | Yes | UN/ECE Rec 20 conform (stuk, kg, uur, m³, etc.) |
| unit_price | number | Yes | Price per unit |
| currency | string | Yes | ISO 4217 code; inherited from PO |
| line_total | number | Yes | quantity_ordered × unit_price × (1 + vat_rate/100) |
| vat_rate | number | Yes | VAT percentage (21, 9, 6, 0 for NL standard rates) |
| vat_amount | number | Yes | (quantity_ordered × unit_price) × (vat_rate / 100) |
| expected_delivery_date | date | No | Line-specific delivery date if differs from PO header |
| gl_account | string | Yes | GL account code (kostenkrekening or voorraadrekening) |
| tolerance_override | number | No | Override global tolerance for this line (e.g., 2.0 for ±2%) |

**Relations:**
- → PurchaseOrder (many-to-one)
- → GoodsReceiptLine (one-to-many)
- → Product (many-to-one)

### GoodsReceiptNote

**Schema.org:** `schema:ReceiveAction`
_Document recording the physical receipt of goods, including quantity, condition, and photographic evidence._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| grn_number | string | Yes | Auto-generated unique identifier |
| po_ids | array | Yes | FK list to PurchaseOrder (multi-PO consolidation supported) |
| received_at | datetime | Yes | Timestamp when goods arrived at receiving location |
| received_by | string | Yes | FK to Person; magazijn-medewerker performing receipt |
| delivery_note_reference | string | No | Supplier's packing slip / delivery note number |
| carrier | string | No | Shipping carrier name (DHL, GLS, etc.) |
| lot_numbers | array | No | Lot/batch identifiers for tracked goods |
| serial_numbers | array | No | Individual serial numbers if tracked |
| temperature_log | object | No | Temperature readings if cold-chain product (timestamp + reading pairs) |
| quality_check_passed | boolean | Yes | Whether goods passed quality inspection |
| photos | array | No | File URIs for delivery condition photos |
| status | enum | Yes | draft, received, quality_checked, accepted, rejected |

**Relations:**
- → GoodsReceiptLine (one-to-many)
- → Person (many-to-one)
- → ThreeWayMatch (one-to-many)

### GoodsReceiptLine

**Schema.org:** `schema:Thing`
_Line-level receipt detail: what was actually received, accepted, or rejected per PO line._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| grn_id | string | Yes | FK to GoodsReceiptNote |
| po_line_id | string | Yes | FK to PurchaseOrderLine |
| quantity_received | number | Yes | Total quantity physically received (accepted + rejected) |
| quantity_accepted | number | Yes | Quantity accepted for stock/use |
| quantity_rejected | number | Yes | Quantity rejected (= received - accepted) |
| rejection_reason | enum | No | schade (damage), verkeerd_product (wrong item), expired, niet_besteld (not ordered), other |
| inspector | string | No | FK to Person who performed quality check |
| batch_reference | string | No | Batch/lot identifier if tracked |

**Relations:**
- → GoodsReceiptNote (many-to-one)
- → PurchaseOrderLine (many-to-one)

### SupplierInvoice

**Schema.org:** `schema:Invoice`
_Vendor invoice received for payment, with OCR extraction and Peppol metadata._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoice_number | string | Yes | Supplier's invoice number |
| supplier | string | Yes | FK to Payee (vendor organization) |
| invoice_date | date | Yes | Date invoice was issued |
| due_date | date | Yes | Payment deadline |
| total_excl_vat | number | Yes | Amount before VAT |
| total_vat | number | Yes | VAT/BTW amount |
| total_incl_vat | number | Yes | Total amount including VAT |
| currency | string | Yes | ISO 4217 code |
| payment_reference | string | No | Supplier's payment reference |
| ubl_source_uri | string | No | URN of incoming UBL Invoice document (if Peppol) |
| peppol_received_at | datetime | No | Timestamp when received via Peppol |
| ocr_confidence_score | number | No | OCR line-item extraction confidence (0.0-1.0) |
| status | enum | Yes | received, matching, matched, exception, approved, paid, rejected |

**Relations:**
- → Payee (many-to-one)
- → ThreeWayMatch (one-to-one)
- → Payment (one-to-many)

### ThreeWayMatch

**Schema.org:** `schema:Thing`
_Result of matching a supplier invoice against purchase order(s) and goods receipt(s) on header and line level._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoice_id | string | Yes | FK to SupplierInvoice |
| matched_po_ids | array | Yes | FK list to PurchaseOrder(s) |
| matched_grn_ids | array | Yes | FK list to GoodsReceiptNote(s) |
| match_status | enum | Yes | auto_approved, within_tolerance, exception_price, exception_quantity, exception_missing_grn, exception_missing_po, fraud_alert |
| divergence_details | object | No | JSON: {price_delta, price_pct, quantity_delta, quantity_pct, vat_delta, date_delta, grn_notes, supplier_note} |
| resolved_by | string | No | FK to Person if exception resolved manually |
| resolution_action | enum | No | auto_approve, accepted_with_motivation, dispute_filed, rejected |
| resolution_notes | string | No | Free-text explanation of exception resolution |
| created_at | datetime | Yes | Timestamp when match was evaluated |
| resolved_at | datetime | No | Timestamp when exception (if any) was resolved |

**Relations:**
- → SupplierInvoice (one-to-many)
- → PurchaseOrder (many-to-many)
- → GoodsReceiptNote (many-to-many)
- → Person (many-to-one)

### ToleranceProfile

**Schema.org:** `schema:Thing`
_Configuration rules for acceptable divergence between PO, GRN, and Invoice._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| tolerance_profile_id | string | Yes | Auto-generated unique identifier |
| scope | enum | Yes | global, supplier, category, gl_account |
| scope_reference | string | No | FK (if supplier/category/gl_account scope) |
| price_tolerance_amount | number | No | Fixed amount tolerance in EUR |
| price_tolerance_percentage | number | No | Percentage tolerance (0.5 = 0.5%) |
| quantity_tolerance_percentage | number | No | Quantity variance allowed (±2.0) |
| date_tolerance_days | number | No | Early/late delivery tolerance (±3 days) |
| currency_rounding_tolerance | number | No | Rounding tolerance (0.01 for cent-level) |
| exception_routing | string | No | Approver role/person for exceptions (default: crediteuren-administrateur) |
| status | enum | Yes | active, inactive, archived |

**Relations:**
- → Supplier (many-to-one, if supplier scope)
- → Account (many-to-one, if gl_account scope)

### VendorPerformance

**Schema.org:** `schema:AggregateRating`
_Monthly supplier scorecard tracking on-time delivery, quantity accuracy, price accuracy, and invoice accuracy._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendor_performance_id | string | Yes | Auto-generated unique identifier |
| supplier_id | string | Yes | FK to Payee |
| period | string | Yes | YYYY-MM (e.g., 2026-05) |
| on_time_delivery_rate | number | Yes | % of GRNs received by expected_delivery_date |
| quantity_accuracy_rate | number | Yes | % of lines received matching ordered quantity (within tolerance) |
| price_accuracy_rate | number | Yes | % of lines invoiced within price tolerance |
| invoice_accuracy_rate | number | Yes | % of invoices matched on first try (no exceptions) |
| dispute_count | integer | No | Number of unresolved disputes in period |
| average_resolution_days | number | No | Avg days to resolve exceptions |
| overall_score | number | Yes | Weighted average: (40% on_time + 30% qty + 20% price + 10% invoice) |
| score_trend | enum | No | improving, stable, declining |
| automated_review_eligible | boolean | Yes | TRUE if overall_score ≥ 96 |

**Relations:**
- → Supplier (many-to-one)

---
## Requirements

### REQ-PO3W-001: Create purchase order with approval chain

The system SHALL satisfy this requirement: Create purchase order with approval chain.

**Demand**: Must implement ordered-list approval routing based on PO amount threshold.

**Narrative:**  
An **Inkoper** (purchasing agent) creates a purchase order for 200 office chairs at €18,500 total (cost center FAC-2026, no project code). The system validates that the cost center has available budget, determines the required approval chain based on the PO amount (>€10,000 = Teamleider + Facility Manager), issues notifications to each approver, and blocks PO transmission to supplier until all approvals are signed with timestamps.

#### Scenario:

**GIVEN** an Inkoper creates a PO for €18,500 with cost_center "FAC-2026"  
**WHEN** the system evaluates the approval_chain based on amount thresholds  
**THEN** it identifies two required approvers (Teamleider, Facility Manager), assigns ApprovalTask records to each, blocks PO status from advancing to "sent" until both approve with timestamps, and notifies both approvers via the notification service

---

### REQ-PO3W-002: Peppol transmission of approved PO to supplier

The system SHALL satisfy this requirement: Peppol transmission of approved PO to supplier.

**Demand**: Must emit PO as UBL Order via Peppol Access Point with fallback to PDF+email.

**Narrative:**  
An **Inkoper** sends an approved PO to a Peppol-registered supplier (ErenteSchreuders). The system transforms the PO to UBL 2.1 Order document per Peppol BIS Ordering 3.0, submits via openconnector's Peppol Access Point, records the peppol_message_id, and marks peppol_sent_at. If the supplier is not Peppol-registered, the system gracefully falls back to PDF + email, logging the fallback_reason explicitly.

#### Scenario:

**GIVEN** a PO is approved and supplier is Peppol-registered (Peppol participant ID: 0192:1234567890)  
**WHEN** Inkoper clicks "Send PO"  
**THEN** the system transforms PO → UBL Order, submits to openconnector Peppol Access Point, receives Message-Level Response, records peppol_message_id (URN format) and peppol_sent_at timestamp; if supplier NOT Peppol-registered, sends PDF+email and logs peppol_fallback_reason: "supplier_not_peppol_participant"

---

### REQ-PO3W-003: Goods receipt note entry with line-level quantities

The system SHALL satisfy this requirement: Goods receipt note entry with line-level quantities.

**Demand**: Magazijn-medewerker must record received quantities per PO line with photographic evidence.

**Narrative:**  
A **magazijn-medewerker** receives a pallet of 180 chairs (out of 200 ordered per PO-2026-0003). Using the mobile GRN interface, they select the PO, enter line-by-line quantities (180 accepted, 0 rejected on first line; 20 short-shipped), upload photos of the delivery, and record the carrier + delivery note number. The system updates PO status to "partial_received", reserves the 180 units in inventory as received stock, and flags the 20-unit short-shipment for vendor follow-up.

#### Scenario:

**GIVEN** a Magazijn-medewerker receives 180 of 200 ordered office chairs  
**WHEN** they create a GoodsReceiptNote via mobile app linked to PO-2026-0003  
**THEN** the system accepts line-item quantities (GoodsReceiptLine: po_line_id, quantity_received=180, quantity_accepted=180, quantity_rejected=20), records rejection_reason="short_shipped", updates PO status to "partial_received", credits inventory for 180 units at the gl_account on PO line, creates a GR/IR clearing posting (DR Inventory / CR GR-IR Clearing), and allows upload of delivery condition photos

---

### REQ-PO3W-004: Automated 3-way matching within tolerance

The system SHALL satisfy this requirement: Automated 3-way matching within tolerance.

**Demand**: Matching engine must evaluate PO + GRN + Invoice on price, quantity, VAT at line level.

**Narrative:**  
A **Peppol invoice** arrives from ErenteSchreuders for €18,547 (PO: €18,500, delta +€47; GRN: 180 units accepted). The matching engine compares PO regels ↔ GRN regels ↔ Invoice regels on (product_code, quantity, price, vat). The price delta is €47 on a €18,500 base = 0.25%, which is within the tolerance_profile (€10 absolute OR 0.5% relative, whichever is MORE permissive). The system marks match_status="auto_approved", routes the invoice directly to payment stack, and requires no human review.

#### Scenario:

**GIVEN** an invoice arrives for €18,547 against a PO of €18,500 with a GRN of 180 units accepted  
**WHEN** the matching engine evaluates line-level divergences against the global tolerance_profile (price_tolerance_amount €10, price_tolerance_percentage 0.5%)  
**THEN** it calculates price_delta=+€47 (0.25% < 0.5%), quantity_delta=0, vat_delta=0, marks match_status="auto_approved", logs divergence_details JSON, and routes the invoice to payment approval without manual intervention

---

### REQ-PO3W-005: Exception workflow for price deviation

The system SHALL satisfy this requirement: Exception workflow for price deviation.

**Demand**: Out-of-tolerance matches must route to crediteuren-administrateur with decision options.

**Narrative:**  
An invoice arrives for €19,250 (PO: €18,500, delta +€750 = 4.1% deviation). This exceeds both the absolute (€10) and percentage (0.5%) tolerance. The system marks match_status="exception_price", creates a notification for the crediteuren-administrateur with a side-by-side comparison (PO €18,500 vs GRN 180 units vs Invoice €19,250), and presents three actions:
1. Accept with motivation (e.g., "price increase authorized by procurement manager per email ref XYZ")
2. File dispute with supplier (auto-generates UBL CreditNote request, escalates to Inkoper for follow-up)
3. Reject and block payment (invoice marked rejected, stock restored if partial GRN)

#### Scenario:

**GIVEN** an invoice for €19,250 arrives against a PO of €18,500 (4.1% variance)  
**WHEN** the matching engine evaluates divergence against tolerance_profile  
**THEN** it determines variance exceeds tolerance, marks match_status="exception_price", creates a ThreeWayMatch with divergence_details={price_delta: 750, price_pct: 4.1}, routes to crediteuren-administrateur via notification, displays side-by-side UI showing PO/GRN/Invoice, and awaits one of three resolution_action choices (accept_with_motivation, dispute_filed, rejected); blocks payment until resolved

---

### REQ-PO3W-006: Configurable tolerance profiles per supplier/category

The system SHALL satisfy this requirement: Configurable tolerance profiles per supplier/category.

**Demand**: Controller must be able to override global tolerances per supplier or GL category.

**Narrative:**  
A **controller** onboards a new supplier (NieuweLeverancierBV) with a vendor_score of 45 (unproven). They create a tolerance_profile with scope="supplier=NieuweLeverancierBV", price_tolerance_amount €0, quantity_tolerance_percentage 0%, and exception_routing=(crediteuren-administrateur + controller). This zero-tolerance regime overrides the global profile until the vendor score climbs above 80. The controller can retroactively apply this profile to open matches with explicit confirmation; all changes are audit-logged with before/after snapshots.

#### Scenario:

**GIVEN** a controller creates a tolerance_profile for a new supplier with stricter tolerances (price_tolerance_amount=0, quantity_tolerance_percentage=0)  
**WHEN** subsequent invoices from this supplier are matched  
**THEN** the matching engine applies the supplier-scoped tolerance_profile instead of the global profile, treats any variance as exception, routes to crediteuren-administrateur + controller, and logs all profile changes in audit trail with before/after snapshot

---

### REQ-PO3W-007: Multi-PO consolidated invoice matching

The system SHALL satisfy this requirement: Multi-PO consolidated invoice matching.

**Demand**: One invoice can match lines from 10 different POs/GRNs via line-level matching.

**Narrative:**  
A supplier sends one **maand-factuur** (monthly invoice) covering 12 different POs placed throughout May. The OCR/UBL engine extracts all invoice regels, attempts to match each to candidate (PO line, GRN line) tuples based on product_code + date range (within 30 days of invoice). When matches are ambiguous (e.g., two POs for the same product), the crediteuren-administrateur clarifies via the matching UI. Each matched trio (PO line, GRN line, Invoice line) generates its own ThreeWayMatch record; some may auto-approve while others route to exception, all independently.

#### Scenario:

**GIVEN** one monthly invoice covers 12 different PO's received during the period  
**WHEN** the OCR/UBL extraction engine processes the invoice  
**THEN** it extracts all invoice line items, searches for matching (PO line, GRN line) tuples via product_code + date proximity, presents ambiguous matches to crediteuren-administrateur for confirmation, creates individual ThreeWayMatch records per (PO, GRN, Invoice line) trio, and processes each independently through the matching/exception workflow

---

### REQ-PO3W-008: Vendor performance scoring with auto-review eligibility

The system SHALL satisfy this requirement: Vendor performance scoring with auto-review eligibility.

**Demand**: Suppliers with 96%+ performance score unlock auto-approval; tolerances relax automatically.

**Narrative:**  
ErenteSchreuders has achieved 12 months of 96%+ on-time delivery, 99% quantity accuracy, and zero invoice disputes. The monthly vendor-scoring process runs, calculates overall_score=98.5 (weighted: 40% on_time, 30% qty, 20% price, 10% invoice), marks automated_review_eligible=TRUE, and automatically relaxes the tolerance_profile for this supplier (price_tolerance_percentage increases from 0.5% → 1.5%, quantity_tolerance_percentage: 2% → 5%). The controller is notified that ErenteSchreuders has been elevated to auto-review status.

#### Scenario:

**GIVEN** a supplier has 12 months of 96%+ on-time delivery, 99% quantity accuracy, 97% price accuracy, and 100% invoice-accuracy-on-first-try  
**WHEN** the monthly vendor-scoring aggregation runs  
**THEN** it calculates overall_score=98.5 (weighted average), sets automated_review_eligible=TRUE, optionally auto-relaxes the tolerance_profile for this supplier (or presents relaxation as an option to the controller), and notifies controller of the elevated status; subsequent invoices from this supplier auto-approve unless exception is fraud_alert or other critical condition

---

### REQ-PO3W-009: GR/IR clearing and GL posting on match

The system SHALL satisfy this requirement: GR/IR clearing and GL posting on match.

**Demand**: Balanced GL posting must be created at GRN time (clearing) and at invoice approval time (settlement).

**Narrative:**  
When a GoodsReceiptNote is accepted for 180 chairs at €18,500 total (21% VAT), a balanced posting is immediately materialized:
- **Debit**: Inventory [GL account per PO line, e.g., 1200] €18,500
- **Credit**: GR/IR Clearing [GL account per ToleranceProfile, e.g., 2910] €18,500

When the ThreeWayMatch is approved (invoice €18,547), a second posting settles the clearing:
- **Debit**: GR/IR Clearing [2910] €18,500
- **Credit**: Accounts Payable [supplier liability, e.g., 4400] €18,500 (excl VAT)
- **Credit**: VAT Payable [2100] €3,887.50 (inclusive of invoice VAT variance)

The GL posting preserves cost_center and gl_account from the PO line. The GR/IR control account saldo must reconcile to zero at period-end (no dangling goods-in-transit).

#### Scenario:

**GIVEN** a GoodsReceiptNote is accepted for 180 units at PO line gl_account=1200  
**WHEN** the GRN transitions to "accepted" status  
**THEN** the system materializes a balanced posting: DR 1200 (Inventory) / CR 2910 (GR/IR Clearing) for the line amount; when the invoice is subsequently approved, a second posting: DR 2910 / CR 4400 (AP) + 2100 (VAT) settles the clearing; both postings are linked to the ThreeWayMatch record and inherit cost_center from the PO line

---

### REQ-PO3W-010: Audit trail and compliance export for external auditors

The system SHALL satisfy this requirement: Audit trail and compliance export for external auditors.

**Demand**: Complete lifecycle history must be audit-trailed and exportable per NV COS 230.

**Narrative:**  
An **external auditor** sampling 25 invoices for year-end audit review can, per invoice, oproepen the complete audit-trail:
1. PO creation timestamp + requester
2. Approval chain with approver names, roles, timestamps, comments
3. Peppol transmission timestamp + message ID (or fallback reason if PDF+email)
4. GRN creation timestamp + received_by + delivery photos
5. SupplierInvoice receipt timestamp + OCR confidence
6. ThreeWayMatch evaluation timestamp + match_status + divergence_details
7. Exception resolution (if any): resolved_by + resolution_action + notes + timestamp
8. GL posting timestamps + journal entries
9. Payment date + reference

The system exports this as a structured audit package (ZIP: PDF summary + JSON ledger + file attachments), fully immutable and linked to the GoodsReceiptNote photographic evidence and signed approval chain records, conforming to BW2 art 2:10 (7-year retention) and NBA COS 230 documentation standards.

#### Scenario:

**GIVEN** an external auditor reviews a sample invoice during year-end audit  
**WHEN** they request the complete audit trail for invoice INV-ERS-2026-00445  
**THEN** the system generates an immutable audit package containing: PO creation record, approval-chain signatures with timestamps, Peppol transmission metadata, GRN receipt record with photos, invoice receipt metadata, ThreeWayMatch evaluation + divergence details, exception resolution notes (if any), GL posting records, payment record; exports as structured ZIP (PDF summary + JSON + attachments); all records are cryptographically linked and timestamped per NV COS 230 §audit trail

---

### Requirement: REQ-PO3W-011 — Service-entry-sheet (prestatieverklaring) as the third leg for service PO lines

The system SHALL satisfy this requirement: a purchase-order line for a
service (consultancy, maintenance, subscription, contract labour) MUST be
able to reach a matched `ThreeWayMatch` state via a **prestatieverklaring**
(`SvcReceipt`) confirming service delivery, without requiring a
`GoodsReceiptNote` that would never physically exist for that line.

**Demand**: An approver named on the service PO confirms delivery for a
period (start/end date), expressed as a percentage complete, a confirmed
quantity, or a confirmed euro amount; the confirmation may be partial and
repeated across multiple billing periods (e.g. monthly for a 12-month
contract). Once a `SvcReceipt` is `accepted`, `ThreeWayMatchingEngine`
MUST treat it exactly as it treats an accepted `GoodsReceiptNote` — as
satisfying the matching engine's third leg — so a service invoice can
reach `auto_approved` / `within_tolerance` instead of being permanently
stuck in `exception_missing_grn`.

#### Scenario: A monthly consultancy retainer confirms delivery and matches the supplier invoice

@e2e exclude pure backend/service matching logic — not browser-testable
(mirrors REQ-PO3W-004's own `@e2e exclude`)

- GIVEN a PurchaseOrder for a monthly consultancy retainer with one
  PurchaseOrderLine (`quantityOrdered: 1`, `unitPrice: 500000`)
- AND an approver creates a `SvcReceipt` for July, adds a `SvcReceiptLine`
  against that PO line with `percentageComplete: 10000` (100%), and
  transitions the receipt `draft → confirmed → accepted`
- WHEN a SupplierInvoice for the same PO arrives with a matching line and
  `ThreeWayMatchingEngine::evaluateMatch()` runs
- THEN the engine resolves the accepted `SvcReceipt` as the third leg (no
  `GoodsReceiptNote` exists or is required), computes divergence the same
  way it would for a goods receipt, and the invoice reaches
  `auto_approved` or `within_tolerance` — a state that was unreachable
  before this change (the only prior outcome for a service PO was
  `exception_missing_grn`)

#### Scenario: Partial periodic confirmation accumulates across billing periods

@e2e exclude pure backend/service matching logic — not browser-testable

- GIVEN a 3-month service PO line with `quantityOrdered: 3` (one unit per
  month)
- WHEN an approver accepts a `SvcReceipt` confirming month 1
  (`quantityAccepted: 1`) and later a second `SvcReceipt` confirming month
  2 (`quantityAccepted: 1`)
- THEN the originating PurchaseOrder's receipt lifecycle recomputes to
  `partial_received` (2 of 3 accepted, mirroring
  `GoodsReceiptNoteService::updatePurchaseOrderReceiptLifecycle()`'s
  existing partial-goods-receipt behaviour) and transitions to
  `fully_received` once month 3 is also accepted

---

## Acceptance Criteria (cross-requirement)

- [ ] PurchaseOrder records can be created, approved via configurable approval chains, and transmitted to Peppol-registered suppliers with fallback to PDF+email
- [ ] GoodsReceiptNote records capture line-item quantities, rejection reasons, and delivery photos with stock mutation
- [ ] SupplierInvoice records are created from Peppol-received UBL Invoices with OCR extraction
- [ ] ThreeWayMatch engine evaluates line-level divergences (price, qty, VAT, date) against configurable tolerance profiles
- [ ] Matches within tolerance auto-approve; out-of-tolerance matches route to crediteuren-administrateur exception workflow
- [ ] ToleranceProfile supports global, supplier, category, and GL-account scopes with override capability
- [ ] VendorPerformance aggregations calculate monthly scores and flag 96%+ suppliers for auto-review
- [ ] GL postings are materialized at GRN time (GR/IR clearing) and at invoice approval time (settlement)
- [ ] Audit trail captures complete lifecycle (PO → approval → GRN → invoice → match → GL → payment) per NV COS 230
- [ ] Multi-PO consolidated invoices (1 invoice → many POs) are matched at line level with crediteuren-administrateur disambiguation
- [ ] All entities inherit cost_center + project_code from PO for dimensional reporting
- [ ] Manifest entries provide index/detail views for Purchase Orders, GRNs, Invoices, Matches, and Exceptions
