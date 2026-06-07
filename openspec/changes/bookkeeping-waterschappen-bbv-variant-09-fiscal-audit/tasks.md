# Tasks — Member 09: fiscal scoping + audit

Sourced from the giant's REQ-BBVW-006 (Fiscal Year Scoping) and
REQ-BBVW-007 (Audit Trail Integration).

## Fiscal-year scoping

- [ ] Inherit the current fiscal year from the Shillinq Administration context
- [ ] Apply fiscal-year scoping to the dashboard queries
- [ ] Apply fiscal-year scoping to the mapping index and detail queries
- [ ] Apply fiscal-year scoping to `ComplianceService` aggregation reads
- [ ] Exclude prior-fiscal-year GL transactions from all BBV views
- [ ] Surface the active fiscal year in the UI (label/breadcrumb)
- [ ] Refresh BBV data automatically when the administration changes
- [ ] Enforce server-side scope so one administration cannot read another's data

## Audit-trail integration

- [ ] Verify OR captures create/update/delete on `BBVProgramme`
- [ ] Verify OR captures create/update/delete on `BudgetBBVMapping`
- [ ] Verify audit records include timestamp, user id, action, before/after state
