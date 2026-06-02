# Tasks — Member 06: mapping index

Sourced from the giant's Phase 3 (Index Page) and its store task.

## Index page

- [ ] Create `src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue` using CnIndexPage layout
- [ ] Columns: GL Account, Programme, Allocation %, Effective From, Effective To, Status
- [ ] Search by account number or programme code
- [ ] Filter by fiscal year, allocation range, date range
- [ ] Add button → navigate to detail page with `id=new`
- [ ] Row click → navigate to detail page with `id=<uuid>`

## Object store

- [ ] Create `src/store/modules/budgetBBVMappingStore.js`
- [ ] Use `createObjectStore('budget-bbv-mapping', 'BudgetBBVMapping', 'Mappings')`
- [ ] Register plugins: relations, auditTrails
