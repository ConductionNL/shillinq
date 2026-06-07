# Tasks — Member 08: Exception Resolution Workflow (code)

## ExceptionResolutionService

- [ ] Implement `acceptWithMotivation()` — record resolution_action + resolved_by + resolution_notes on ThreeWayMatch
- [ ] Implement `fileDispute()` — auto-generate UBL CreditNote request via openconnector, escalate to Inkoper notification queue
- [ ] Implement `rejectAndBlockPayment()` — mark invoice rejected, reverse partial GR/IR postings, restore stock if needed

## Notification integration

- [ ] Send exception alert to crediteuren-administrateur on match_status = exception_*
- [ ] Include side-by-side comparison in notification body; deep-link to ThreeWayMatchExceptionPanel

## Vue panel

- [ ] Create `ThreeWayMatchExceptionPanel.vue`: side-by-side PO↔GRN↔Invoice (qty, price, VAT, dates), human-readable divergence_details, three action buttons + motivation/notes input
- [ ] On resolution, update ThreeWayMatch with resolution_action + resolved_by + notes

## Tests

- [ ] Unit tests: accept with motivation (audit capture), dispute filing (UBL CreditNote), rejection (GL reversal logic)
- [ ] Integration test: full exception → resolution flow with payment block until resolved
