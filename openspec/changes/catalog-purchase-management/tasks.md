# Tasks: catalog-purchase-management

## 1. OpenRegister Schema Definitions

- [ ] 1.1 Add `productCategory` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `productCategory` MUST be registered with `name` (required), `code`, `description`, `parentCategoryId`, `active` (boolean, default true), `sortOrder` (integer, default 0)
    - AND `x-schema-org` annotation MUST be `schema:Thing`

- [ ] 1.2 Add `product` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `product` MUST exist with `sku` (required), `name` (required), `description`, `unit` (default "piece"), `active` (boolean, default true), `categoryId`, `purchasePrice` (number), `currency` (default "EUR"), `taxRate` (number, default 21), `leadTimeDays` (integer), `notes`
    - AND `x-schema-org` MUST be `schema:Product`

- [ ] 1.3 Add `catalog` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `catalog` MUST exist with `name` (required), `description`, `status` (required, enum `["draft","active","archived"]`, default "draft"), `supplierProfileId`, `effectiveFrom` (datetime), `effectiveTo` (datetime), `ownerId`, `contractReference`
    - AND `x-schema-org` MUST be `schema:CreativeWork`

- [ ] 1.4 Add `catalogItem` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `catalogItem` MUST exist with `catalogId` (required), `productId` (required), `supplierProfileId` (required), `unitPrice` (required, number), `currency` (default "EUR"), `minimumOrderQuantity` (integer, default 1), `leadTimeDays` (integer), `active` (boolean, default true), `notes`
    - AND `x-schema-org` MUST be `schema:Offer`

- [ ] 1.5 Add `orderBasket` and `orderBasketLine` schemas to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-003`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `orderBasket` MUST exist with `requisitionerId` (required), `status` (required, enum `["open","submitted","approved","rejected"]`, default "open"), `costCentreId`, `glAccountCode`, `totalAmount` (number, default 0), `currency` (default "EUR"), `notes`, `submittedAt` (datetime), `approvedBy`, `approvedAt` (datetime)
    - AND schema `orderBasketLine` MUST exist with `basketId` (required), `catalogItemId` (required), `quantity` (required, number), `unitPrice` (required, number), `lineTotal` (number), `currency` (default "EUR")
    - AND both MUST have `x-schema-org: schema:Order` and `schema:OrderItem` respectively

- [ ] 1.6 Add `purchaseOrder` and `purchaseOrderLine` schemas to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-004`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `purchaseOrder` MUST exist with `poNumber` (required), `supplierProfileId` (required), `status` (required, enum `["draft","submitted","acknowledged","partially_received","received","invoiced","closed","cancelled"]`, default "draft"), `orderBasketId`, `deliveryAddress`, `expectedDeliveryDate` (datetime), `transmittedAt` (datetime), `acknowledgedAt` (datetime), `totalAmount` (number), `currency` (default "EUR"), `costCentreId`, `createdBy` (required), `notes`
    - AND schema `purchaseOrderLine` MUST exist with `purchaseOrderId` (required), `productId` (required), `description`, `quantity` (required, number), `unitPrice` (required, number), `lineTotal` (number), `currency` (default "EUR"), `matchStatus` (enum `["pending","matched","discrepancy","not_received"]`, default "pending"), `receivedQuantity` (number, default 0), `invoicedQuantity` (number, default 0)
    - AND `x-schema-org` MUST be `schema:Order` and `schema:OrderItem` respectively

