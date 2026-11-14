# bookkeeping-bado-controleprotocol Specification (delta)

## Purpose

The auditor gets reproducible samples and the aggregated findings. From shillinq matrix rows `pub-rechtmatigheid`, `pub-audit-protocol`, `pub-ensia`.

## ADDED Requirements

### Requirement: A sample is drawn reproducibly (REQ-011)

The app SHALL draw a sample of the requested size from a population of posted lines with a stored seed, and a draw with the same seed SHALL return the same lines.

#### Scenario: An auditor draws a sample of purchase invoices

- GIVEN a protocol and a population of 4,000 posted purchase lines
- WHEN the auditor draws 25 lines with seed 20261
- THEN a sample of 25 line ids is stored with the seed
- AND drawing again with seed 20261 returns the same 25 lines

### Requirement: The protocol page shows the aggregation (REQ-012)

The app SHALL show on the protocol page the findings per topic, their severity and the proposed opinion.

#### Scenario: A controller reads the proposed opinion

- GIVEN a protocol with two findings above the tolerance
- WHEN the controller opens the protocol
- THEN the aggregation lists both findings with their severity and the proposed opinion
