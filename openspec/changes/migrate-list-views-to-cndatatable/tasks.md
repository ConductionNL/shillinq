# Tasks: migrate-list-views-to-cndatatable

## 1. Migrate the five record-list views to CnDataTable
- [ ] 1.1 `src/views/invoice/AdminInvoiceList.vue` — replace the bespoke
      `<table>` with `CnDataTable` (columns: invoice #, invoice date, due date,
      customer, billing model, gross, status, actions); keep the status filter and
      per-row actions.
- [ ] 1.2 `src/views/bookkeeping/DocumentsView.vue` — same migration.
- [ ] 1.3 `src/views/bookkeeping/TransactionsView.vue` — same migration.
- [ ] 1.4 `src/components/three-way-match/ThreeWayMatchIndex.vue` — same migration
      (columns: invoice, supplier, amount, match date, status, linked PO/GRN).
- [ ] 1.5 `src/components/vendor-performance/VendorPerformanceIndex.vue` — same
      migration.

## 2. Accessibility
- [ ] 2.1 Ensure any `NcSelect` filters carry an `inputLabel` (nc-vue
      requirement / fleet gate) rather than a manual `<label>`.

## 3. Tests
- [ ] 3.1 Vitest: each migrated view renders `CnDataTable` as its list renderer
      with the expected columns.
- [ ] 3.2 Playwright: on the invoice list, assert CnDataTable renders rows and the
      status filter narrows them.

## 4. Verify
- [ ] 4.1 Grep the five files for a remaining hand-rolled record-list
      `<table><thead><tbody>`; confirm none remain.
- [ ] 4.2 Confirm the nine exempt detail/line-item tables are untouched.
