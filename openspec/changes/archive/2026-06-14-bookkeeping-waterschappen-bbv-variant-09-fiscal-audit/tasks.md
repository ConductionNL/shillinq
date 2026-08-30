# Tasks — Member 09: fiscal scoping + audit

Sourced from the giant's REQ-BBVW-006 (Fiscal Year Scoping) and
REQ-BBVW-007 (Audit Trail Integration).

## Fiscal-year scoping

- [x] Inherit the current fiscal year from the Shillinq Administration context
- [x] Apply fiscal-year scoping to the dashboard queries
- [x] Apply fiscal-year scoping to the mapping index and detail queries
- [x] Apply fiscal-year scoping to `ComplianceService` aggregation reads
- [x] Exclude prior-fiscal-year GL transactions from all BBV views
- [x] Surface the active fiscal year in the UI (label/breadcrumb)
- [x] Refresh BBV data automatically when the administration changes
- [x] Enforce server-side scope so one administration cannot read another's data

## Audit-trail integration

- [x] Verify OR captures create/update/delete on `BBVProgramme`
- [x] Verify OR captures create/update/delete on `BudgetBBVMapping`
- [x] Verify audit records include timestamp, user id, action, before/after state
