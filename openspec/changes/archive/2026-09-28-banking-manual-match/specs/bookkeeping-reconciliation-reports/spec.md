# bookkeeping-reconciliation-reports Specification (delta)

## Purpose

The unmatched items bulk actions reach shillinq. From shillinq matrix row
`bnk-manual-match`, whose note flags the URLs.

## ADDED Requirements

### Requirement: The unmatched items bulk actions reach the shillinq endpoint (REQ-BMM-004)

The bulk actions on the unmatched items page SHALL ask for one reason and
call `/apps/shillinq/api/reconciliations/{reconId}/matches/bulk-resolve`
once per reconciliation of the selected items, with those items and the
reason, so that classifying a selection as timing, pending or adjustment is
saved and the rest of each item is kept.

@e2e exclude the grouping and the calls are asserted by tests/vitest/bankManualMatch.spec.js and the kept fields by ReconciliationResolutionServiceTest::testResolveKeepsTheMatchFields

#### Scenario: A controller classifies three items as timing

- GIVEN a controller on the unmatched items page with three items of one open reconciliation selected
- WHEN they choose Classify as timing with the reason Betaling onderweg
- THEN the three items disappear from the page
- AND each shows resolution timing with that reason on the reconciliation detail page
