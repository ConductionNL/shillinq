---
status: pr-created
---

# Catalog & Purchase Management — Shillinq

## Purpose

Defines functional requirements for Shillinq's catalog and procurement capabilities: a hierarchical product and service item master, pre-approved vendor catalogs with negotiated pricing, requisitioner basket checkout with cost-centre assignment and approval routing, RFQ management with multi-supplier solicitation and side-by-side quote comparison, purchase order creation and transmission, goods receipt confirmation with discrepancy recording, three-way matching for invoice processing, and full purchase cycle tracking with overdue delivery alerting.

Stakeholders: Customer (requisitioner/buyer), Group Controller.

User stories addressed: Search pre-approved catalog items, Add catalog items to order basket, Assign cost center to catalog order, Track catalog order delivery status, Confirm receipt of catalog order.

## Requirements

### REQ-CAT-001: Product Category Hierarchy and Product Item Master [must]

The app MUST register the `ProductCategory` schema (`schema:Thing`) supporting parent–child nesting via `parentCategoryId`. The app MUST register the `Product` schema (`schema:Product`) with SKU, name, description, unit, active flag, and category assignment. Duplicate SKUs MUST be rejected on save. Inactive products MUST not appear in catalog search results.

**Scenarios:**

1. **GIVEN** a procurement manager opens the product category list **WHEN** the list renders **THEN** `CnIndexPage` displays root categories with nested child categories visible on expand; each row shows `name`, `code`, and the number of products in that category.

2. **GIVEN** a product manager creates a product with SKU `PAPER-A4-80` **WHEN** the form is submitted **THEN** a `Product` object is created in OpenRegister with `sku: "PAPER-A4-80"`, `unit: "ream"`, `active: true`, and the selected `categoryId`.

3. **GIVEN** a product with SKU `PAPER-A4-80` already exists **WHEN** a second product is saved with the same SKU **THEN** the API returns 422 with "A product with this SKU already exists." and no duplicate is created.

4. **GIVEN** a product has `active: false` **WHEN** a requisitioner searches the catalog **THEN** `CatalogSearchService` excludes the product from results regardless of whether it has active CatalogItems.

5. **GIVEN** a product is assigned to category `OFFICE-SUP` **WHEN** a requisitioner filters the catalog by category **THEN** only products in `OFFICE-SUP` and its child categories are returned.

### REQ-CAT-002: Catalog Management with Negotiated Pricing and Bulk Import [must]

The app MUST register the `Catalog` schema (`schema:CreativeWork`) and `CatalogItem` schema (`schema:Offer`). A catalog transitions through draft → active → archived. Each `CatalogItem` links a `Product` to a `Catalog` with a negotiated unit price. Bulk import via CSV upload MUST validate each row against existing Product SKUs and return per-row errors for invalid rows. Only items from active catalogs within their effective date range MUST be visible in catalog search.

**Scenarios:**

1. **GIVEN** a procurement manager creates a catalog `Office Essentials 2026` with `effectiveFrom: 2026-01-01`, `effectiveTo: 2026-12-31` and status `draft` **WHEN** the catalog is activated **THEN** `status` changes to `active` and the catalog becomes available for requisitioners to browse.

2. **GIVEN** a catalog is `active` and `effectiveTo` is 2026-12-31 **WHEN** a requisitioner searches on 2027-01-01 **THEN** `CatalogSearchService` excludes all items from this catalog from results.

3. **GIVEN** a procurement manager uploads a CSV to `POST /api/v1/catalogs/{id}/import` with 10 rows **WHEN** 2 rows have unknown SKUs **THEN** the API returns 200 with `{imported: 8, errors: [{row: 4, sku: "UNKNOWN-1", message: "Product not found"}, {row: 7, sku: "UNKNOWN-2", message: "Product not found"}]}` and 8 valid CatalogItems are created or updated.

4. **GIVEN** a `CatalogItem` is created with `unitPrice: 4.50` for product `PAPER-A4-80` **WHEN** a requisitioner views that item in the catalog **THEN** the displayed price is `EUR 4.50 / ream` with supplier name and catalog contract reference.

5. **GIVEN** a catalog has `status: archived` **WHEN** a procurement manager attempts to add new items via the form or CSV import **THEN** the API returns 422 with "Cannot add items to an archived catalog."

### REQ-CAT-003: Catalog Search, Order Basket, and Checkout [must]

