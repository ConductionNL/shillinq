# bookkeeping-gr-consolidation Specification (delta)

## Purpose

Costs of a joint arrangement are split over its participants. From shillinq matrix rows `pub-bbv`, `pub-gr`, `pub-market-separation`.

## ADDED Requirements

### Requirement: An allocation run splits a key's costs over the participants (REQ-GRC-007)

The app SHALL propose, for a key and a period, one line per active participant with its share of the costs posted on the key's cost clusters, and the lines SHALL add up to the total to the cent.

#### Scenario: A controller splits the shared service costs

- GIVEN three active participants with shares 50, 30 and 20 percent and EUR 100,000.01 posted on the key's cost clusters in the first quarter
- WHEN the controller proposes an allocation run for that key and quarter
- THEN a draft run shows EUR 50,000.01, EUR 30,000.00 and EUR 20,000.00
- AND the lines add up to EUR 100,000.01

### Requirement: A confirmed run invoices the participants (REQ-GRC-008)

The app SHALL lock a confirmed run and SHALL write one draft contribution invoice per participant line, and SHALL refuse a second run for the same key and period.

#### Scenario: A controller invoices the participants

- GIVEN a confirmed allocation run with three lines
- WHEN the controller chooses invoice participants
- THEN three draft invoices exist, one per participant, each naming the run
- AND proposing the same key and quarter again is refused with the confirmed run named