- [ ] 1.7 Add `goodsReceipt` and `goodsReceiptLine` schemas to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-005`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `goodsReceipt` MUST exist with `purchaseOrderId` (required), `receivedBy` (required), `receivedAt` (required, datetime), `deliveryReference`, `notes`, `matchStatus` (enum `["pending","matched","discrepancy"]`, default "pending")
    - AND schema `goodsReceiptLine` MUST exist with `goodsReceiptId` (required), `purchaseOrderLineId` (required), `receivedQuantity` (required, number), `discrepancyNote`
    - AND `x-schema-org` MUST be `schema:ReceiveAction` and `schema:OrderItem` respectively

- [ ] 1.8 Add `rFQ` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `rFQ` MUST exist with `number` (required), `title` (required), `description`, `type` (enum `["RFI","RFP","RFQ","Auction"]`, default "RFQ"), `status` (enum `["draft","published","evaluating","awarded","closed"]`, default "draft"), `budget` (number), `currency` (default "EUR"), `dueDate` (datetime), `publishedAt` (datetime), `awardedSupplierProfileId`, `awardedAt` (datetime), `createdBy` (required), `invitedSupplierProfileIds` (array, default [])
    - AND `x-schema-org` MUST be `schema:Quotation`

- [ ] 1.9 Add `supplierQuote` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-007`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - THEN schema `supplierQuote` MUST exist with `rfqId` (required), `supplierProfileId` (required), `status` (required, enum `["submitted","accepted","rejected"]`, default "submitted"), `totalAmount` (number), `currency` (default "EUR"), `validUntil` (datetime), `notes`, `submittedAt` (datetime), `evaluationScore` (number), `evaluationNotes`, `evaluatedBy`, `documentIds` (array, default [])
    - AND `x-schema-org` MUST be `schema:Offer`

## 2. Seed Data

- [ ] 2.1 Add ProductCategory and Product seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 4 ProductCategory objects MUST be created: "Office & Facilities" (OFFICE, root), "IT & Electronics" (IT, root), "Office Supplies" (OFFICE-SUP, child of OFFICE), "Computer Hardware" (IT-HW, child of IT)
    - AND 4 Product objects MUST be created: PAPER-A4-80, PEN-BLK-10, LAPTOP-15-STD, MOUSE-USB-STD with the prices, units, and tax rates from the seed data table
    - AND idempotency key for ProductCategory MUST be `code`; for Product MUST be `sku`

- [ ] 2.2 Add Catalog and CatalogItem seed objects to repair step
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 Catalog object MUST be created: "Office Essentials 2026" (active, effectiveFrom 2026-01-01, effectiveTo 2026-12-31, contractReference FW-2026-OFFICE)
    - AND 4 CatalogItem objects MUST be created linking each seed product to the catalog at seed purchase prices, linked to Acme BV SupplierProfile from supplier-management seed
    - AND idempotency key for CatalogItem MUST be composite `(catalogId, productId)`

- [ ] 2.3 Add PurchaseOrder seed object to repair step
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 PurchaseOrder PO-2026-00001 MUST be created (status: acknowledged, expectedDeliveryDate: 2026-04-30, totalAmount: 50.00 EUR)
    - AND 2 PurchaseOrderLines MUST be created: 5 reams PAPER-A4-80 (EUR 22.50) and 5 boxes PEN-BLK-10 (EUR 16.00), referencing Product IDs resolved at seed time
    - AND idempotency key for PurchaseOrder MUST be `poNumber`

- [ ] 2.4 Add RFQ and SupplierQuote seed objects to repair step
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-009`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - THEN 1 RFQ RFQ-2026-00001 MUST be created: "RFQ — IT Hardware Q3 2026" (type: RFQ, status: evaluating, budget: 5000.00, dueDate: 2026-05-15)
    - AND 2 SupplierQuote objects MUST be created: Acme BV (EUR 4200, submitted) and Beta Supplies BV (EUR 4650, submitted), referencing SupplierProfile IDs from supplier-management seed
    - AND idempotency key for RFQ MUST be `number`; for SupplierQuote MUST be composite `(rfqId, supplierProfileId)`

## 3. Backend Services

- [ ] 3.1 Create `lib/Service/CatalogSearchService.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002, #REQ-CAT-003`
  - **files**: `lib/Service/CatalogSearchService.php`
  - **acceptance_criteria**:
    - GIVEN a search term and optional `categoryId` filter are passed
    - THEN the service queries `CatalogItem` objects via OpenRegister, joining `Product` and `Catalog` metadata
    - AND only items from catalogs with `status: active` AND `effectiveFrom ≤ today ≤ effectiveTo` are returned
    - AND only items where the linked `Product.active` is `true` are returned
    - AND category filter applies to the product's `categoryId` AND all descendant category IDs (resolved via `ProductCategory.parentCategoryId` traversal)

