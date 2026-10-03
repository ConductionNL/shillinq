# bookkeeping-cost-centers-dimensions Specification (delta)

## Purpose

Segment reports show a signed profit and loss from posted lines only. From
shillinq matrix rows `led-dimensions` and `rep-segment-pnl`.

## ADDED Requirements

### Requirement: A ledger line carries a signed amount (REQ-RSR-001)

`GLLine` SHALL declare `signedAmount`, equal to `amount` for a credit line and
to minus `amount` for a debit line.

#### Scenario: A cost line is negative

- GIVEN a posted debit line of EUR 3,000 on 4100 Huisvesting
- WHEN its signed amount is read
- THEN it is minus EUR 3,000

### Requirement: Only posted profit and loss lines count in a segment result (REQ-RSR-002)

When a transaction posts, each of its lines SHALL be stamped with its
account class and as counting in results. When a transaction is reversed,
its lines and the lines of the transaction it reverses SHALL stop counting.
Segment aggregations MUST include only lines of class `pnl` that count.

#### Scenario: A bank line and a draft are left out

- GIVEN posted lines for KP-300 of EUR 40,000 credit on 8200, EUR 25,000 debit on 4000, EUR 3,000 debit on 4100 and EUR 40,000 debit on bank account 1100, and a draft line of EUR 5,000 debit on 4000
- WHEN the segment result for KP-300 in September 2026 is read
- THEN it is EUR 12,000

### Requirement: The segment dashboard shows revenue, costs and result (REQ-RSR-003)

The segment dashboard SHALL show, per segment of the chosen type (cost
centre, cost object, project, analytical dimension) and for the chosen
period, the revenue, the costs and the result.

#### Scenario: A manager reads Sociaal Domein's September

- GIVEN a manager on the segment P&L dashboard with segment type cost centre and period September 2026
- WHEN the table loads
- THEN the row for KP-300 Sociaal Domein shows revenue EUR 40,000, costs EUR 28,000 and result EUR 12,000
