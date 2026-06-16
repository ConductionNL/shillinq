# Tasks — Member 08: Exception Resolution Workflow (code)

## ExceptionResolutionService

- [x] Implement `acceptWithMotivation()` — record resolution_action + resolved_by + resolution_notes on ThreeWayMatch
- [x] Implement `fileDispute()` — auto-generate UBL CreditNote request via openconnector, escalate to Inkoper notification queue
- [x] Implement `rejectAndBlockPayment()` — mark invoice rejected, reverse partial GR/IR postings, restore stock if needed

## Notification integration

- [x] Send exception alert to crediteuren-administrateur on match_status = exception_*
- [x] Include side-by-side comparison in notification body; deep-link to ThreeWayMatchExceptionPanel

## Vue panel

- [x] Create `ThreeWayMatchExceptionPanel.vue`: side-by-side PO↔GRN↔Invoice (qty, price, VAT, dates), human-readable divergence_details, three action buttons + motivation/notes input
- [x] On resolution, update ThreeWayMatch with resolution_action + resolved_by + notes

## Tests

- [x] Unit tests: accept with motivation (audit capture), dispute filing (UBL CreditNote), rejection (GL reversal logic)
- [x] Integration test: full exception → resolution flow with payment block until resolved