- [ ] 3.2 Create `lib/Service/OrderLimitService.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-003`
  - **files**: `lib/Service/OrderLimitService.php`
  - **acceptance_criteria**:
    - GIVEN a userId and basket total are passed
    - THEN the service reads the per-user ordering limit from AppSettings key `ordering.limitEur.{userId}`, falling back to the global key `ordering.limitEur.default`
    - AND returns a boolean `requiresApproval` and the applicable limit amount
    - AND if no limit is configured, `requiresApproval` is always false

- [ ] 3.3 Create `lib/Service/ThreeWayMatchingService.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-006`
  - **files**: `lib/Service/ThreeWayMatchingService.php`
  - **acceptance_criteria**:
    - GIVEN a `PurchaseOrder` object ID is passed
    - THEN for each `PurchaseOrderLine`, the service fetches all `GoodsReceiptLine` objects with matching `purchaseOrderLineId` and sums `receivedQuantity`
    - AND fetches all linked invoice lines (via OpenRegister relation from Account Payable invoice objects) and sums `invoicedQuantity`
    - AND sets `matchStatus: matched` when ordered = received = invoiced
    - AND sets `matchStatus: discrepancy` with a structured discrepancy reason string when any value differs
    - AND MUST NOT modify any invoice or goods receipt objects; only `PurchaseOrderLine.matchStatus`, `receivedQuantity`, and `invoicedQuantity` are updated
    - AND the service is idempotent: calling it twice with unchanged data produces no further updates

- [ ] 3.4 Create `lib/Service/CatalogImportService.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002`
  - **files**: `lib/Service/CatalogImportService.php`
  - **acceptance_criteria**:
    - GIVEN a CSV stream and a catalogId are passed
    - THEN the service validates the header row MUST contain at minimum `sku` and `unit_price` columns; if missing, returns a top-level error and aborts
    - AND for each data row, resolves the Product by `sku`; if not found, records a per-row error without aborting remaining rows
    - AND for valid rows, upserts a `CatalogItem` (creates if `(catalogId, productId)` not found; updates `unitPrice`, `minimumOrderQuantity`, `leadTimeDays` if found)
    - AND returns `{imported: N, errors: [{row, sku, message}]}`
    - AND no partial transaction is committed if fewer than 1 valid row exists

## 4. Background Jobs

- [ ] 4.1 Create `lib/BackgroundJob/OverdueDeliveryJob.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-008`
  - **files**: `lib/BackgroundJob/OverdueDeliveryJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the job is registered via `ITimedJobList` with an interval of 86400 seconds (daily)
    - WHEN the job runs THEN it queries all PurchaseOrder objects where `expectedDeliveryDate < now` AND `status` is one of `["submitted","acknowledged","partially_received"]`
    - AND for each matching PO: sends a Nextcloud notification to the user identified by `PurchaseOrder.createdBy` with message "Purchase Order {poNumber} is overdue — expected delivery was {date}"
    - AND deduplication key format: `po-overdue-{poNumber}-{YYYY-MM-DD}` prevents duplicate notifications per PO per calendar day
    - AND the job is registered in `Application.php` via `ITimedJobList`

## 5. Backend Controllers

- [ ] 5.1 Create `lib/Controller/CatalogController.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002, #REQ-CAT-003`
  - **files**: `lib/Controller/CatalogController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/v1/catalog/search?q={term}&categoryId={id}` is called
    - THEN `CatalogSearchService::search()` is called and results are returned as JSON with `productName`, `supplierName`, `unitPrice`, `currency`, `catalogName`, `contractReference`
    - GIVEN `POST /api/v1/catalogs/{id}/import` is called with a multipart CSV file
    - THEN `CatalogImportService::import()` is called; 400 returned if header row is invalid; 200 with import summary otherwise
    - GIVEN `POST /api/v1/catalogs/{id}/import` is called for an archived catalog
    - THEN 422 is returned with "Cannot add items to an archived catalog."

