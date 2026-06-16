# Implementation Tasks — Waterschappen BBV Variant (BBVW)

## Phase 0: Setup & Dependency Verification

- [ ] Verify OpenRegister availability and stable `x-openregister-aggregations` extension
- [ ] Verify Shillinq T1 (Chart of Accounts, GL Transactions, Administration) is released
- [ ] Verify @conduction/nextcloud-vue ≥ 1.0.0-beta.66 is available
- [ ] Create feature branch `feature/bookkeeping-waterschappen-bbv-variant`

## Phase 1: Schema & Register Declaration

### Register Definition

- [ ] Add `BBVProgramme` schema to `lib/Settings/shillinq_register.json`
  - [ ] Define all properties per REQ-BBVW-001 schema
  - [ ] Add validation rules (code regex, fiscal year range)
  - [ ] Add relation to Administration
  - [ ] Set register permissions (admin-write, public-read)

- [ ] Add `BudgetBBVMapping` schema to `lib/Settings/shillinq_register.json`
  - [ ] Define all properties per REQ-BBVW-002 schema
  - [ ] Add FK relations to BBVProgramme, Account, Administration
  - [ ] Add aggregation validation: per-account allocation ≤ 100%
  - [ ] Set register permissions (admin-write, public-read)

### Seed Data

- [ ] Create seed data for 5 demo programmes (fiscal year 2026)
  - [ ] 1.1.1 — Core Administration
  - [ ] 1.2.1 — HR & Payroll
  - [ ] 2.3.2 — Water Quality Monitoring
  - [ ] 2.4.1 — Infrastructure Maintenance
  - [ ] 3.1.0 — Strategic Planning

- [ ] Create seed data for 5 demo mappings
  - [ ] GL 4100 → 50% 1.1.1, 30% 1.2.1, 20% 2.4.1
  - [ ] GL 5000 → 25% 2.3.2, 75% 2.4.1
  - [ ] Additional mappings per existing GL accounts

- [ ] Verify seed data idempotency (re-import does not create duplicates)

## Phase 2: Dashboard Implementation

### BBV Compliance Dashboard

- [ ] Create `src/Dashboard/BBVComplianceWidget.php` (controller)
  - [ ] Query `BBVProgramme` and `BudgetBBVMapping` registers
  - [ ] Implement aggregation logic per REQ-BBVW-005 (Utilization, ComplianceStatus)
  - [ ] Return JSON response with widget data

- [ ] Create `src/components/Dashboard/BBVComplianceDashboard.vue` (main dashboard page)
  - [ ] Use CnDashboardPage layout
  - [ ] Wire up 4 widget types: KPI cards, pie chart, table, line chart

- [ ] Create `src/components/Dashboard/BBVKPICards.vue`
  - [ ] Display 4 CnStatsBlock cards: Total, On-Track, At-Risk, Non-Compliant counts
  - [ ] Fetch data from controller

- [ ] Create `src/components/Dashboard/BBVComplianceChart.vue`
  - [ ] CnChartWidget (pie chart) — compliance status distribution
  - [ ] Fetch aggregation data

- [ ] Create `src/components/Dashboard/BBVTrendChart.vue`
  - [ ] CnChartWidget (line chart) — YTD spend trend per programme
  - [ ] Query GL transactions and compute cumulative spend

- [ ] Create `src/components/Dashboard/BBVProgrammeTable.vue`
  - [ ] CnDataTable with columns: Code, Name, Budget, YTD, Utilization %, Status
  - [ ] Sortable, filterable
  - [ ] Inline status badge (🟢 🟡 🔴 ⚪)
  - [ ] Click row → navigate to Programme detail page

### Dashboard Integration

- [ ] Register dashboard route in `appinfo/routes.php`
  - [ ] Route: `GET /index.php/apps/shillinq/bbv-dashboard`
  - [ ] Controller: `DashboardController::index()`

- [ ] Add dashboard entry to `src/manifest.json`
  - [ ] Title: "BBV Compliance Dashboard"
  - [ ] Icon: `icon-analytics` or `icon-chart-pie`
  - [ ] Order: after main dashboard

## Phase 3: Budget Mapping UI

### Index Page

- [ ] Create `src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue`
  - [ ] Use CnIndexPage layout
  - [ ] Columns: GL Account, Programme, Allocation %, Effective From, Effective To
  - [ ] Search by account number or programme code
  - [ ] Filter by fiscal year, allocation range, date range
  - [ ] Add button → navigate to detail page with `id=new`
  - [ ] Row click → navigate to detail page with `id=<uuid>`

- [ ] Create `src/store/modules/budgetBBVMappingStore.js`
  - [ ] Use `createObjectStore('budget-bbv-mapping', 'BudgetBBVMapping', 'Mappings')`
  - [ ] Register plugins: relations, auditTrails

### Detail Page

