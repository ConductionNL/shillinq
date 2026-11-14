# inventory-valuation-fifo-avg Specification (delta)

## Purpose

Goods received through a goods receipt note add their cost layers, so issues
are costed from purchases. From shillinq matrix rows `inv-valuation` and
`inv-cogs`.

## ADDED Requirements

### Requirement: An accepted receipt adds its cost layer (REQ-IRCL-001)

When a goods receipt note is accepted, each receipt stock move SHALL be
created as a draft and posted through the transition engine, so that the
valuation engine adds a FIFO layer or updates the average cost at the PO
line's unit cost. The receipt's ledger entry MUST be posted once.

#### Scenario: Flour is received

- GIVEN FIFO product Tarwebloem 25 kg with no stock
- WHEN a warehouse employee accepts a goods receipt note of 40 bags at EUR 18.50 on the goods receipt page
- THEN the valuation report shows 40 bags worth EUR 740
- AND the general ledger shows one GR/IR entry for the receipt

### Requirement: An issue is costed from the receipt layers (REQ-IRCL-002)

The cost of goods sold of an issue SHALL be taken from the layers that
receipts added.

#### Scenario: Selling 50 bags across two layers

- GIVEN layers of 40 bags at EUR 18.50 and 40 bags at EUR 19.25
- WHEN a delivery of 50 bags is dispatched
- THEN the posted cost of goods sold is EUR 932.50

### Requirement: Past receipts get their layers once (REQ-IRCL-003)

A repair step SHALL add the missing layers for receipts posted before this
change, in posting order, without posting to the ledger, and SHALL list the
issues that were costed without those layers.

#### Scenario: The backfill lists an undercosted issue

- GIVEN a receipt posted last month without a layer and an issue costed at zero after it
- WHEN the repair runs
- THEN the receipt's layer exists and the issue is listed with its old and its layer-based cost

### Requirement: The valuation report has a page (REQ-IRCL-004)

The inventory menu SHALL offer a stock valuation page showing quantity,
value and unit cost per product and location on a chosen date.

#### Scenario: A controller reads the September valuation

- GIVEN the flour receipts and issue above
- WHEN the controller opens the stock valuation page for 2026-09-30
- THEN Tarwebloem 25 kg shows 30 bags worth EUR 577.50
