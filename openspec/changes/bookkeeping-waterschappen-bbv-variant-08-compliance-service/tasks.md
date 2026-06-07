# Tasks — Member 08: compliance service

Sourced from the giant's Phase 4 (ComplianceService) and Phase 2
(BBVComplianceWidget controller).

## ComplianceService

- [x] Create `lib/Service/ComplianceService.php`
- [x] Implement `computeComplianceStatus($programme)` returning {utilization, status, budget, ytdSpend}
- [x] Read the member-02 aggregation values (do not reimplement the formulas)
- [x] Query the programme's mapped GL accounts and budget via OR ObjectService (find/findAll)
- [x] Cache the result (TTL 1 hour)
- [x] Invalidate the cache on GL transaction create/update

## Dashboard controller

- [x] Create `src/Dashboard/BBVComplianceWidget.php` (controller)
- [x] Query `BBVProgramme` and `BudgetBBVMapping` registers
- [x] Return the JSON widget envelope consumed by the member-05 dashboard
- [x] Declare the controller route auth attribute (matches member 04)