- [ ] Create `src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue`
  - [ ] Use CnDetailPage layout
  - [ ] Form fields: GL Account (picker), Programme (picker), Allocation %, Effective From, Effective To, Status
  - [ ] Actions: Save, Delete, Cancel
  - [ ] Sidebar: CnObjectSidebar with audit trail tab

- [ ] Implement GL Account picker
  - [ ] Dropdown/autocomplete with search by account number or name
  - [ ] Fetch from Chart of Accounts register
  - [ ] Display account name + type + balance in picker

- [ ] Implement BBV Programme picker
  - [ ] Dropdown/autocomplete with search by code or name
  - [ ] Fetch from BBVProgramme register (current fiscal year only)
  - [ ] Display programme code + name

- [ ] Implement inline validation
  - [ ] As user edits allocation %, recalculate total for selected GL account
  - [ ] Display warning if total > 100% (no save until corrected)
  - [ ] Display helpful message: "GL 4100 total: 45% (of 100%) — you can add up to 55%"

- [ ] Implement save logic
  - [ ] Call `objectStore.saveObject()` with form data
  - [ ] On success: return to index, show toast notification
  - [ ] On error: display error message inline, do not dismiss

- [ ] Implement delete logic
  - [ ] Delete button (only for existing records, not on new)
  - [ ] Confirm dialog before delete
  - [ ] Call `objectStore.deleteObject()`
  - [ ] On success: return to index

### Routing

- [ ] Register routes in `appinfo/routes.php`
  - [ ] `GET /index.php/apps/shillinq/budget-mappings` → index
  - [ ] `GET /index.php/apps/shillinq/budget-mappings/:id` → detail

- [ ] Add manifest entries to `src/manifest.json`
  - [ ] Title: "Budget Mapping"
  - [ ] Pages: index + detail

## Phase 4: Aggregation & Validation

### Compliance Status Aggregation

- [ ] Create `src/Service/ComplianceService.php`
  - [ ] Method `computeComplianceStatus($programme)`: returns {utilization, status, budget, ytdSpend}
  - [ ] Query GL transactions for the programme's mapped accounts
  - [ ] Query mappings and budget for the programme
  - [ ] Compute Utilization, determine Status per REQ-BBVW-005 rules
  - [ ] Cache result (TTL: 1 hour, invalidate on GL transaction create/update)

- [ ] Create aggregation definition in register schema (per ADR-031)
  - [ ] Add `x-openregister-aggregations` block to `BBVProgramme` schema
  - [ ] Define aggregation query: sum GL spend by (customerId, agingBucket)
  - [ ] Define aggregation query: sum outstanding budget per programme

### Validation

- [ ] Add OpenRegister schema validation to `BudgetBBVMapping`
  - [ ] On save: trigger "per-account allocation ≤ 100%" check
  - [ ] Return 400 Bad Request with descriptive error if validation fails
  - [ ] Tolerance: 99.9% to 100.1% (rounding safety)

- [ ] Add OpenRegister schema validation to `BBVProgramme`
  - [ ] programmeCode: must match regex per schema
  - [ ] programmCode: must be unique per (administration, fiscalYear)
  - [ ] Return 400 Bad Request if validation fails

## Phase 5: Internationalization (i18n)

- [ ] Create `l10n/en.json`
  - [ ] Add all UI strings as English keys (source)
  - [ ] Keys: "BBV Compliance Dashboard", "Budget Mapping", "Programme Code", "Allocation Percentage", etc. (per REQ-BBVW-009)

- [ ] Create `l10n/nl.json`
  - [ ] Add Dutch translations for all keys
  - [ ] Use sentence case (not title case)

- [ ] Replace all hardcoded strings in Vue components
  - [ ] `this.t('shillinq', 'BBV Compliance Dashboard')`
  - [ ] `this.t('shillinq', 'On-Track')`
  - [ ] etc.

- [ ] Replace all hardcoded strings in PHP responses
  - [ ] `$this->l10n->t('BBV Compliance Dashboard')`
  - [ ] etc.

- [ ] Verify translation keys are consistent across components

## Phase 6: Testing & Verification

### Unit Tests

- [ ] Create `tests/Unit/Service/ComplianceServiceTest.php`
  - [ ] Test `computeComplianceStatus()` with various spend levels
  - [ ] Test aggregation of multiple GL accounts
  - [ ] Test rounding tolerance (99.9% to 100.1%)
  - [ ] Test fiscal-year scoping

### Integration Tests

- [ ] Create `tests/Integration/ComplianceAggregationTest.php`
  - [ ] Create programmes, mappings, GL transactions
  - [ ] Verify dashboard data matches computed aggregations
  - [ ] Verify compliance status updates as GL transactions are recorded

### Functional Tests (Browser)

- [ ] Test BBV Compliance Dashboard
  - [ ] Dashboard loads and displays all 4 widgets
  - [ ] KPI cards show correct counts (on-track, at-risk, etc.)
  - [ ] Pie chart renders correct proportions
  - [ ] Table is sortable by any column
  - [ ] Status badges display with correct colors
  - [ ] Clicking a programme row navigates to detail page