- [ ] 5.2 Create `lib/Controller/OrderBasketController.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-003`
  - **files**: `lib/Controller/OrderBasketController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/order-baskets/{id}/submit` is called
    - THEN `OrderLimitService::check()` is called; if `requiresApproval` is true, an `ApprovalWorkflow` record is created and `OrderBasket.status` is set to `submitted`; otherwise the basket is auto-approved and a `PurchaseOrder` draft is created directly
    - AND `OrderBasket.requisitionerId` MUST always be set server-side to the authenticated userId; any client-supplied value is ignored
    - AND the budget check against the `Budget` entity for the selected `costCentreId` is performed; if available budget is insufficient, the response includes `{budgetWarning: true, deficit: N}` alongside the 200 success response

- [ ] 5.3 Create `lib/Controller/PurchaseOrderController.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-004`
  - **files**: `lib/Controller/PurchaseOrderController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/purchase-orders/{id}/submit` is called
    - THEN `PurchaseOrder.status` changes to `submitted`, `transmittedAt` is set, and a Nextcloud notification is sent to the supplier's `assignedOfficerId`
    - GIVEN `POST /api/v1/purchase-orders/{id}/cancel` is called with `{reason: "..."}` body
    - THEN reason is required; 422 if empty; status changes to `cancelled` and supplier is notified
    - GIVEN a new PO is created THEN `poNumber` is auto-generated in format `PO-{YYYY}-{NNNNN}` using the highest existing sequence number for the current year + 1

- [ ] 5.4 Create `lib/Controller/GoodsReceiptController.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-005`
  - **files**: `lib/Controller/GoodsReceiptController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/goods-receipts` is called with a GoodsReceipt and array of GoodsReceiptLine objects
    - THEN GoodsReceipt and all lines are created in OpenRegister
    - AND `ThreeWayMatchingService::match()` is called for the PO; updated `matchStatus` values are returned in the response
    - AND if received quantities are less than ordered for any line, `PurchaseOrder.status` changes to `partially_received` and the supplier receives a partial delivery notification
    - AND if all lines are fully received, `PurchaseOrder.status` changes to `received`
    - GIVEN the PO has `status: closed` THEN 422 is returned with "Cannot create a goods receipt for a closed purchase order."

