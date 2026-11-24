# usage-metered-billing Specification (delta)

## Purpose

Metered usage reaches an invoice from a screen. From shillinq matrix row
`sal-usage-billing`.

## ADDED Requirements

### Requirement: Readings are entered and rated on a page (REQ-USB-001)

The app SHALL list meter readings per customer and period, SHALL let a user
import readings from a CSV file with a refusal per invalid row, and SHALL let
a user rate selected readings against their rate plan.

#### Scenario: A bookkeeper imports a month of readings

- GIVEN a rate plan "Opslag per GB" at EUR 0.12 per GB
- WHEN the bookkeeper imports a CSV with 3 rows for customer Hosting Noord, one of them with quantity -5
- THEN the meter readings page shows 2 new unrated readings
- AND the import result names row 3 as refused because the quantity is negative

#### Scenario: Rating a reading shows its amount

- GIVEN an unrated reading of 250 GB on "Opslag per GB"
- WHEN the bookkeeper chooses rate on it
- THEN the reading shows status rated and an amount of EUR 30.00

### Requirement: Usage is a billing model in the invoice generator (REQ-USB-002)

The invoice generator SHALL offer usage as a billing model, SHALL list the
customer's rated readings in the period that are not invoiced, SHALL put the
selected readings on the draft invoice as usage lines, and SHALL mark each of
them invoiced so it is not billed again.

#### Scenario: A bookkeeper bills September usage

- GIVEN two rated readings for Hosting Noord in September 2026 worth EUR 30.00 and EUR 12.00
- WHEN the bookkeeper opens the invoice generator, chooses usage, the customer and September, and saves the draft
- THEN the draft invoice has two usage lines totalling EUR 42.00 before VAT
- AND both readings show status invoiced with that invoice

#### Scenario: An invoiced reading is not offered again

- GIVEN a reading already invoiced
- WHEN the bookkeeper generates another usage invoice for the same period
- THEN that reading is not listed