- [ ] Test Budget Mapping Index
  - [ ] Page loads with seeded mappings visible
  - [ ] Search by GL account filters correctly
  - [ ] Add button opens new detail page
  - [ ] Row click navigates to detail page with data pre-filled

- [ ] Test Budget Mapping Detail (Create)
  - [ ] Form fields render correctly
  - [ ] GL Account picker allows search by account number
  - [ ] Programme picker allows search by code
  - [ ] Allocation % input validates numeric 0–100
  - [ ] Effective From defaults to today
  - [ ] Save button creates record and returns to index
  - [ ] Validation prevents saving if total > 100%

- [ ] Test Budget Mapping Detail (Edit)
  - [ ] Form pre-fills with existing data
  - [ ] Edits update the record on save
  - [ ] Delete button removes record (with confirm)
  - [ ] Sidebar audit trail shows all historical changes

- [ ] Test Fiscal Year Scoping
  - [ ] Switch to different administration → data updates
  - [ ] Filter UI shows only programmes for current fiscal year
  - [ ] GL transactions from prior fiscal years are excluded

- [ ] Test Validation & Error Handling
  - [ ] Creating mapping with total > 100% shows error
  - [ ] Programme code with invalid format rejected
  - [ ] Creating mapping with invalid GL account rejected
  - [ ] Effective dates validated (To ≥ From)

### Smoke Tests

- [ ] Verify all routes are reachable and respond with 200 OK
  - [ ] GET /bbv-dashboard
  - [ ] GET /budget-mappings
  - [ ] GET /budget-mappings/new
  - [ ] GET /budget-mappings/:id

- [ ] Verify schema fields are populated correctly
  - [ ] BBVProgramme has required fields: programmeName, programmeCode, fiscalYear
  - [ ] BudgetBBVMapping has required fields: glAccountNumber, allocationPercentage, effectiveFrom

- [ ] Verify seed data is loaded on install
  - [ ] 5 programmes visible in picker
  - [ ] 5 mappings visible in index

## Phase 7: Documentation & Cleanup

### Code Documentation

- [ ] Add PHPDoc to `ComplianceService.php` with @spec tag
- [ ] Add Vue component JSDoc with description, props, events
- [ ] Add README snippet explaining BBV variant scope and usage

### Deduplication Check

- [ ] Verify no duplicate GL account linkage implementation elsewhere in Shillinq
- [ ] Verify no existing "compliance dashboard" or "budget mapping" UI
- [ ] Verify aggregation logic is not reimplemented in other specs
- [ ] Document findings in task completion summary

### Code Style & Linting

- [ ] Run `composer check:strict` — all checks pass
- [ ] Run `npm run lint` — all Vue/JS checks pass
- [ ] Verify SPDX headers on all new files
- [ ] Verify translation key consistency

### Git Cleanup

- [ ] Rebase on main if necessary
- [ ] Squash or organize commits logically
- [ ] Verify no uncommitted changes
- [ ] Ready for PR creation

## Phase 8: CI/CD & Quality Gates

- [ ] Run Hydra mechanical gates
  - [ ] `hydra-gate-route-auth` — all routes have proper auth attributes
  - [ ] `hydra-gate-semantic-auth` — auth attributes match body requirements
  - [ ] `hydra-gate-nc-input-labels` — form inputs have associated labels
  - [ ] `hydra-gate-modal-isolation` — all modals in separate files
  - [ ] All other gates pass

- [ ] Resolve any pre-commit issues
- [ ] Commit with message: "feat: Add OpenSpec change bookkeeping-waterschappen-bbv-variant from Specter"

---

## Summary

This task list implements the full Waterschappen BBV Variant capability across 8 phases:

1. **Setup** — dependencies, branches
2. **Schema** — register definitions, seed data
3. **Dashboard** — UI widgets, controllers
4. **Budget Mapping UI** — index, detail, routing
5. **Aggregations** — compliance computation, validation
6. **i18n** — English & Dutch translations
7. **Testing** — unit, integration, functional, smoke
8. **Cleanup** — documentation, linting, CI/CD gates

**Total estimates:**
- Schema & seed: 1–2 days
- Dashboard: 2–3 days
- Budget Mapping UI: 2–3 days
- Aggregations: 1–2 days
- i18n: 0.5 day
- Testing: 3–4 days
- Documentation & cleanup: 1 day

**Total: 11–16 days** (7–10 person-days with parallelization)

---

## Success Criteria

✅ All 9 requirements (REQ-BBVW-001 through REQ-BBVW-009) are implemented and tested  
✅ All mechanical gates (Hydra) pass with zero findings  
✅ All browser tests pass (create, read, update, delete flows verified)  
✅ Seed data loads on fresh install without duplicates on re-import  
✅ Dashboard renders without console errors  
✅ Translations present for all UI strings  
✅ Code review complete with zero blocking findings
