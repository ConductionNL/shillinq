# Spec Delta: Multi-PO Consolidated Invoice Matching

## ADDED Requirements

### Requirement: Match one invoice against many POs with disambiguation

The system SHALL match a single supplier invoice whose lines span
multiple purchase orders by searching for candidate (PO line, GRN line)
tuples per invoice line using product_code and date proximity. When more
than one candidate tuple matches a single invoice line, the system SHALL
present the candidates to the crediteuren-administrateur for confirmation
and SHALL store the chosen tuple in the `ThreeWayMatch` record. The system
SHALL create one `ThreeWayMatch` record per matched (PO line, GRN line,
invoice line) trio and SHALL process each independently through the
matching and exception workflow.

#### Scenario: Monthly invoice covering 12 POs

- **GIVEN** one monthly invoice covers 12 different POs received during the period
- **WHEN** the matching engine processes the invoice line items
- **THEN** it searches for matching (PO line, GRN line) tuples via product_code + date proximity, presents ambiguous matches to the crediteuren-administrateur for confirmation, creates an individual ThreeWayMatch per matched trio, and processes each independently (some auto-approve, others route to exception)

#### Scenario: Disambiguation choice is persisted

- **GIVEN** an invoice line matches two candidate POs for the same product
- **WHEN** the crediteuren-administrateur selects the correct (PO line, GRN line) tuple
- **THEN** the system stores the chosen tuple on the ThreeWayMatch record and evaluates that trio through the tolerance path