- [ ] 5.5 Create `lib/Controller/RFQController.php`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-007`
  - **files**: `lib/Controller/RFQController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/v1/rfqs/{id}/publish` is called with `{supplierProfileIds: [...]}` body
    - THEN `RFQ.status` changes to `published`, `publishedAt` is set, `invitedSupplierProfileIds` is updated, and a Nextcloud notification is sent to each supplier's `assignedOfficerId`
    - GIVEN `POST /api/v1/rfqs/{id}/award` is called with `{supplierQuoteId: "..."}` body
    - THEN `RFQ.status` changes to `awarded`, the selected `SupplierQuote.status` changes to `accepted`, all other quotes change to `rejected`, `awardedSupplierProfileId` and `awardedAt` are set, and a `PurchaseOrder` draft is created from the awarded quote's `totalAmount` and `supplierProfileId`
    - GIVEN a supplier not in `invitedSupplierProfileIds` attempts to submit a quote THEN 403 is returned

## 6. Pinia Stores

- [ ] 6.1 Create `src/store/modules/productCategory.js`
  - **files**: `src/store/modules/productCategory.js`
  - **acceptance_criteria**:
    - THEN `useProductCategoryStore` MUST be created via `createObjectStore('productCategory')` and registered in `src/store/store.js`

- [ ] 6.2 Create `src/store/modules/product.js`
  - **files**: `src/store/modules/product.js`
  - **acceptance_criteria**:
    - THEN `useProductStore` MUST be created via `createObjectStore('product')` and registered in `src/store/store.js`

- [ ] 6.3 Create `src/store/modules/catalog.js`
  - **files**: `src/store/modules/catalog.js`
  - **acceptance_criteria**:
    - THEN `useCatalogStore` MUST be created via `createObjectStore('catalog')` and registered in `src/store/store.js`

- [ ] 6.4 Create `src/store/modules/catalogItem.js`
  - **files**: `src/store/modules/catalogItem.js`
  - **acceptance_criteria**:
    - THEN `useCatalogItemStore` MUST be created via `createObjectStore('catalogItem')` and registered in `src/store/store.js`

- [ ] 6.5 Create `src/store/modules/orderBasket.js`
  - **files**: `src/store/modules/orderBasket.js`
  - **acceptance_criteria**:
    - THEN `useOrderBasketStore` MUST be created via `createObjectStore('orderBasket')` and registered in `src/store/store.js`

- [ ] 6.6 Create `src/store/modules/purchaseOrder.js`
  - **files**: `src/store/modules/purchaseOrder.js`
  - **acceptance_criteria**:
    - THEN `usePurchaseOrderStore` MUST be created via `createObjectStore('purchaseOrder')` and registered in `src/store/store.js`

- [ ] 6.7 Create `src/store/modules/goodsReceipt.js`
  - **files**: `src/store/modules/goodsReceipt.js`
  - **acceptance_criteria**:
    - THEN `useGoodsReceiptStore` MUST be created via `createObjectStore('goodsReceipt')` and registered in `src/store/store.js`

- [ ] 6.8 Create `src/store/modules/rFQ.js`
  - **files**: `src/store/modules/rFQ.js`
  - **acceptance_criteria**:
    - THEN `useRFQStore` MUST be created via `createObjectStore('rFQ')` and registered in `src/store/store.js`

- [ ] 6.9 Create `src/store/modules/supplierQuote.js`
  - **files**: `src/store/modules/supplierQuote.js`
  - **acceptance_criteria**:
    - THEN `useSupplierQuoteStore` MUST be created via `createObjectStore('supplierQuote')` and registered in `src/store/store.js`

- [ ] 6.10 Create `src/store/modules/supplier.js`
  - **files**: `src/store/modules/supplier.js`
  - **acceptance_criteria**:
    - THEN `useSupplierStore` MUST be created via `createObjectStore('supplierProfile')` (referencing the supplier-management schema) and registered in `src/store/store.js`

## 7. Frontend Views — Product and Category

- [ ] 7.1 Create `src/views/productCategory/ProductCategoryIndex.vue`, `ProductCategoryDetail.vue`, `ProductCategoryForm.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-001`
  - **files**: `src/views/productCategory/ProductCategoryIndex.vue`, `src/views/productCategory/ProductCategoryDetail.vue`, `src/views/productCategory/ProductCategoryForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index renders THEN it uses `CnIndexPage` with `columnsFromSchema('productCategory')` and a tree/flat view toggle
    - GIVEN the detail renders THEN it uses `CnDetailPage` with tabs: Details, Products (listing `Product` objects where `categoryId` matches)
    - GIVEN the form opens THEN `parentCategoryId` uses a dropdown populated from existing categories

- [ ] 7.2 Create `src/views/product/ProductIndex.vue`, `ProductDetail.vue`, `ProductForm.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-001`
  - **files**: `src/views/product/ProductIndex.vue`, `src/views/product/ProductDetail.vue`, `src/views/product/ProductForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index renders THEN it uses `CnIndexPage` with filter chips for `active` and `categoryId` via `filtersFromSchema('product')`
    - GIVEN the detail renders THEN it uses `CnDetailPage` with tabs: Details, Catalogs (CatalogItems for this product), Orders (PurchaseOrderLines referencing this product)
    - GIVEN the form opens THEN it uses `CnFormDialog` with `fieldsFromSchema('product')`; SKU field shows a uniqueness error inline if a duplicate is detected on blur

## 8. Frontend Views — Catalog

- [ ] 8.1 Create `src/views/catalog/CatalogIndex.vue`, `CatalogDetail.vue`, `CatalogForm.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002`
  - **files**: `src/views/catalog/CatalogIndex.vue`, `src/views/catalog/CatalogDetail.vue`, `src/views/catalog/CatalogForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index renders THEN it uses `CnIndexPage` with filter chips for `status`
    - GIVEN the detail renders THEN it uses `CnDetailPage` with tabs: Details, Items (CatalogItem list), Import (CSV upload panel via `CatalogImportPanel.vue`)
    - AND status transition buttons (Activate, Archive) MUST be shown per permitted roles; archived catalogs MUST show a read-only banner "This catalog is archived"

