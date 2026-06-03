# Tasks — Member 07: mapping detail

Sourced from the giant's Phase 3 (Detail Page).

## Detail page

- [ ] Create `src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue` using CnDetailPage layout
- [ ] Form fields: GL Account (picker), Programme (picker), Allocation %, Effective From, Effective To, Status
- [ ] Actions: Save, Delete, Cancel
- [ ] Sidebar: CnObjectSidebar with audit trail tab

## GL Account picker

- [ ] Implement dropdown/autocomplete with search by account number or name
- [ ] Fetch from the Chart of Accounts register
- [ ] Display account name + type + balance in the picker (with inputLabel)

## BBV Programme picker

- [ ] Implement dropdown/autocomplete with search by code or name
- [ ] Fetch from the BBVProgramme register (current fiscal year only)
- [ ] Display programme code + name (with inputLabel)

## Inline validation

- [ ] Recalculate the per-account total as the user edits allocation %
- [ ] Display warning if total > 100% (no save until corrected)
- [ ] Display "GL 4100 total: 45% — you can add up to 55%" helper message

## Save / delete

- [ ] Implement save: call `objectStore.saveObject()`, toast on success, inline error on failure
- [ ] Implement delete: confirm dialog, `objectStore.deleteObject()`, return to index on success