The app MUST provide a requisitioner-facing catalog search view with full-text and category filters returning product name, supplier, unit price, and contract reference. Requisitioners MUST be able to add items to an `OrderBasket`, adjust quantities, see a running total, and assign a cost centre and GL account. On checkout submission, if the basket total exceeds the user's personal ordering limit, the order MUST be routed to an approver via `ApprovalWorkflow`. A warning (not a hard block) MUST be shown when the basket total exceeds the available budget for the selected cost centre.

**Scenarios:**

1. **GIVEN** a requisitioner navigates to catalog search and enters "paper" **WHEN** results render **THEN** all active `CatalogItem` objects whose linked `Product.name` or `Product.description` contains "paper" are returned, showing product name, supplier name, unit price with currency, and `Catalog.contractReference`.

2. **GIVEN** the requisitioner filters by category `Office Supplies` **WHEN** the filter is applied **THEN** the result list updates without a page reload, showing only items whose product belongs to `OFFICE-SUP` or its children.

3. **GIVEN** the requisitioner clicks "Add to basket" on A4 Copy Paper and enters quantity 10 **WHEN** the item is added **THEN** `OrderBasketPanel` shows the updated item count badge and the running total updates to `EUR 45.00`.

4. **GIVEN** the basket total is EUR 2 500 and the user's ordering limit in AppSettings is EUR 1 000 **WHEN** the requisitioner clicks "Submit order" **THEN** the `OrderBasket.status` is set to `submitted` and an `ApprovalWorkflow` approval request is created for a user with the `approver` CollaborationRole; the requisitioner sees "Your order has been sent for approval."

5. **GIVEN** the requisitioner assigns cost centre `CC-ADMIN-01` and the available budget is EUR 200 but the basket total is EUR 250 **WHEN** the checkout screen renders **THEN** a warning banner "Budget for CC-ADMIN-01 is EUR 200; your order exceeds available budget by EUR 50. Submit anyway to request budget approval." is shown, but the "Submit" button remains enabled.

6. **GIVEN** items are in the basket **WHEN** the requisitioner updates the quantity of A4 Copy Paper from 10 to 5 **THEN** the line total changes from EUR 45.00 to EUR 22.50 and the basket running total recalculates immediately.

### REQ-CAT-004: Purchase Order Management and Supplier Transmission [must]

The app MUST register the `PurchaseOrder` schema (`schema:Order`) and `PurchaseOrderLine` schema (`schema:OrderItem`). PO numbers MUST be auto-generated sequentially (format `PO-{YYYY}-{NNNNN}`). On approval, the PO MUST be transmitted to the supplier via Nextcloud notification and optionally via `IMailer`. Suppliers MUST be able to acknowledge receipt of a PO through the self-service portal, updating `status` to `acknowledged`.

**Scenarios:**

1. **GIVEN** an approved `OrderBasket` is converted to a PO **WHEN** the PO is created **THEN** a `PurchaseOrder` is created with `poNumber: "PO-2026-00001"`, `status: draft`, and one `PurchaseOrderLine` per `OrderBasketLine` with quantity, unit price, and line total populated.

2. **GIVEN** a PO has `status: draft` **WHEN** the procurement manager clicks "Submit to supplier" **THEN** `status` changes to `submitted`, `transmittedAt` is set to the current timestamp, and the supplier's `assignedOfficerId` receives a Nextcloud notification "Purchase Order PO-2026-00001 has been sent to you."

3. **GIVEN** a PO has been transmitted to supplier Acme BV **WHEN** the supplier acknowledges it via the portal **THEN** `PurchaseOrder.status` changes to `acknowledged` and `acknowledgedAt` is set.

4. **GIVEN** a PO is in `submitted` or `acknowledged` status **WHEN** the procurement manager clicks "Cancel" and enters a reason **THEN** `status` changes to `cancelled` and the supplier receives a Nextcloud notification "Purchase Order PO-2026-00001 has been cancelled."

5. **GIVEN** a PO has all lines fully received and a matched invoice **WHEN** the finance controller closes the PO **THEN** `status` changes to `closed` and no further goods receipts can be created against it.

### REQ-CAT-005: Goods Receipt Confirmation and Discrepancy Recording [must]

The app MUST register the `GoodsReceipt` schema (`schema:ReceiveAction`) and `GoodsReceiptLine` schema. Requisitioners MUST be able to enter the actually received quantity per line and add a discrepancy note. On partial receipt, the outstanding quantity MUST remain on the PO line. On save, `ThreeWayMatchingService` MUST update `PurchaseOrderLine.receivedQuantity` and `matchStatus`. The supplier MUST be notified of partial deliveries.

