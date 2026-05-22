# Design: Accounts Receivable (Core)

## Overview

Shillinq's AR capability enables customer invoicing, dunning workflows, aging analysis, and payment matching. This design formalises three core registers (`CustomerMaster`, `ARInvoice`, `DunningRecord`) and their lifecycles, aggregations, and UI surfaces (list pages, aging report, dunning log).

## Features & Scenarios

### Feature 1: Aged Receivables Report with Customer-Level Overdue Tracking

**Demand Score:** 167 (49 tender mentions) | **Category:** analytics

**Scenario 1:** View outstanding receivables by aging bucket  
GIVEN an administration with issued AR invoices in various aging periods (0–30, 31–60, 61–90, 90+ days overdue)  
WHEN the finance officer opens the AR Aging report  
THEN they see a table grouped by customer, with outstanding amount and days overdue per bucket, sorted by bucket (overdue first).

**Scenario 2:** Filter AR aging by customer  
GIVEN the AR Aging report is open  
WHEN the operator filters by customer name or customer code  
THEN the table updates to show only that customer's invoices by aging bucket.

### Feature 2: Accounts Aging Report Showing Overdue Invoices by Time Period

**Demand Score:** 125 (39 tender mentions) | **Category:** analytics

**Scenario 3:** View aging buckets (0–30, 31–60, 61–90, 90+)  
GIVEN AR invoices in different aging periods  
WHEN the finance officer views the aging report  
THEN invoices are grouped into time buckets, with counts and amounts per bucket.

**Scenario 4:** Export aging report as CSV  
GIVEN the aging report is displayed  
WHEN the operator clicks "Export"  
THEN a CSV file is downloaded with aging bucket data (customer, amount, days overdue, status).

### Feature 3: Accounts Receivable Aging Report with Customer-Level Detail

**Demand Score:** 102 (26 tender mentions) | **Category:** analytics

**Scenario 5:** Drill down from aging summary to customer invoice list  
GIVEN the AR aging report with customer grouping  
WHEN the operator clicks a customer row  
THEN a detail panel opens showing all open invoices for that customer.

### Feature 4: Outstanding Invoices Overview with Total Receivables

**Demand Score:** 90 (28 tender mentions) | **Category:** other

**Scenario 6:** Dashboard widget showing total outstanding receivables  
GIVEN a dashboard with AR widgets  
WHEN the finance officer views the dashboard  
THEN they see a KPI card with total outstanding receivables amount (sum of non-paid invoices), with a trend (month-over-month).

**Scenario 7:** List all outstanding invoices with due dates  
GIVEN the Outstanding Invoices list page  
WHEN the operator opens it  
THEN they see all invoices with `status != 'paid'` and `status != 'written-off'`, sorted by due date (overdue first).

### Feature 5: Aged Receivables Report with Customer-Level Aging Buckets

**Demand Score:** 90 (26 tender mentions) | **Category:** analytics

**Scenario 8:** View detailed aging breakdown per customer  
GIVEN the aging report with customer-level buckets  
WHEN the operator hovers over a bucket  
THEN a tooltip shows invoice count and total amount in that bucket.

## Data Model

### CustomerMaster Register

**Schema:** `CustomerMaster`  
**Schema.org type:** `schema:Organization`  
**Primary key:** `uuid`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| customerNumber | string | Yes | Unique customer code (e.g., CUST-001) |
| legalName | string | Yes | Customer's legal/trading name |
| taxId | string | No | Customer's VAT number (BTW-ID) |
| contactPerson | string | No | Primary contact name |
| email | string | No | Customer contact email |
| billingAddress | string | No | Full billing address |
| paymentTerms | string | No | Payment terms (e.g., "Net 30", "2/10 Net 30") |
| creditLimit | number | No | Maximum outstanding credit allowed (EUR) |
| dungningPolicy | string | No | Reference to dunning workflow configuration |
| status | enum | Yes | One of: active, suspended, inactive |
| iban | string | No | Customer IBAN for payment receipts |
| notes | string | No | Internal notes |

**Relations:**
- → ARInvoice (one-to-many, via `customerId`)
- → DunningRecord (one-to-many, via `customerId`)