- [ ] 8.2 Create `src/views/catalog/CatalogSearch.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-003`
  - **files**: `src/views/catalog/CatalogSearch.vue`
  - **acceptance_criteria**:
    - GIVEN the page loads THEN a search input and category filter (populated from active ProductCategories) are shown
    - GIVEN the requisitioner types at least 2 characters THEN `GET /api/v1/catalog/search` is called with debounce (300 ms) and results render without a full page reload
    - AND each result row shows: product name, supplier name, unit price + currency, catalog name, contract reference, and an "Add to basket" button with a quantity input
    - AND clicking "Add to basket" calls `useOrderBasketStore.addLine()` and updates the `OrderBasketPanel` badge count

- [ ] 8.3 Create `src/components/CatalogImportPanel.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-002`
  - **files**: `src/components/CatalogImportPanel.vue`
  - **acceptance_criteria**:
    - GIVEN the panel renders THEN a file input accepting `.csv` files is shown with a "Download template" link that triggers a client-side CSV template download
    - GIVEN a file is selected and "Import" is clicked THEN `POST /api/v1/catalogs/{id}/import` is called; on success the imported count is shown; per-row errors are displayed in a table with columns: row number, SKU, error message

## 9. Frontend Views — Order Basket

- [ ] 9.1 Create `src/views/orderBasket/OrderBasketView.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-003`
  - **files**: `src/views/orderBasket/OrderBasketView.vue`
  - **acceptance_criteria**:
    - GIVEN the basket view renders THEN all `OrderBasketLine` objects for the current user's open basket are shown with product name, quantity input, unit price, and line total
    - GIVEN the quantity of a line is updated THEN the line total and basket running total recalculate immediately (client-side)
    - GIVEN the cost centre selector is used THEN only `Budget` objects the authenticated user is authorised to charge are shown
    - GIVEN submit is clicked THEN `POST /api/v1/order-baskets/{id}/submit` is called; if `budgetWarning: true` is returned, the warning banner is shown before the success message; if `requiresApproval: true`, the confirmation message reads "Your order has been sent for approval."

- [ ] 9.2 Create `src/views/orderBasket/OrderBasketHistory.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-008`
  - **files**: `src/views/orderBasket/OrderBasketHistory.vue`
  - **acceptance_criteria**:
    - GIVEN the page renders THEN all `OrderBasket` objects for the current user where `status` is not `open` are listed in reverse chronological order of `submittedAt`
    - AND linked `PurchaseOrder.status` is shown per order: pending approval (amber), approved (blue), sent to supplier (blue), acknowledged (blue), overdue (red), delivered (green), invoiced (teal), closed (grey)
    - AND an "overdue" badge is shown for linked PurchaseOrders meeting the overdue criteria

- [ ] 9.3 Create `src/components/OrderBasketPanel.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-003`
  - **files**: `src/components/OrderBasketPanel.vue`
  - **acceptance_criteria**:
    - GIVEN any catalog or requisitioner view renders THEN `OrderBasketPanel` is visible as a persistent floating widget showing the item count badge and current basket total
    - GIVEN the panel is clicked THEN it expands to show a summary of basket lines and a "Go to basket" link navigating to `OrderBasketView.vue`

## 10. Frontend Views — Purchase Order

- [ ] 10.1 Create `src/views/purchaseOrder/PurchaseOrderIndex.vue`, `PurchaseOrderDetail.vue`, `PurchaseOrderForm.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-004, #REQ-CAT-008`
  - **files**: `src/views/purchaseOrder/PurchaseOrderIndex.vue`, `src/views/purchaseOrder/PurchaseOrderDetail.vue`, `src/views/purchaseOrder/PurchaseOrderForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index renders THEN it uses `CnIndexPage` with filter chips for `status`; overdue POs MUST be highlighted with a red "Overdue" NL Design System badge
    - GIVEN the detail renders THEN it uses `CnDetailPage` with tabs: Details, Lines, Receipts, Matching, Documents
    - AND the Details tab shows status transition buttons: "Submit to Supplier" (draft → submitted), "Cancel" (submitted/acknowledged → cancelled), "Close" (received/invoiced → closed)
    - AND the Matching tab embeds `ThreeWayMatchingPanel.vue`
    - AND an overdue PO's detail page shows a "Send delivery reminder" button that calls `POST /api/v1/purchase-orders/{id}/send-reminder`

## 11. Frontend Views — Goods Receipt

- [ ] 11.1 Create `src/views/goodsReceipt/GoodsReceiptIndex.vue`, `GoodsReceiptDetail.vue`, `GoodsReceiptForm.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-005`
  - **files**: `src/views/goodsReceipt/GoodsReceiptIndex.vue`, `src/views/goodsReceipt/GoodsReceiptDetail.vue`, `src/views/goodsReceipt/GoodsReceiptForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form opens for a PO THEN it pre-populates one row per `PurchaseOrderLine` with `orderedQuantity` shown read-only and an editable `receivedQuantity` input and `discrepancyNote` text field
    - AND a "Received quantity" validation MUST prevent values greater than the outstanding quantity (ordered − previously received) with message "Received quantity cannot exceed outstanding quantity of {N}"
    - GIVEN the form is submitted THEN `POST /api/v1/goods-receipts` is called; updated `matchStatus` values per line are reflected in the PO detail "Matching" tab immediately