**Scenarios:**

1. **GIVEN** a PO with 2 lines (10 reams, 5 boxes) has been transmitted **WHEN** the requisitioner clicks "Confirm receipt" and enters 10 reams and 3 boxes received **THEN** a `GoodsReceipt` is created with 2 `GoodsReceiptLine` objects; `PurchaseOrderLine` for pens has `receivedQuantity: 3` and `matchStatus: discrepancy`; the supplier receives a notification "Partial delivery received for PO-2026-00001: 2 items outstanding."

2. **GIVEN** a goods receipt has been saved with a partial quantity **WHEN** the finance controller views the PO detail "Matching" tab **THEN** the line shows `ordered: 5`, `received: 3`, `outstanding: 2`, `matchStatus: discrepancy` with the discrepancy note.

3. **GIVEN** all PO lines have `receivedQuantity` equal to `quantity` **WHEN** the final goods receipt is saved **THEN** `PurchaseOrder.status` changes to `received` and `GoodsReceipt.matchStatus` is set to `matched`.

4. **GIVEN** a goods receipt is confirmed **WHEN** the finance controller processes the supplier invoice **THEN** the confirmed receipt quantities are available in the `ThreeWayMatchingPanel` for invoice-line comparison.

5. **GIVEN** `PurchaseOrder.status` is `closed` **WHEN** the requisitioner attempts to create a new `GoodsReceipt` for the same PO **THEN** the API returns 422 with "Cannot create a goods receipt for a closed purchase order."

### REQ-CAT-006: Three-Way Matching [must]

`ThreeWayMatchingService` MUST compare `PurchaseOrderLine.quantity`, `GoodsReceiptLine.receivedQuantity`, and the linked invoice line quantity for each PO line. Lines where all three values match MUST be set to `matchStatus: matched`. Lines where any value differs MUST be set to `matchStatus: discrepancy` and surfaced to the finance controller. The service MUST be idempotent and MUST NOT modify invoice objects.

**Scenarios:**

1. **GIVEN** a PO line has `quantity: 10`, `receivedQuantity: 10`, and the linked invoice line has quantity 10 **WHEN** `ThreeWayMatchingService` runs **THEN** `PurchaseOrderLine.matchStatus` is set to `matched`.

2. **GIVEN** a PO line has `quantity: 10`, `receivedQuantity: 8`, and the linked invoice line has quantity 10 **WHEN** the matching service runs **THEN** `matchStatus` is set to `discrepancy` with a discrepancy reason "Received quantity (8) differs from ordered quantity (10)."

3. **GIVEN** a PO line has `quantity: 10`, `receivedQuantity: 10`, and the linked invoice line has a unit price 10% higher than the PO line unit price **WHEN** the matching service runs **THEN** `matchStatus` is set to `discrepancy` with reason "Invoice unit price (EUR X.XX) differs from PO unit price (EUR Y.YY)."

4. **GIVEN** the matching service has already set `matchStatus: matched` on a line **WHEN** it is called again with the same data **THEN** no change is made to the PO line (idempotent).

5. **GIVEN** all PO lines have `matchStatus: matched` **WHEN** the finance controller views the matching panel **THEN** a green "All lines matched — ready for payment approval" indicator is shown and the "Approve for payment" button is enabled.

### REQ-CAT-007: RFQ Management with Multi-supplier Solicitation and Quote Comparison [must]

The app MUST register the `RFQ` schema (`schema:Quotation`) and `SupplierQuote` schema (`schema:Offer`). Procurement officers MUST be able to publish an RFQ to multiple qualified suppliers, track response status per supplier, and compare submitted quotes side-by-side in a `QuoteComparisonTable` ranked by total price. The RFQ MUST be able to be awarded to a single supplier, creating a new `PurchaseOrder` from the awarded quote.

**Scenarios:**

1. **GIVEN** a procurement officer creates an RFQ "RFQ — IT Hardware Q3 2026" with budget EUR 5 000, type `RFQ`, due date 2026-05-15 **WHEN** the RFQ is saved with `status: draft` **THEN** it appears in the RFQ list; no invitations are sent yet.

2. **GIVEN** the RFQ is in `draft` status **WHEN** the officer selects Acme BV and Beta Supplies BV in the "Invite Suppliers" panel and clicks "Publish & Invite" **THEN** `status` changes to `published`, `publishedAt` is set, both supplier IDs are added to `invitedSupplierProfileIds`, and each supplier's `assignedOfficerId` receives a Nextcloud notification "You have been invited to submit a quote for RFQ-2026-00001."