### ARInvoice Register

**Schema:** `ARInvoice`  
**Schema.org type:** `schema:Invoice`  
**Primary key:** `uuid`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier (e.g., INV-2026-001) |
| customerId | uuid | Yes | FK to CustomerMaster |
| invoiceDate | date | Yes | Date invoice was issued |
| dueDate | date | Yes | Payment due date |
| description | text | No | Invoice description / subject |
| netAmount | number | Yes | Amount before tax (EUR) |
| taxAmount | number | No | VAT amount (EUR) |
| totalAmount | number | Yes | Total amount including tax (EUR) |
| currency | string | Yes | ISO 4217 code (default: EUR) |
| status | enum | Yes | One of: draft, issued, paid, overdue, disputed, written-off |
| sourceDocumentUri | uri | No | Reference to PDF invoice (docudesk attachment) |
| paidDate | date | No | Date payment was received |
| paidAmount | number | No | Amount actually paid (EUR) |
| notes | string | No | Internal notes or dispute reason |
| glPosting | uuid | No | Reference to GL entry created at issuance |

**Relations:**
- → CustomerMaster (many-to-one, via `customerId`)
- → DunningRecord (one-to-many, via `invoiceId`)
- → GLLine (one-to-many, via `glPosting`)
- → Payment (many-to-one, via bank reconciliation)

### DunningRecord Register

**Schema:** `DunningRecord`  
**Schema.org type:** `schema:Event`  
**Primary key:** `uuid`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| dunningId | string | Yes | Unique dunning record identifier (e.g., DUN-2026-001) |
| invoiceId | uuid | Yes | FK to ARInvoice |
| customerId | uuid | Yes | FK to CustomerMaster (denormalised for query efficiency) |
| level | integer | Yes | Dunning escalation level (1=reminder, 2=formal notice, 3=debt collection) |
| sentDate | date | Yes | Date dunning notice was sent |
| dueDate | date | No | Response/payment deadline |
| status | enum | Yes | One of: pending, acknowledged, paid, escalated, cancelled |
| policyRef | string | No | Reference to dunning policy configuration |
| notes | string | No | Dunning history or customer correspondence |

**Relations:**
- → ARInvoice (many-to-one, via `invoiceId`)
- → CustomerMaster (many-to-one, via `customerId`)

## Aggregations

### AR Aging Aggregation

**Purpose:** Group outstanding invoices by customer and aging bucket (0–30, 31–60, 61–90, 90+ days).

**Definition (pseudo-x-openregister-aggregations):**

```json
{
  "name": "arAging",
  "source": "ARInvoice",
  "filters": { "status": { "not": ["paid", "written-off"] } },
  "groupBy": ["customerId", "agingBucket"],
  "agingBucket": "floor(daysOverdue / 30) * 30",
  "aggregates": [
    { "field": "totalAmount", "fn": "sum", "as": "totalAmount" },
    { "field": "invoiceNumber", "fn": "count", "as": "invoiceCount" },
    { "field": "dueDate", "fn": "min", "as": "oldestDueDate" }
  ],
  "orderBy": [{ "agingBucket": "desc" }]
}
```

### Credit Limit Check Aggregation

**Purpose:** Validate that issuance of a new AR invoice will not exceed customer credit limit.

**Definition (pseudo-x-openregister-aggregations):**

```json
{
  "name": "customerOutstandingAmount",
  "source": "ARInvoice",
  "filters": { "customerId": "{{customerId}}", "status": { "not": ["paid", "written-off"] } },
  "aggregates": [{ "field": "totalAmount", "fn": "sum", "as": "outstandingAmount" }]
}
```

Invoked on `ARInvoice.issue` transition: `outstandingAmount + newInvoiceAmount <= creditLimit`.

## Lifecycle & Transitions

### ARInvoice Lifecycle

```
draft
  ↓ (issue)
issued
  ├─ paid ← (on bank reconciliation match)
  ├─ overdue ← (dueDate < today, automatic or manual)
  └─ disputed ← (manual, e.g., customer contest)
        ↓ (resolve)
      issued (restart payment)
        or written-off

written-off ← (manual, with GL compensation posting)
```

