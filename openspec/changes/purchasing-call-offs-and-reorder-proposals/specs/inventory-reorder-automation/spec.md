# Delta: inventory-reorder-automation

## ADDED Requirements

### Requirement: Stock at or below its reorder point proposes a purchase (REQ-IRA-011)

A daily job SHALL compare each reorder rule with the stock at its location. Stock at or below the reorder point SHALL raise a low-stock alert and a draft purchase order for the reorder quantity with the preferred supplier.

#### Scenario: Low stock proposes an order

- **GIVEN** a reorder rule of 10 units with reorder quantity 50 and 8 units in stock
- **WHEN** the daily job runs
- **THEN** a low-stock alert shows and a draft purchase order for 50 units waits for the buyer
