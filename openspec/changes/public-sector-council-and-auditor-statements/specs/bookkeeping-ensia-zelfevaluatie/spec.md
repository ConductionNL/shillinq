# bookkeeping-ensia-zelfevaluatie Specification (delta)

## Purpose

The ENSIA cycle produces its findings, statement and XML. From shillinq matrix rows `pub-rechtmatigheid`, `pub-audit-protocol`, `pub-ensia`.

## ADDED Requirements

### Requirement: Transitions produce the findings and the statement (REQ-ENSIA-011)

The app SHALL create the findings from the answers when a cycle moves to peer review, and SHALL render the college statement when it moves to college approval.

#### Scenario: A process owner moves the cycle forward

- GIVEN a cycle with two questions answered no
- WHEN the process owner moves it to peer review
- THEN two findings exist for the cycle
- AND moving it to college approval stores the statement file

### Requirement: The submission XML is downloaded (REQ-ENSIA-012)

The app SHALL produce the ENSIA XML when a cycle is submitted and SHALL let the process owner download it.

#### Scenario: A process owner downloads the XML

- GIVEN a cycle approved by the college
- WHEN the process owner submits it and chooses download
- THEN an XML file downloads for that cycle
