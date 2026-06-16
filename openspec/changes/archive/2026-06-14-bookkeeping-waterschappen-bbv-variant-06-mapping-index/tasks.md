# Tasks — Member 06: mapping index

Sourced from the giant's Phase 3 (Index Page) and its store task.

## Index page

- [x] Create `src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue` using CnIndexPage layout
- [x] Columns: GL Account, Programme, Allocation %, Effective From, Effective To, Status
- [x] Search by account number or programme code
- [x] Filter by fiscal year, allocation range, date range
- [x] Add button → navigate to detail page with `id=new`
- [x] Row click → navigate to detail page with `id=<uuid>`

## Object store

- [x] Create `src/store/modules/budgetBBVMappingStore.js`
- [x] Use `createObjectStore('budget-bbv-mapping', 'BudgetBBVMapping', 'Mappings')`
- [x] Register plugins: relations, auditTrails
