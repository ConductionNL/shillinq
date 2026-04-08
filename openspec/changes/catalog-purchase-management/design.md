# Design: Catalog & Purchase Management — Shillinq

## Architecture Overview

This change adds product and service item management, vendor catalog browsing, order basket checkout, RFQ multi-supplier solicitation, purchase order management, goods receipt confirmation, and three-way matching on top of the core, access-control-authorisation, collaboration, document-management, and supplier-management infrastructure. All new entities are OpenRegister schemas; no custom database tables are introduced. The `ThreeWayMatchingService` and `OverdueDeliveryJob` are the only new PHP components beyond standard CRUD controllers.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (ProductCategory, Product, Catalog, CatalogItem,
    │                          OrderBasket, PurchaseOrder, PurchaseOrderLine,
    │                          GoodsReceipt, GoodsReceiptLine,
    │                          RFQ, SupplierQuote CRUD)
    │
    └─ Shillinq OCS API
            ├─ CatalogController         (search, basket checkout, CSV bulk import)
            ├─ PurchaseOrderController   (submit, acknowledge, cancel, transmit)
            ├─ GoodsReceiptController    (confirm receipt, trigger matching)
            ├─ RFQController             (publish, solicit quotes, award)
            └─ PHP Services
                    ├─ CatalogSearchService
                    ├─ OrderLimitService
                    ├─ ThreeWayMatchingService
                    └─ CatalogImportService
            │
            └─ Background Jobs
                    └─ OverdueDeliveryJob  (daily)