3. **GIVEN** the RFQ due date has passed and 2 SupplierQuotes have been submitted **WHEN** the officer sets status to `evaluating` **THEN** `QuoteComparisonTable` renders the two quotes side-by-side with columns: supplier name, total amount, validity date, evaluation score, and budget variance (quote total vs RFQ budget).

4. **GIVEN** the comparison table is shown **WHEN** the officer enters evaluation scores and notes for each quote **THEN** the scores are saved to `SupplierQuote.evaluationScore` and `evaluationNotes` in OpenRegister.

5. **GIVEN** the officer selects Acme BV's quote and clicks "Award" **THEN** `RFQ.status` changes to `awarded`, `awardedSupplierProfileId` is set to Acme BV's ID, `awardedAt` is set, the accepted `SupplierQuote.status` changes to `accepted`, the rejected supplier's quote status changes to `rejected`, and a new `PurchaseOrder` draft is generated from the awarded quote lines.

6. **GIVEN** a supplier not in `invitedSupplierProfileIds` attempts to submit a quote via the portal **THEN** the API returns 403 "You have not been invited to respond to this RFQ."

### REQ-CAT-008: Purchase Cycle Tracking and Overdue Delivery Alerting [must]

`PurchaseOrder.status` MUST progress through the full purchase cycle: draft → submitted → acknowledged → partially_received → received → invoiced → closed. The `OverdueDeliveryJob` MUST run daily, identify overdue orders, highlight them in the order list, and notify the requisitioner. Requisitioners MUST be able to send a delivery reminder to the supplier directly from the order detail page.

**Scenarios:**

1. **GIVEN** a PO has `status: acknowledged` and `expectedDeliveryDate: 2026-04-01` **WHEN** the `OverdueDeliveryJob` runs on 2026-04-08 **THEN** the PO is highlighted with an "Overdue" badge in `PurchaseOrderIndex.vue` and the requisitioner (the user identified by `createdBy`) receives a Nextcloud notification "Purchase Order PO-2026-00001 is overdue — expected delivery was 2026-04-01."

2. **GIVEN** the overdue job has already sent a notification for PO-2026-00001 today **WHEN** the job runs again in the same day **THEN** no duplicate notification is sent (deduplication key: `po-overdue-{poNumber}-{date}`).

3. **GIVEN** a PO is overdue **WHEN** the requisitioner clicks "Send delivery reminder" on the order detail page **THEN** a Nextcloud notification is sent to the supplier's `assignedOfficerId` with message "Delivery reminder: Purchase Order PO-2026-00001 is overdue. Please confirm expected delivery date." and the action is logged in the OpenRegister audit trail.

4. **GIVEN** a requisitioner views their order history **THEN** orders are shown with status badges: pending approval (amber), approved (blue), sent to supplier (blue), acknowledged (blue), overdue (red), delivered (green), invoiced (teal), closed (grey).

5. **GIVEN** a PO has been invoiced and all matching is complete **WHEN** the finance controller approves payment **THEN** `PurchaseOrder.status` changes to `invoiced`; once payment is confirmed, it changes to `closed`.

### REQ-CAT-009: Seed Data [must]

The app MUST load demo seed data for all new schemas via the repair step. Seed data MUST be idempotent — running the repair step multiple times MUST NOT create duplicate records.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step runs **THEN** all seed records are created: 4 ProductCategories, 4 Products, 1 Catalog, 4 CatalogItems, 1 PurchaseOrder with 2 PurchaseOrderLines, 1 RFQ with 2 SupplierQuotes.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** no duplicate records are created; idempotency keys are: `ProductCategory.code`, `Product.sku`, `Catalog.name`, `(CatalogItem.catalogId + CatalogItem.productId)`, `PurchaseOrder.poNumber`, `RFQ.number`.

3. **GIVEN** the seed PurchaseOrder PO-2026-00001 is created **WHEN** seed data loads **THEN** its 2 PurchaseOrderLines reference the correct Product object IDs resolved at seed time for `PAPER-A4-80` and `PEN-BLK-10`.

4. **GIVEN** the seed RFQ RFQ-2026-00001 is created **WHEN** seed data loads **THEN** both SupplierQuote objects reference the correct SupplierProfile object IDs for the Acme BV and Beta Supplies BV seed records from the supplier-management change.
