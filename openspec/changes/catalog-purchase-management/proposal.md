---
status: proposed
source: specter
features: [product-and-service-item-management-with-category-hierarchy, rfq-management-with-multi-supplier-solicitation-and-quote-comparison, catalog-management, purchase-order-management-with-three-way-matching, purchase-cycle-management]
---

# Catalog & Purchase Management — Shillinq

## Summary

Implements catalog and procurement management for Shillinq: a hierarchical product and service item registry, pre-approved vendor catalogs with negotiated pricing, an order basket and checkout flow with cost-centre assignment, RFQ management with multi-supplier solicitation and structured quote comparison, purchase order creation with three-way matching against goods receipts and supplier invoices, and full purchase cycle tracking from requisition to payment. These capabilities address the five highest-demand catalog and procurement features identified in the Specter intelligence model and build on the core, access-control-authorisation, collaboration, document-management, and supplier-management infrastructure changes.

## Demand Evidence

Top features by market demand score:

- **Product and service item management with category hierarchy** (demand: 2147) — the top-ranked catalog-and-procurement feature. Requisitioners and procurement officers need a structured item master with CPV-aligned categories, unit-of-measure control, and active/inactive lifecycle, so that catalog searches return consistent, pre-approved items.
- **RFQ management with multi-supplier solicitation and quote comparison** (demand: 1760) — procurement officers need to send a single RFQ to multiple qualified suppliers, track response status per supplier, and compare submitted quotes side-by-side against a budget envelope before awarding.
- **Catalog management** (demand: 1715) — organisations maintain one or more internal catalogs of pre-negotiated products and services; buyers must be able to search, filter, and order from these catalogs without initiating a new procurement process each time.
- **Purchase order management with three-way matching** (demand: 1660) — finance controllers need to match a purchase order, a goods receipt, and a supplier invoice before approving payment, catching discrepancies in quantity, price, or delivery.
- **Purchase cycle management** (demand: 1643) — requisitioners need end-to-end visibility from catalog requisition through approval, purchase order transmission, goods receipt confirmation, and invoice payment, with status tracking and overdue alerts at every stage.

Key stakeholder pain points addressed:

- **Customer (requisitioner/buyer)**: no pre-approved catalog to search, must initiate full procurement each time, cannot track order status or confirm delivery in the system — addressed by catalog browsing, basket checkout, delivery tracking, and goods receipt confirmation.
- **Group Controller**: manual matching of PO, delivery notes, and invoices in spreadsheets; discrepancies discovered late in the payment cycle — addressed by structured three-way matching with inline discrepancy reporting.

## What Shillinq Already Has

After the core, access-control-authorisation, collaboration, document-management, and supplier-management changes:

- OpenRegister schemas for `Organization` (Supplier), `AppSettings`, `AccessControl`, `Comment`, `Document`, `DocumentVersion`, `SupplierProfile`, `SourcingEvent`, `NegotiationEvent`, `Budget`
- Nextcloud notification integration and ApprovalWorkflow infrastructure
- Entity list, detail, and form views using `CnIndexPage` / `CnDetailPage` / `CnFormDialog`
- Global search, admin settings, sidebar navigation, user preferences
- Document attachment panel and CPV code selector component

### What Is Missing

- No `ProductCategory` schema for hierarchical product and service classification
- No `Product` schema for the item master with SKU, unit-of-measure, and category assignment
- No `Catalog` schema for named collections of pre-approved items with effective dates
- No `CatalogItem` schema for product–catalog associations with negotiated unit prices
- No `OrderBasket` schema for the in-progress requisition before checkout
- No `PurchaseOrder` schema for formal purchase orders transmitted to suppliers
- No `PurchaseOrderLine` schema for individual line items on a PO
- No `GoodsReceipt` schema for delivery confirmation and quantity discrepancy recording
- No `SupplierQuote` schema for structured supplier responses to RFQ solicitations
- No order basket UI or checkout flow with cost-centre assignment
- No three-way matching logic comparing PO lines, goods receipt lines, and invoice lines
- No purchase cycle status tracking with overdue delivery alerting

## Scope

### In Scope

1. **ProductCategory Schema and Hierarchy** — OpenRegister `ProductCategory` schema (`schema:Thing`) supporting parent–child category nesting via a `parentCategoryId` property. A `CnIndexPage` tree view renders the hierarchy. Views at `src/views/productCategory/`; store at `src/store/modules/productCategory.js`.

2. **Product Schema and Item Master** — OpenRegister `Product` schema (`schema:Product`) with SKU, name, description, unit-of-measure, active flag, and category assignment. A duplicate-SKU check is enforced on save. Views at `src/views/product/`; store at `src/store/modules/product.js`. `columnsFromSchema()` and `fieldsFromSchema()` drive list and form rendering.

3. **Catalog Schema and Catalog Item Management** — `Catalog` schema (`schema:CreativeWork`) for named collections with effective start/end dates, owner, and status (draft / active / archived). `CatalogItem` schema (`schema:Offer`) links a `Product` to a `Catalog` with negotiated unit price, currency, minimum order quantity, and lead-time days. Bulk import via CSV upload (product SKU + price columns) with server-side validation and error reporting. Views at `src/views/catalog/` and `src/views/catalogItem/`; stores at `src/store/modules/catalog.js` and `src/store/modules/catalogItem.js`.