```

## Data Model

### ProductCategory (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Category display name |
| code | string | No | — | Short code for filtering (e.g. IT, OFFICE) |
| description | string | No | — | Category description |
| parentCategoryId | string | No | — | OpenRegister object ID of the parent ProductCategory; null for root nodes |
| active | boolean | No | true | Whether the category is available for use |
| sortOrder | integer | No | 0 | Display order within a parent level |

### Product (`schema:Product`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| sku | string | Yes | — | Stock keeping unit; unique within the register |
| name | string | Yes | — | Product or service display name |
| description | string | No | — | Detailed description |
| unit | string | No | piece | Unit of measure (piece, kg, hour, litre, m², etc.) |
| active | boolean | No | true | Whether the product is available for purchasing |
| categoryId | string | No | — | OpenRegister object ID of the assigned ProductCategory |
| purchasePrice | number | No | — | Default purchase price (may be overridden in CatalogItem) |
| currency | string | No | EUR | ISO 4217 currency code for purchasePrice |
| taxRate | number | No | 21 | Default VAT rate percentage |
| leadTimeDays | integer | No | — | Default supplier lead time in calendar days |
| notes | string | No | — | Internal notes |

### Catalog (`schema:CreativeWork`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Catalog display name |
| description | string | No | — | Catalog scope and purpose |
| status | string | Yes | draft | Enum: draft / active / archived |
| supplierProfileId | string | No | — | OpenRegister object ID of the linked SupplierProfile (single-supplier catalogs) |
| effectiveFrom | datetime | No | — | Date from which the catalog is valid |
| effectiveTo | datetime | No | — | Date after which the catalog expires |
| ownerId | string | No | — | userId of the procurement manager who owns this catalog |
| contractReference | string | No | — | Framework contract or agreement number |

### CatalogItem (`schema:Offer`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| catalogId | string | Yes | — | OpenRegister object ID of the parent Catalog |
| productId | string | Yes | — | OpenRegister object ID of the Product |
| supplierProfileId | string | Yes | — | OpenRegister object ID of the supplying SupplierProfile |
| unitPrice | number | Yes | — | Negotiated unit price |
| currency | string | No | EUR | ISO 4217 currency code |
| minimumOrderQuantity | integer | No | 1 | Minimum order quantity |
| leadTimeDays | integer | No | — | Supplier lead time for this item (overrides Product default) |
| active | boolean | No | true | Whether this item is orderable from this catalog |
| notes | string | No | — | Procurement notes (e.g. "valid through 2026-12-31") |

### OrderBasket (`schema:Order`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| requisitionerId | string | Yes | — | userId of the requisitioner |
| status | string | Yes | open | Enum: open / submitted / approved / rejected |
| costCentreId | string | No | — | OpenRegister object ID of the assigned Budget/cost centre |
| glAccountCode | string | No | — | General ledger account code |
| totalAmount | number | No | 0 | Running total (computed on save) |
| currency | string | No | EUR | ISO 4217 currency code |
| notes | string | No | — | Requisitioner notes |
| submittedAt | datetime | No | — | Timestamp when the basket was submitted for approval |
| approvedBy | string | No | — | userId who approved the requisition |
| approvedAt | datetime | No | — | Timestamp of approval decision |

### OrderBasketLine (`schema:OrderItem`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| basketId | string | Yes | — | OpenRegister object ID of the parent OrderBasket |
| catalogItemId | string | Yes | — | OpenRegister object ID of the CatalogItem |
| quantity | number | Yes | 1 | Ordered quantity |
| unitPrice | number | Yes | — | Unit price at time of adding to basket (snapshot from CatalogItem) |
| lineTotal | number | No | — | quantity × unitPrice (computed on save) |
| currency | string | No | EUR | ISO 4217 currency code |

### PurchaseOrder (`schema:Order`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| poNumber | string | Yes | — | Auto-generated PO number (e.g. PO-2026-00001) |
| supplierProfileId | string | Yes | — | OpenRegister object ID of the SupplierProfile |
| status | string | Yes | draft | Enum: draft / submitted / acknowledged / partially_received / received / invoiced / closed / cancelled |
| orderBasketId | string | No | — | OpenRegister object ID of the originating OrderBasket |
| deliveryAddress | string | No | — | Delivery address text |
| expectedDeliveryDate | datetime | No | — | Expected delivery date |
| transmittedAt | datetime | No | — | Timestamp when PO was sent to supplier |
| acknowledgedAt | datetime | No | — | Timestamp when supplier acknowledged the PO |
| totalAmount | number | No | — | PO total (computed from lines) |
| currency | string | No | EUR | ISO 4217 currency code |
| costCentreId | string | No | — | Budget / cost centre reference |
| createdBy | string | Yes | — | userId who created the PO |
| notes | string | No | — | Internal notes |

### PurchaseOrderLine (`schema:OrderItem`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| purchaseOrderId | string | Yes | — | OpenRegister object ID of the parent PurchaseOrder |
| productId | string | Yes | — | OpenRegister object ID of the Product |
| description | string | No | — | Line description (defaults to Product.name) |
| quantity | number | Yes | — | Ordered quantity |
| unitPrice | number | Yes | — | Agreed unit price |
| lineTotal | number | No | — | quantity × unitPrice (computed on save) |
| currency | string | No | EUR | ISO 4217 currency code |
| matchStatus | string | No | pending | Enum: pending / matched / discrepancy / not_received |
| receivedQuantity | number | No | 0 | Cumulative quantity confirmed received |
| invoicedQuantity | number | No | 0 | Cumulative quantity on matched invoices |

### GoodsReceipt (`schema:ReceiveAction`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| purchaseOrderId | string | Yes | — | OpenRegister object ID of the PurchaseOrder |
| receivedBy | string | Yes | — | userId who confirmed receipt |
| receivedAt | datetime | Yes | — | Timestamp of goods receipt |
| deliveryReference | string | No | — | Supplier delivery note or tracking reference |
| notes | string | No | — | General receipt notes |
| matchStatus | string | No | pending | Enum: pending / matched / discrepancy |

### GoodsReceiptLine (`schema:OrderItem`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| goodsReceiptId | string | Yes | — | OpenRegister object ID of the parent GoodsReceipt |
| purchaseOrderLineId | string | Yes | — | OpenRegister object ID of the PurchaseOrderLine |
| receivedQuantity | number | Yes | — | Actually received quantity |
| discrepancyNote | string | No | — | Note explaining quantity or condition discrepancy |

### RFQ (`schema:Quotation`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| number | string | Yes | — | Unique RFQ number (auto-generated, e.g. RFQ-2026-00001) |
| title | string | Yes | — | RFQ title |
| description | string | No | — | Detailed RFQ description and requirements |
| type | string | No | RFQ | Enum: RFI / RFP / RFQ / Auction |
| status | string | No | draft | Enum: draft / published / evaluating / awarded / closed |
| budget | number | No | — | Budget envelope for this RFQ |
| currency | string | No | EUR | ISO 4217 currency code |
| dueDate | datetime | No | — | Deadline for supplier responses |
| publishedAt | datetime | No | — | Timestamp when published to suppliers |
| awardedSupplierProfileId | string | No | — | OpenRegister object ID of the awarded SupplierProfile |
| awardedAt | datetime | No | — | Timestamp of award |
| createdBy | string | Yes | — | userId of the procurement officer |
| invitedSupplierProfileIds | array | No | [] | Array of SupplierProfile object IDs invited to respond |

### SupplierQuote (`schema:Offer`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| rfqId | string | Yes | — | OpenRegister object ID of the parent RFQ |
| supplierProfileId | string | Yes | — | OpenRegister object ID of the quoting SupplierProfile |
| status | string | Yes | submitted | Enum: submitted / accepted / rejected |
| totalAmount | number | No | — | Supplier's total quoted price |
| currency | string | No | EUR | ISO 4217 currency code |
| validUntil | datetime | No | — | Quote validity deadline |
| notes | string | No | — | Supplier's narrative or conditions |
| submittedAt | datetime | No | — | Timestamp of quote submission |
| evaluationScore | number | No | — | Numeric score assigned during evaluation |
| evaluationNotes | string | No | — | Evaluator's notes |
| evaluatedBy | string | No | — | userId of the evaluator |
| documentIds | array | No | [] | Array of Document object IDs attached to this quote |

## Component Architecture

### PHP Services

| Service | Responsibility |
|---------|---------------|
| `CatalogSearchService` | Full-text search across CatalogItem + Product + Catalog; applies active-catalog and active-product filters; supports category and supplier facets |
| `OrderLimitService` | Reads configurable per-user ordering limit from AppSettings; compares basket total; returns whether the order requires approval routing |
| `ThreeWayMatchingService` | For each PurchaseOrderLine, compares `quantity` vs `receivedQuantity` (from GoodsReceiptLines) and `invoicedQuantity` (from linked invoice lines); sets `matchStatus` accordingly; returns a discrepancy report |
| `CatalogImportService` | Parses CSV upload (Product SKU, unit price, MOQ, lead time); resolves Product by SKU; upserts CatalogItems; returns per-row validation errors |

### Background Jobs

| Job | Schedule | Behaviour |
|-----|----------|-----------|
| `OverdueDeliveryJob` | Daily (ITimedJobList) | Queries PurchaseOrders where `expectedDeliveryDate < now` and `status` is `submitted` or `acknowledged` or `partially_received`; sets an overdue flag in the object metadata; sends Nextcloud notification to the requisitioner; deduplication key prevents duplicate notifications per PO per day |

### Vue Component Structure

```
src/
├── views/
│   ├── productCategory/
│   │   ├── ProductCategoryIndex.vue      (CnIndexPage — tree/flat toggle)
│   │   ├── ProductCategoryDetail.vue     (CnDetailPage — tabs: Details, Products)
│   │   └── ProductCategoryForm.vue       (CnFormDialog)
│   ├── product/
│   │   ├── ProductIndex.vue              (CnIndexPage)
│   │   ├── ProductDetail.vue             (CnDetailPage — tabs: Details, Catalogs, Orders)
│   │   └── ProductForm.vue               (CnFormDialog)
│   ├── catalog/
│   │   ├── CatalogIndex.vue              (CnIndexPage)
│   │   ├── CatalogDetail.vue             (CnDetailPage — tabs: Details, Items, Import)
│   │   ├── CatalogForm.vue               (CnFormDialog)
│   │   └── CatalogSearch.vue             (requisitioner-facing search + basket)
│   ├── catalogItem/
│   │   ├── CatalogItemIndex.vue
│   │   ├── CatalogItemDetail.vue
│   │   └── CatalogItemForm.vue
│   ├── orderBasket/
│   │   ├── OrderBasketView.vue           (checkout flow — basket + cost centre + submit)
│   │   └── OrderBasketHistory.vue        (requisitioner order history with status)
│   ├── purchaseOrder/
│   │   ├── PurchaseOrderIndex.vue        (CnIndexPage — overdue badge integration)
│   │   ├── PurchaseOrderDetail.vue       (CnDetailPage — tabs: Details, Lines, Receipts, Matching, Documents)
│   │   └── PurchaseOrderForm.vue         (CnFormDialog)
│   ├── goodsReceipt/
│   │   ├── GoodsReceiptIndex.vue
│   │   ├── GoodsReceiptDetail.vue
│   │   └── GoodsReceiptForm.vue          (per-line received quantity entry)
│   ├── rFQ/
│   │   ├── RFQIndex.vue                  (CnIndexPage)
│   │   ├── RFQDetail.vue                 (CnDetailPage — tabs: Details, Suppliers, Quotes, Comparison)
│   │   └── RFQForm.vue                   (CnFormDialog)
│   ├── supplierQuote/
│   │   ├── SupplierQuoteIndex.vue
│   │   ├── SupplierQuoteDetail.vue
│   │   └── SupplierQuoteForm.vue
│   └── supplier/
│       ├── SupplierIndex.vue             (CnIndexPage — read-only supplier selector)
│       └── SupplierDetail.vue            (CnDetailPage — read-only supplier summary)
├── components/
│   ├── OrderBasketPanel.vue              (floating basket widget, item count badge)
│   ├── ThreeWayMatchingPanel.vue         (match status table embedded in PO detail)
│   ├── QuoteComparisonTable.vue          (side-by-side quote table for RFQ detail)
│   └── CatalogImportPanel.vue            (CSV upload + validation error display)
└── store/modules/
    ├── productCategory.js                (createObjectStore('productCategory'))
    ├── product.js                        (createObjectStore('product'))
    ├── catalog.js                        (createObjectStore('catalog'))
    ├── catalogItem.js                    (createObjectStore('catalogItem'))
    ├── orderBasket.js                    (createObjectStore('orderBasket'))
    ├── purchaseOrder.js                  (createObjectStore('purchaseOrder'))
    ├── goodsReceipt.js                   (createObjectStore('goodsReceipt'))
    ├── rFQ.js                            (createObjectStore('rFQ'))
    ├── supplierQuote.js                  (createObjectStore('supplierQuote'))
    └── supplier.js                       (createObjectStore('supplierProfile') — alias)
