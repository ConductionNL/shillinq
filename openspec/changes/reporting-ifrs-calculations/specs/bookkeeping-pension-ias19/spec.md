# Delta: bookkeeping-pension-ias19

## ADDED Requirements

### Requirement: An actuarial valuation books service cost, net interest and remeasurement (REQ-PEN-011)

Approving an actuarial valuation SHALL compute the service cost and the net interest for the profit and loss and the remeasurement for other comprehensive income, and SHALL post one balanced journal entry for them.

#### Scenario: A valuation is booked

- **GIVEN** a plan with service cost EUR 80,000, net interest EUR 12,000 and an actuarial loss of EUR 30,000 in the approved valuation
- **WHEN** the valuation is approved
- **THEN** one journal entry posts EUR 92,000 to the profit and loss and EUR 30,000 to other comprehensive income
