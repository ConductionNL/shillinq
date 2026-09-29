# bookkeeping-market-government-separation Specification (delta)

## Purpose

The integral cost price is calculated and cross subsidy is flagged. From shillinq matrix rows `pub-bbv`, `pub-gr`, `pub-market-separation`.

## ADDED Requirements

### Requirement: A cost price is calculated from the books (REQ-WMO-013)

The app SHALL calculate the integral cost price of a commercial activity for a period from its posted direct costs, its share of overhead, the cost of capital and the profit markup, and SHALL store the result with its components.

#### Scenario: A controller calculates the cost price of the sports hall rental

- GIVEN a commercial activity with posted direct costs and an overhead key
- WHEN the controller chooses calculate for 2026 on the activity page
- THEN an integral cost price for 2026 is stored with direct costs, overhead, cost of capital and markup
- AND it says whether the applied rate covers the cost price

### Requirement: Cross subsidy signals are logged (REQ-WMO-014)

The app SHALL check every active commercial activity daily for the cross subsidy signals and SHALL write one alert per signal, without repeating an alert that is still open.

#### Scenario: An activity has run at a loss for a year

- GIVEN an activity whose cost prices show a loss for twelve months in a row
- WHEN the daily check runs twice
- THEN one open loss financing alert exists for that activity
