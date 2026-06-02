# Tasks — Member 08: compliance service

Sourced from the giant's Phase 4 (ComplianceService) and Phase 2
(BBVComplianceWidget controller).

## ComplianceService

- [ ] Create `lib/Service/ComplianceService.php`
- [ ] Implement `computeComplianceStatus($programme)` returning {utilization, status, budget, ytdSpend}
- [ ] Read the member-02 aggregation values (do not reimplement the formulas)
- [ ] Query the programme's mapped GL accounts and budget via OR ObjectService (find/findAll)
- [ ] Cache the result (TTL 1 hour)
- [ ] Invalidate the cache on GL transaction create/update

## Dashboard controller

- [ ] Create `src/Dashboard/BBVComplianceWidget.php` (controller)
- [ ] Query `BBVProgramme` and `BudgetBBVMapping` registers
- [ ] Return the JSON widget envelope consumed by the member-05 dashboard
- [ ] Declare the controller route auth attribute (matches member 04)
