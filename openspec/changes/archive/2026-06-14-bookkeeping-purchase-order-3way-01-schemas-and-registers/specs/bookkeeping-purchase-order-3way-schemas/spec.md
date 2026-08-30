# Spec Delta: Purchase Order 3-way Match — Schemas & Registers

## ADDED Requirements

### Requirement: Declare the eight 3-way-match registers with lifecycles

The system SHALL declare eight OpenRegister schemas in
`lib/Settings/shillinq_register.json` — `PurchaseOrder`,
`PurchaseOrderLine`, `GoodsReceiptNote`, `GoodsReceiptLine`,
`SupplierInvoice`, `ThreeWayMatch`, `ToleranceProfile`, and
`VendorPerformance` — each with its declared field set and, where
applicable, an `x-openregister-lifecycle` state machine.

`PurchaseOrder` SHALL carry the lifecycle draft → approved → sent →
partial_received → fully_received → invoiced → closed (with a cancelled
terminal state). `GoodsReceiptNote` SHALL carry draft → received →
quality_checked → accepted → rejected. `SupplierInvoice` SHALL carry
received → matching → matched → exception → approved → paid → rejected.
`ThreeWayMatch` SHALL carry a match_status enum (auto_approved,
within_tolerance, exception_price, exception_quantity,
exception_missing_grn, exception_missing_po, fraud_alert). Every order,
receipt, invoice, and match record SHALL preserve cost_center and
project_code from the originating PO for dimensional reporting.

#### Scenario: Registers materialise with declared lifecycles

- **GIVEN** the shillinq app config is applied
- **WHEN** OpenRegister materialises the schemas from `shillinq_register.json`
- **THEN** all eight registers exist, each `PurchaseOrder`/`GoodsReceiptNote`/`SupplierInvoice`/`ThreeWayMatch` schema exposes its declared lifecycle states, and cost_center + project_code round-trip on created records

### Requirement: Provide navigation and seed data for the 3-way-match registers

The system SHALL declare five navigation entries in `src/manifest.json`
— Purchase Orders, Goods Receipts, Supplier Invoices, 3-way Matches, and
Exceptions (a filtered index of match_status ∈ {exception_price,
exception_quantity, exception_missing_grn, exception_missing_po,
fraud_alert}) — each with an index and a detail page. The change SHALL
ship seed fixtures (3-5 examples per entity) and an integration test that
asserts the materialised registers, lifecycle states, and navigation
entries are correct.

#### Scenario: Navigation entries and seed fixtures verified

- **GIVEN** the seed fixtures are loaded and the manifest is rendered
- **WHEN** the integration test runs
- **THEN** the five navigation entries (Purchase Orders, Goods Receipts, Supplier Invoices, 3-way Matches, Exceptions) resolve to index/detail pages, and the seeded records (PO-2026-0001..0003, GRN-2026-0011..0012, INV-ERS-2026-00445, MATCH-2026-001..002, the three ToleranceProfiles, two VendorPerformance rows) are queryable with their declared field values
