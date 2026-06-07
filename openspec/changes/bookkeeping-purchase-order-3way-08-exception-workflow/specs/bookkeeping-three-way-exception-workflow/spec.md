# Spec Delta: 3-way Match Exception Workflow

## ADDED Requirements

### Requirement: Route out-of-tolerance matches to a resolution workflow

When a `ThreeWayMatch` has an `exception_*` match_status the system SHALL
notify the crediteuren-administrateur (and any additional approver named
in the applicable ToleranceProfile exception_routing), present a
side-by-side comparison of the PO, GRN, and invoice lines with the
divergence_details, and offer three resolution actions: accept with
motivation, file dispute, or reject and block payment. The system SHALL
block payment until the exception is resolved and SHALL record
resolved_by, resolution_action, resolution_notes, and resolved_at on the
ThreeWayMatch. Filing a dispute SHALL auto-generate a UBL CreditNote
request via openconnector and escalate to the Inkoper queue. Rejecting
SHALL mark the invoice rejected, reverse any partial GR/IR posting, and
restore stock if needed.

#### Scenario: Price exception routed and accepted with motivation

- **GIVEN** an invoice for €19,250 arrives against a PO of €18,500 (4.1% variance)
- **WHEN** the matching engine marks match_status="exception_price" and routes to the crediteuren-administrateur
- **THEN** the system displays the side-by-side PO/GRN/Invoice comparison, blocks payment, and on "accept with motivation" records resolution_action="accepted_with_motivation", resolved_by, and resolution_notes

#### Scenario: Dispute generates a UBL CreditNote request

- **GIVEN** an exception_price ThreeWayMatch awaiting resolution
- **WHEN** the crediteuren-administrateur files a dispute
- **THEN** the system auto-generates a UBL CreditNote request via openconnector, escalates to the Inkoper queue, and keeps payment blocked