- [ ] 11.2 Create `src/components/ThreeWayMatchingPanel.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-006`
  - **files**: `src/components/ThreeWayMatchingPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a `purchaseOrderId` prop is passed THEN the panel fetches all `PurchaseOrderLine` objects and renders a table with columns: product name, ordered qty, received qty, invoiced qty, match status chip
    - AND rows with `matchStatus: discrepancy` are highlighted with an amber NL Design System alert colour token and the discrepancy reason is shown on expand
    - AND rows with `matchStatus: matched` show a green chip
    - AND when all rows are `matched` a green "All lines matched — ready for payment approval" banner is shown and an "Approve for payment" button is rendered for users with `approver` CollaborationRole

## 12. Frontend Views — RFQ

- [ ] 12.1 Create `src/views/rFQ/RFQIndex.vue`, `RFQDetail.vue`, `RFQForm.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-007`
  - **files**: `src/views/rFQ/RFQIndex.vue`, `src/views/rFQ/RFQDetail.vue`, `src/views/rFQ/RFQForm.vue`
  - **acceptance_criteria**:
    - GIVEN the index renders THEN it uses `CnIndexPage` with filter chips for `type` and `status`
    - GIVEN the detail renders THEN it uses `CnDetailPage` with tabs: Details, Suppliers, Quotes, Comparison
    - AND the Suppliers tab lists invited suppliers with status per `invitedSupplierProfileIds`; a supplier search (qualified suppliers only) with "Publish & Invite" button calls `POST /api/v1/rfqs/{id}/publish`
    - AND the Comparison tab renders `QuoteComparisonTable.vue`
    - AND an "Award" button on the Comparison tab calls `POST /api/v1/rfqs/{id}/award` with the selected quote ID; confirmation dialog shows "Awarding to {supplierName} will reject all other quotes. Continue?"

- [ ] 12.2 Create `src/components/QuoteComparisonTable.vue`
  - **spec_ref**: `specs/catalog-purchase-management/spec.md#REQ-CAT-007`
  - **files**: `src/components/QuoteComparisonTable.vue`
  - **acceptance_criteria**:
    - GIVEN an `rfqId` prop is passed THEN all `SupplierQuote` objects for the RFQ are fetched and rendered in a sortable table ranked by `totalAmount` ascending
    - AND columns MUST include: supplier name, total amount, currency, validity date, evaluation score (editable inline), budget variance (quote total minus `RFQ.budget`, shown as +/− EUR amount with red/green colouring), status chip
    - AND evaluation score changes trigger a PATCH to the `SupplierQuote` object via `useSupplierQuoteStore`
    - AND the lowest-priced quote row is highlighted with a blue NL Design System "Best price" badge

## 13. Sidebar Navigation Update

- [ ] 13.1 Add catalog and procurement sections to `src/components/ShillinqSidebar.vue`
  - **files**: `src/components/ShillinqSidebar.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders THEN a "Catalog" section MUST be present with nav items: Catalog Search, My Basket, My Orders, Catalogs, Products, Categories
    - AND a "Procurement" section MUST be present with nav items: Purchase Orders, Goods Receipts, RFQ
    - AND each nav item MUST show a badge count from OpenRegister object counts for the relevant schema
    - AND "My Basket" nav item shows the current user's open basket item count badge updated reactively via `useOrderBasketStore`