Lifecycle consumed via `x-openregister-lifecycle` in register definition.

### DunningRecord Lifecycle

```
pending
  ↓ (on ARInvoice.overdue)
acknowledged ← (customer response)
  ├─ paid ← (payment received, linked to ARInvoice)
  └─ escalated ← (advance to next level)
        ↓
      level 2, level 3...

cancelled ← (dispute resolved, dispute written-off)
```

## Seed Data

Located in `lib/Settings/shillinq_register.json` under `components.objects[]`:

### Seed Customers

```json
{
  "@self": { "register": "shillinq", "schema": "CustomerMaster", "slug": "customer-001" },
  "customerNumber": "CUST-001",
  "legalName": "Gemeente Amsterdam",
  "taxId": "NL856621669B01",
  "contactPerson": "Jan Jansen",
  "email": "jan.jansen@amsterdam.nl",
  "billingAddress": "Stadhuis, Prinsengracht 1, 1013 SZ Amsterdam",
  "paymentTerms": "Net 30",
  "creditLimit": 50000,
  "status": "active",
  "iban": "NL91ABNA0417164300"
},
{
  "@self": { "register": "shillinq", "schema": "CustomerMaster", "slug": "customer-002" },
  "customerNumber": "CUST-002",
  "legalName": "VNG Realisatie",
  "taxId": "NL002220061B12",
  "contactPerson": "Maria Hendrix",
  "email": "maria@vng.nl",
  "billingAddress": "Koninginnegracht 11, 2595 AA Den Haag",
  "paymentTerms": "2/10 Net 30",
  "creditLimit": 75000,
  "status": "active",
  "iban": "NL24ABNA0208500000"
},
{
  "@self": { "register": "shillinq", "schema": "CustomerMaster", "slug": "customer-003" },
  "customerNumber": "CUST-003",
  "legalName": "Consultancy De Bruyn",
  "taxId": "NL856621668B02",
  "contactPerson": "Peter de Bruyn",
  "email": "peter@debruyn.nl",
  "billingAddress": "Zeestraat 45, 2518 AA Den Haag",
  "paymentTerms": "Net 14",
  "creditLimit": 30000,
  "status": "active",
  "iban": "NL40RABONL2A23456789"
}
```

### Seed Invoices

```json
{
  "@self": { "register": "shillinq", "schema": "ARInvoice", "slug": "invoice-001" },
  "invoiceNumber": "INV-2026-001",
  "customerId": "{{customer-001.uuid}}",
  "invoiceDate": "2026-03-15",
  "dueDate": "2026-04-14",
  "description": "Consultancy services Q1 2026",
  "netAmount": 8000,
  "taxAmount": 1680,
  "totalAmount": 9680,
  "currency": "EUR",
  "status": "issued",
  "iban": "NL91ABNA0417164300",
  "notes": ""
},
{
  "@self": { "register": "shillinq", "schema": "ARInvoice", "slug": "invoice-002" },
  "invoiceNumber": "INV-2026-002",
  "customerId": "{{customer-002.uuid}}",
  "invoiceDate": "2026-02-01",
  "dueDate": "2026-03-02",
  "description": "Platform support and maintenance",
  "netAmount": 5000,
  "taxAmount": 1050,
  "totalAmount": 6050,
  "currency": "EUR",
  "status": "overdue",
  "notes": "Payment expected by 2026-05-21"
},
{
  "@self": { "register": "shillinq", "schema": "ARInvoice", "slug": "invoice-003" },
  "invoiceNumber": "INV-2026-003",
  "customerId": "{{customer-003.uuid}}",
  "invoiceDate": "2026-04-01",
  "dueDate": "2026-04-15",
  "description": "Training and implementation support",
  "netAmount": 3500,
  "taxAmount": 735,
  "totalAmount": 4235,
  "currency": "EUR",
  "status": "paid",
  "paidDate": "2026-04-10",
  "paidAmount": 4235,
  "notes": ""
}
```

## Manifest Integration

Four new navigation entries in `appinfo/manifest.json`:

```json
{
  "name": "Customers",
  "route": "customers",
  "icon": "icon-customers",
  "order": 10
},
{
  "name": "Accounts Receivable",
  "route": "invoices",
  "icon": "icon-invoices",
  "order": 20
},
{
  "name": "AR Aging",
  "route": "ar-aging",
  "icon": "icon-report",
  "order": 30
},
{
  "name": "Dunning Log",
  "route": "dunning",
  "icon": "icon-dunning",
  "order": 40
}
```

## UI Pages

### Customers (CnIndexPage)

- List all CustomerMaster objects with search/filter
- Add button → create new customer
- Row click → detail page (edit, delete, view invoices)
- Sidebar: related AR invoices, dunning records

### Accounts Receivable (CnIndexPage)

- List all ARInvoice objects
- Columns: Invoice #, Customer, Amount, Due Date, Days Overdue, Status
- Filter by: customer, status (open/overdue/disputed), amount range
- Add button → create new invoice
- Row click → detail page (edit, view PDF, mark paid/disputed)
- Bulk actions: mark paid, write-off, export

### AR Aging Report (CnDashboardPage with CnTableWidget)

- Table grouped by customer and aging bucket
- Columns: Customer, 0–30, 31–60, 61–90, 90+, Total Outstanding
- Sort by: overdue amount (desc), customer (asc)
- Export button → CSV with aging breakdown

### Dunning Log (CnIndexPage)

- List all DunningRecord objects
- Columns: Dunning #, Customer, Invoice #, Level, Sent Date, Status
- Filter by: status, level, customer
- Row click → detail page (view correspondence, acknowledge, escalate)

## Reuse Analysis

Per ADR-012, this design leverages existing OpenRegister services:

- **ObjectService** (CRUD): `saveObject()`, `findObject()`, `findObjects()` for all three registers
- **ImportService / ExportService**: seed data import via `ConfigurationService::importFromApp()` + export via `ExportService`
- **IndexService + FacetBuilder**: full-text search on invoice/customer name, faceted filters by status/aging bucket
- **AuditTrailService**: automatic change tracking on all register objects
- **FileService**: store invoice PDFs via docudesk attachment URI
- **ConfigurationService**: register template import with `force=false` idempotency
- **CnIndexPage / CnDetailPage / CnDashboardPage**: schema-driven list/detail/dashboard UI (no custom components)
- **CnDataTable / CnFormDialog / CnAdvancedFormDialog**: data display and editing

**No custom PHP services required:** all AR logic is declarative via:
- Schemas (CustomerMaster, ARInvoice, DunningRecord)
- Lifecycles (`x-openregister-lifecycle`)
- Aggregations (`x-openregister-aggregations`)
- Dunning workflow (consumed from OpenRegister extension)

## Database & Performance

- **Register backend:** OpenRegister ObjectService (SQL via `IObjectMapper`)
- **Indexes:** recommend composite on ARInvoice `(customerId, status, dueDate)` for aging query efficiency
- **Aggregations:** lazy-evaluated by OpenRegister query engine; caching managed by register settings
- **Pagination:** 50 invoices per page (configurable)

## Security & Authorization

- **Per-object RBAC:** ARInvoice owner = customerMaster.organization; creator can view/edit own administration's invoices only
- **Role-based actions:** Finance Officer (issue/mark-paid/write-off), Customer (view own invoices only), Admin (all)
- **No PII in logs:** customer name is logged; email, address, IBAN are masked

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Dunning workflow extension not stable | ADR-031: implement single `DunningGuard` service if needed; wrap in lifecycle decorator |
| Large customer dataset (1000+) | Faceted search + pagination; customer list index on name/code |
| Concurrent invoice edits (reconciliation + manual override) | Optimistic locking via OpenRegister `version` field; conflict resolution via audit trail |
| Payment matching ambiguity (multiple invoices, partial payment) | Bank reconciliation emits candidate matches with score; operator confirms manually |

## Deferred (T3/T4)

- Invoice generation workflows (`bookkeeping-ar-issuing` spec)
- Multi-currency revaluation for outstanding invoices
- Advanced analytics (aging trends, DSO, write-off analysis)
- Customer portal / self-service invoice view
