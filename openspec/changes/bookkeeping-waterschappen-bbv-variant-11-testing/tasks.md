# Tasks — Member 11: testing

Sourced from the giant's Phase 6 (Testing & Verification).

## Unit tests

- [ ] Create `tests/Unit/Service/ComplianceServiceTest.php` covering spend levels, multi-account aggregation, rounding tolerance, and fiscal-year scoping

## Integration tests

- [ ] Create `tests/Integration/ComplianceAggregationTest.php` verifying dashboard data matches computed aggregations and updates as GL transactions are recorded

## Browser tests — dashboard

- [ ] Test the dashboard loads all 4 widgets with correct counts, badges, and pie proportions
- [ ] Test the programme table is sortable and a row click navigates to detail

## Browser tests — mapping index

- [ ] Test the index loads seeded mappings, search filters correctly, Add opens new detail, row click pre-fills detail

## Browser tests — mapping detail

- [ ] Test create: pickers, allocation 0–100 validation, Effective From default, save returns to index, >100% total blocked
- [ ] Test edit: form pre-fills, edits persist on save, delete with confirm, sidebar audit trail shows changes

## Browser tests — scoping & validation

- [ ] Test fiscal-year scoping: switching administration updates data; prior-year GL excluded
- [ ] Test validation/error handling: >100% total, invalid programme code, invalid GL account, effectiveTo ≥ effectiveFrom

## Smoke tests

- [ ] Verify routes respond 200 (/bbv-dashboard, /budget-mappings, /budget-mappings/new, /budget-mappings/:id)
- [ ] Verify required schema fields are populated and seed data (5 programmes + mappings) is loaded