4. **Catalog Search and Order Basket** — a requisitioner-facing catalog search view (`src/views/catalog/CatalogSearch.vue`) using `CnIndexPage` with full-text and category filters, showing product name, supplier, unit price, and contract reference. An `OrderBasket` schema (`schema:Order`) holds in-progress items before checkout submission. Requisitioners add items, adjust quantities, see a running total, and assign a cost centre and GL account. Budget availability is checked against the `Budget` entity; a warning (not a hard block) is shown when the basket total exceeds the available budget. If the basket total exceeds the user's personal ordering limit (configured in AppSettings), the order is routed to an approver via the `ApprovalWorkflow` mechanism.

5. **Purchase Order Schema and Transmission** — `PurchaseOrder` schema (`schema:Order`) with PO number (auto-generated), status (draft / submitted / acknowledged / partially_received / received / invoiced / closed / cancelled), supplier reference, delivery address, and expected delivery date. `PurchaseOrderLine` schema (`schema:OrderItem`) with product, quantity, unit price, and line total. On approval, the PO is transmitted to the supplier via Nextcloud notification (and optionally email via `IMailer`). Views at `src/views/purchaseOrder/`; store at `src/store/modules/purchaseOrder.js`.

6. **Goods Receipt and Three-Way Matching** — `GoodsReceipt` schema (`schema:ReceiveAction`) linked to a `PurchaseOrder`, with one `GoodsReceiptLine` per PO line capturing received quantity, receipt date, and discrepancy note. On goods receipt save, `ThreeWayMatchingService` compares PO lines, receipt lines, and linked invoice lines; discrepancies (quantity short, price mismatch) are flagged as `match_status: discrepancy` on the PO line and surfaced in the finance controller's matching view. Views at `src/views/goodsReceipt/`; store at `src/store/modules/goodsReceipt.js`.

7. **RFQ Schema and Multi-Supplier Solicitation** — OpenRegister `RFQ` schema (`schema:Quotation`) with RFQ number, title, description, type (RFI / RFP / RFQ / Auction), status (draft / published / evaluating / awarded / closed), budget envelope, and due date. `SupplierQuote` schema (`schema:Offer`) records each supplier's quoted unit price, total, validity date, notes, and status (submitted / accepted / rejected). A quote comparison table view renders all `SupplierQuote` objects for an RFQ side-by-side, ranked by total price. Views at `src/views/rFQ/` and `src/views/supplierQuote/`; stores at `src/store/modules/rFQ.js` and `src/store/modules/supplierQuote.js`.

8. **Purchase Cycle Tracking and Overdue Alerts** — `PurchaseOrder.status` progresses through the full purchase cycle. A background job (`lib/BackgroundJob/OverdueDeliveryJob.php`) runs daily, identifies POs where `expectedDeliveryDate` has passed and status is not `received` or later, highlights them in the order list with an overdue badge, and sends a Nextcloud notification to the requisitioner. From the order history, requisitioners can send a delivery reminder to the supplier (Nextcloud notification to `SupplierProfile.assignedOfficerId`).

9. **Supplier Views (catalog context)** — `src/views/supplier/` provides a lightweight `SupplierIndex.vue` and `SupplierDetail.vue` for selecting suppliers during catalog item and PO creation. These views read the `SupplierProfile` schema defined in the supplier-management change and do not redefine it. Store at `src/store/modules/supplier.js` wraps `createObjectStore('supplierProfile')` under the `supplier` alias.

10. **Seed Data** — demo records for all new schemas (ADR-016): 2 ProductCategories, 4 Products, 1 Catalog, 4 CatalogItems, 1 OrderBasket, 1 PurchaseOrder with 2 PurchaseOrderLines, 1 GoodsReceipt, 1 RFQ with 2 SupplierQuotes. Loaded via the repair step idempotently.

### Out of Scope

- UBL/Peppol purchase order transmission — deferred to e-invoicing change
- AI-based OCR for supplier invoice ingestion — deferred (requires external AI service)
- Auction and reverse-auction bidding UI — deferred
- Multi-currency PO with live exchange rates — deferred to multi-currency change
- Inventory quantity tracking and replenishment rules — deferred to inventory management change
- Contract-linked catalog pricing with automatic expiry — deferred to contract lifecycle change
- Supplier performance scoring based on delivery accuracy — deferred to supplier-management follow-up

## Acceptance Criteria

1. GIVEN a requisitioner navigates to the catalog search WHEN they enter a search term THEN matching `CatalogItem` objects are returned with product name, supplier name, unit price, and catalog reference, filtered to active catalogs and active products only.
2. GIVEN a requisitioner adds items to their basket WHEN they update a quantity THEN the running total recalculates immediately; on checkout, if the basket total exceeds the user's ordering limit, the order is routed to an approver.
3. GIVEN a requisitioner assigns a cost centre WHEN the available budget is insufficient THEN a warning is shown but submission is not blocked; the order is flagged for budget approval.
4. GIVEN a purchase order has been sent to supplier WHEN the expected delivery date passes without a goods receipt THEN the `OverdueDeliveryJob` highlights the order and notifies the requisitioner.
5. GIVEN a requisitioner confirms receipt WHEN received quantities differ from ordered quantities THEN discrepancy notes are recorded per line and the `ThreeWayMatchingService` flags the PO line as `match_status: discrepancy` for the finance controller.
6. GIVEN an RFQ is published to multiple suppliers WHEN all quotes are submitted THEN a side-by-side comparison table renders all `SupplierQuote` objects ranked by total price with the budget envelope shown for reference.
