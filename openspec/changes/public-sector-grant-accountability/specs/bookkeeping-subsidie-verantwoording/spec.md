# bookkeeping-subsidie-verantwoording Specification (delta)

## Purpose

A grant is settled and accounted for from its page. From shillinq matrix rows `pub-subsidies`, `pub-sisa`, `pub-eu-funds`.

## ADDED Requirements

### Requirement: Determining a grant settles the difference (REQ-SUBV-011)

The app SHALL, when a grant is determined, record the determined amount, compute the difference with the amount paid out, and write a draft reclaim invoice for a grant given or a draft payable for a grant received.

#### Scenario: A grants officer determines a grant below what was paid

- GIVEN a grant given to a sports club with EUR 10,000 paid out
- WHEN the grants officer determines it at EUR 8,500 on the grant page
- THEN the grant shows EUR 1,500 reclaimed
- AND a draft invoice of EUR 1,500 to the sports club names the grant

### Requirement: The accountability report is generated from the grant (REQ-SUBV-012)

The app SHALL generate the accountability report of a grant from its page and SHALL ask for an auditor statement when the granted amount requires one.

#### Scenario: A grants officer generates the report

- GIVEN a grant of EUR 150,000
- WHEN the grants officer chooses generate report
- THEN a report is stored for the grant
- AND an auditor statement is marked as required
