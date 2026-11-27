# bookkeeping-bbv-compliance Specification (delta)

## Purpose

Every posted line reaches a task field, and the dashboard shows the result. From shillinq matrix rows `pub-bbv`, `pub-gr`, `pub-market-separation`.

## ADDED Requirements

### Requirement: Realisation is shown per task field (REQ-BBV-012)

The app SHALL show, for a municipal administration and a year, the expenses and income per BBV task field from posted GL lines through the account mapping, and SHALL list every account that carries postings but no mapping.

#### Scenario: A controller reads the year per task field

- GIVEN posted lines of EUR 40,000 on an account mapped to task field 0.1 and EUR 5,000 on an unmapped account
- WHEN the controller opens the BBV dashboard for 2026
- THEN task field 0.1 shows EUR 40,000 in expenses
- AND the unmapped account is listed with EUR 5,000 and a link to the BBV mapping page
