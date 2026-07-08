# Spec: list-views-cndatatable (delta)

## ADDED Requirements

### Requirement: REQ-LVC-001 Record-list views SHALL render through nc-vue CnDataTable

Paginated/filterable record-list views MUST render their rows through
`@conduction/nextcloud-vue` `CnDataTable` (column definitions + `:rows`, the
`#footer` slot, and `cn-cell` utilities) rather than a hand-rolled
`<table><thead><tbody>` with manual filter controls and per-row action buttons.
This aligns list views with the fleet CnDataTable universal-list-widget
convention and the shared component's built-in sorting, pagination, empty-state,
and accessibility wiring.

The five views that currently reimplement the table MUST migrate:
`src/views/invoice/AdminInvoiceList.vue`,
`src/views/bookkeeping/DocumentsView.vue`,
`src/views/bookkeeping/TransactionsView.vue`,
`src/components/three-way-match/ThreeWayMatchIndex.vue`,
`src/components/vendor-performance/VendorPerformanceIndex.vue`. Each MUST preserve
its existing columns, row data, filters, and row actions — the change is
presentation only.

Single-record detail, line-item, comparison, and pivot tables (e.g.
`PurchaseOrderDetail.vue`, `SupplierInvoiceDetail.vue`, `InvoiceLineItemReview.vue`,
`SegmentPnLDashboard.vue`) are EXEMPT — they are a fixed-shape single-record
concern, not record-list widgets, and MUST NOT be forced onto CnDataTable.

#### Scenario: The invoice list renders via CnDataTable

- **WHEN** `AdminInvoiceList.vue` renders its list of invoices
- **THEN** the rows are rendered by `CnDataTable` (columns + `:rows`), not a bespoke `<table>`, and the invoice-number, dates, customer, billing-model, gross, status, and actions columns are all present
- **AND** the status filter and per-row actions still function
- @e2e The invoice list is browser-observable — a Playwright test SHALL assert the CnDataTable renders rows and the status filter narrows them

#### Scenario: All five named views use CnDataTable

- **WHEN** each of the five migrated views renders
- **THEN** none contains a hand-rolled `<table><thead><tbody>` record list, and each renders through `CnDataTable`
- @e2e exclude structural assertion across five components — covered by vitest component tests asserting CnDataTable is the list renderer

#### Scenario: Detail/line-item tables are left unchanged

- **WHEN** a single-record detail view with a fixed-shape line-item table renders (e.g. `PurchaseOrderDetail.vue`)
- **THEN** its embedded table is unchanged and is NOT converted to CnDataTable
- @e2e exclude exemption assertion — covered by vitest / code review
