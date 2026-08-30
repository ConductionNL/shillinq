# Change: migrate-list-views-to-cndatatable

## Why

Per the fleet convention "Vue logic lives in nc-vue" and the CnDataTable
universal-list-widget pattern (`@conduction/nextcloud-vue` `CnDataTable` over a
bespoke `<ul>`/`<table>`), paginated/filterable record-list views should use
`CnDataTable` rather than a hand-rolled `<table><thead><tbody>` with manual
filters and per-row action buttons. Shillinq already uses `CnDataTable` as its
house list widget (`src/components/reporting/GeneratedReportsIndex.vue`,
`src/components/Dashboard/BBVProgrammeTable.vue`), but **five record-list views
still reimplement the table by hand**:

- `src/views/invoice/AdminInvoiceList.vue` (bespoke `<table>` + status filter +
  per-row actions, ~lines 53-97)
- `src/views/bookkeeping/DocumentsView.vue` (~line 53)
- `src/views/bookkeeping/TransactionsView.vue` (~line 57)
- `src/components/three-way-match/ThreeWayMatchIndex.vue` (~line 63)
- `src/components/vendor-performance/VendorPerformanceIndex.vue` (~line 61)

Each is exactly the "paginated/filterable list of records with row actions" shape
CnDataTable targets. Reimplementing it locally duplicates sorting, pagination,
empty-state, and accessibility wiring that nc-vue already provides (and that the
fleet gates — e.g. NcSelect input-label accessibility), drifts from the shared
look, and is five more surfaces to maintain.

This is an nc-vue-reuse (ADR-022 / "Vue logic in nc-vue") consistency fix, not a
feature change: the same rows, filters, and actions, rendered through the shared
component.

## What Changes

- **ADDED** `REQ-LVC-001` (new capability `list-views-cndatatable`) — record-list
  views SHALL render through nc-vue `CnDataTable` (columns + `:rows`, `#footer`
  slot, `cn-cell` utilities) rather than a bespoke `<table>`; the five named views
  SHALL migrate; single-record detail / line-item / comparison tables are
  explicitly exempt.
- The five `.vue` files above — replace the hand-rolled `<table>` with
  `CnDataTable`, mapping their existing columns, row data, filters, and row
  actions onto the component's API (`:rows`, column defs, action slot). No change
  to what data is shown or which actions exist.

## Impact

- Affected spec: new `list-views-cndatatable` capability (ADDED `REQ-LVC-001`).
- Affected code: five Vue views (presentation only). No PHP, schema, route, or
  store change; the same object stores feed the tables.
- Explicitly out of scope: the nine fixed-shape detail/line-item/pivot tables
  (`PurchaseOrderDetail.vue`, `SupplierInvoiceDetail.vue`,
  `GoodsReceiptNoteDetail.vue`, `PeriodCloseDetail.vue`,
  `VendorPerformanceDetail.vue`, `InvoiceLineItemReview.vue`,
  `PurchaseOrderForm.vue`, `ThreeWayMatchExceptionPanel.vue`,
  `SegmentPnLDashboard.vue`) — these are single-record embedded tables, a
  different concern than CnDataTable, and are correct as-is.