```

## Seed Data (ADR-016)

### ProductCategory seed objects

| name | code | parentCategoryId | active |
|------|------|-----------------|--------|
| Office & Facilities | OFFICE | — (root) | true |
| IT & Electronics | IT | — (root) | true |
| Office Supplies | OFFICE-SUP | Office & Facilities | true |
| Computer Hardware | IT-HW | IT & Electronics | true |

### Product seed objects

| sku | name | unit | categoryCode | purchasePrice | taxRate |
|-----|------|------|--------------|---------------|---------|
| `PAPER-A4-80` | A4 Copy Paper 80gsm (500 sheets) | ream | OFFICE-SUP | 4.50 | 21 |
| `PEN-BLK-10` | Black Ballpoint Pen (box of 10) | box | OFFICE-SUP | 3.20 | 21 |
| `LAPTOP-15-STD` | Standard 15" Business Laptop | piece | IT-HW | 850.00 | 21 |
| `MOUSE-USB-STD` | USB Optical Mouse | piece | IT-HW | 15.00 | 21 |

### Catalog seed object

| Field | Value |
|-------|-------|
| name | Office Essentials 2026 |
| status | `active` |
| effectiveFrom | 2026-01-01 |
| effectiveTo | 2026-12-31 |
| contractReference | FW-2026-OFFICE |

With 4 CatalogItems linking the seed products to the catalog at the seed purchase prices, linked to the Acme BV SupplierProfile seed from the supplier-management change.

### PurchaseOrder seed object

| Field | Value |
|-------|-------|
| poNumber | PO-2026-00001 |
| status | `acknowledged` |
| expectedDeliveryDate | 2026-04-30 |
| totalAmount | 50.00 |
| currency | EUR |

With 2 PurchaseOrderLines: 5 reams of PAPER-A4-80 (EUR 22.50) and 5 boxes of PEN-BLK-10 (EUR 16.00). No GoodsReceipt yet (to exercise overdue delivery state).

### RFQ seed object

| Field | Value |
|-------|-------|
| number | RFQ-2026-00001 |
| title | RFQ — IT Hardware Q3 2026 |
| type | `RFQ` |
| status | `evaluating` |
| budget | 5000.00 |
| dueDate | 2026-05-15 |

With 2 SupplierQuote objects: Acme BV (EUR 4 200, status `submitted`) and Beta Supplies BV (EUR 4 650, status `submitted`).

## Security Considerations

- Catalog search returns only items from catalogs with `status: active` and `effectiveFrom ≤ today ≤ effectiveTo`; no draft or archived catalog items are exposed to requisitioners
- OrderBasket `requisitionerId` is always set server-side to the authenticated userId; clients cannot set it to another user
- PO transmission uses Nextcloud's `INotifier` and `IMailer`; supplier email is resolved server-side from `SupplierProfile`; the frontend never receives a raw email address
- `ThreeWayMatchingService` is read-only and idempotent; it never modifies invoice objects; it only updates `matchStatus` on PurchaseOrderLine
- CSV bulk import validates each row server-side; invalid rows are returned to the user with line numbers; no partial import is committed if the schema header row is malformed
- RFQ quote visibility: only the procurement officer who created the RFQ and users with `reviewer` CollaborationRole can see submitted quotes; suppliers cannot see each other's quotes
