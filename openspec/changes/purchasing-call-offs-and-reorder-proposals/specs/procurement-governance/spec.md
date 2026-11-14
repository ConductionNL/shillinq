# Delta: procurement-governance

## ADDED Requirements

### Requirement: A purchase order is drawn against a framework agreement at its agreed prices (REQ-PG-005)

The purchase order form SHALL let a buyer pick an active framework agreement of the supplier. Lines for items on the agreement SHALL take the agreed price, and the order SHALL count against the agreement's ceiling.

#### Scenario: A call-off takes the agreed price

- **GIVEN** an agreement with a supplier at EUR 42 per box of paper and EUR 10,000 left under the ceiling
- **WHEN** a buyer orders 20 boxes against the agreement
- **THEN** the line price is EUR 42 and the agreement shows EUR 9,160 left
