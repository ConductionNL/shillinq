# Delta: bookkeeping-archiefwet-retention

## ADDED Requirements

### Requirement: A list shows the records whose retention period has ended (REQ-RET-015)

The system SHALL list every retention-bound record whose retention end date
lies before today. The end date is the record's reference date plus the
retention period of its `RetentionRule`. The list SHALL show the register,
the record, the rule, the end date and the disposition. A record under a
legal hold SHALL NOT appear on the list.

#### Scenario: A posted transaction from 2018 is due for destruction

- **GIVEN** a `GLTransaction` posted on 15 March 2018 under rule 5.1.2 (7 years, destroy)
- **WHEN** a bookkeeper opens the list of records due for destruction on 12 November 2026
- **THEN** the transaction is listed with end date 15 March 2025 and disposition destroy

#### Scenario: A recent transaction stays off the list

- **GIVEN** a `GLTransaction` posted on 1 February 2022 under rule 5.1.2
- **WHEN** the bookkeeper opens the list on 12 November 2026
- **THEN** the transaction is not listed

#### Scenario: A record under a legal hold stays off the list

- **GIVEN** an `ARInvoice` whose retention ended in 2024 and which carries a legal hold
- **WHEN** the bookkeeper opens the list
- **THEN** the invoice is not listed
