# sales-quote-order-invoice Specification (delta)

## Purpose

An accepted quote becomes a sales order and a sales order becomes an
invoice without retyping lines. From shillinq matrix rows
`sal-quote-to-invoice` and `sal-orders`.

## ADDED Requirements

### Requirement: An accepted quote becomes a sales order (REQ-QTI-001)

The app SHALL let a user turn an accepted quote into a sales order that
carries every quote line, and SHALL refuse a quote that is not accepted or
that already has a sales order.

#### Scenario: A salesperson orders an accepted quote

- GIVEN an accepted quote Q-2026-031 for Bakkerij De Korenaar with two lines, 10 oven trays at EUR 45 and 1 installation at EUR 300
- WHEN the salesperson chooses "Create sales order" on the quote page
- THEN a sales order in state draft opens with the same customer and both lines at the same quantities and prices
- AND the order names Q-2026-031 as its source quote

#### Scenario: A quote that is still being negotiated is refused

- GIVEN a quote in state sent
- WHEN a user asks for a sales order from it
- THEN the app refuses and says the quote must be accepted first

### Requirement: A sales order has its own states (REQ-QTI-002)

The app SHALL record a customer order as a sales order on the Orders page
with the states draft, confirmed, partly invoiced, invoiced and cancelled,
and SHALL let users filter the Orders page to sales orders.

#### Scenario: A user confirms a sales order

- GIVEN a draft sales order with lines
- WHEN the user confirms it on the order page
- THEN the order shows state confirmed
- AND the Orders page filtered to sales lists it

### Requirement: A confirmed sales order becomes a draft invoice (REQ-QTI-003)

The app SHALL write a draft sales invoice from a confirmed sales order with
one line per order line for the quantity not yet invoiced, SHALL record the
invoiced quantity on each order line, and SHALL refuse an order with nothing
left to invoice.

#### Scenario: A bookkeeper invoices an order in two parts

- GIVEN a confirmed sales order with a line of 10 oven trays of which 4 are already invoiced
- WHEN the bookkeeper chooses "Invoice order"
- THEN a draft sales invoice opens with a line of 6 oven trays at the order price
- AND the order shows state invoiced

#### Scenario: A fully invoiced order is refused

- GIVEN a sales order in state invoiced
- WHEN the bookkeeper chooses "Invoice order"
- THEN the app refuses and says every line is already invoiced
