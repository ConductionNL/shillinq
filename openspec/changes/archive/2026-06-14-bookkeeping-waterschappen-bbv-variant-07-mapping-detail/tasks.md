# Tasks — Member 07: mapping detail

Sourced from the giant's Phase 3 (Detail Page).

## Detail page

- [x] Create `src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue` using CnDetailPage layout
- [x] Form fields: GL Account (picker), Programme (picker), Allocation %, Effective From, Effective To, Status
- [x] Actions: Save, Delete, Cancel
- [x] Sidebar: CnObjectSidebar with audit trail tab

## GL Account picker

- [x] Implement dropdown/autocomplete with search by account number or name
- [x] Fetch from the Chart of Accounts register
- [x] Display account name + type + balance in the picker (with inputLabel)

## BBV Programme picker

- [x] Implement dropdown/autocomplete with search by code or name
- [x] Fetch from the BBVProgramme register (current fiscal year only)
- [x] Display programme code + name (with inputLabel)

## Inline validation

- [x] Recalculate the per-account total as the user edits allocation %
- [x] Display warning if total > 100% (no save until corrected)
- [x] Display "GL 4100 total: 45% — you can add up to 55%" helper message

## Save / delete

- [x] Implement save: call `objectStore.saveObject()`, toast on success, inline error on failure
- [x] Implement delete: confirm dialog, `objectStore.deleteObject()`, return to index on success
