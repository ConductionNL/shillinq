# integration-catalogue Specification

## Purpose

A shillinq user finds ready-made integrations for bookkeeping on one page,
drawn from integriq's catalogue. From shillinq matrix row `plt-marketplace`.

## ADDED Requirements

### Requirement: An Integrations page lists bookkeeping catalogue items (REQ-PIC-001)

Shillinq SHALL provide an Integrations page listing, as cards, integriq's
catalogue items in the bank, payments, e-invoicing, tax filing, commerce and
payroll categories, each with its name, description, category, status and a
link to the item in integriq.

#### Scenario: An administrator looks for a payment provider

- GIVEN integriq's catalogue holds a Mollie item in category Payments with status dormant
- WHEN an administrator opens the Integrations page in shillinq's settings
- THEN a Mollie card shows status dormant and links to its detail in integriq

### Requirement: Families without a catalogue item are shown as coming (REQ-PIC-002)

The page SHALL list shillinq's connection families that have no catalogue
item as not available yet, with their reason.

#### Scenario: Digipoort is not in the catalogue

- GIVEN the family digipoort-sbr has no catalogue item
- WHEN the administrator opens the Integrations page
- THEN digipoort-sbr is listed under not available yet with its note

### Requirement: Without integriq the page says so (REQ-PIC-003)

When integriq is not installed, the Integrations page SHALL show that
integriq is needed instead of an empty grid.

#### Scenario: An instance without integriq

- GIVEN an instance without integriq
- WHEN the administrator opens the Integrations page
- THEN the page states that integrations come from integriq
