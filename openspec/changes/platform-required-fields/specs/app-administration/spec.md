# app-administration Specification (delta)

## Purpose

An administrator decides which extra fields must be filled in, per
administration. From shillinq matrix row `plt-required-fields`.

## ADDED Requirements

### Requirement: An administrator marks fields as required (REQ-PRF-001)

Shillinq SHALL provide a required fields settings page listing, per schema,
the fields its forms show, with shipped required fields locked and the others
toggleable with a reason, stored as `FieldRequirement` records of the
administration.

#### Scenario: Cost centre becomes required on purchase invoices

- GIVEN an administrator of Gemeente Voorbeeld on the required fields page
- WHEN they choose schema SupplierInvoice, switch on cost centre with reason "Elke inkoopfactuur wordt op een kostenplaats verantwoord" and save
- THEN cost centre is listed as required for Gemeente Voorbeeld only

### Requirement: A save missing a required field is refused (REQ-PRF-002)

Creating or updating an object of that schema in that administration
without the required field MUST be refused, naming the missing fields and
their reasons. Writes by shillinq's own services SHALL NOT be refused by
this check.

#### Scenario: A bookkeeper saves an invoice without a cost centre

- GIVEN cost centre required on supplier invoices in Gemeente Voorbeeld
- WHEN a bookkeeper saves a supplier invoice without a cost centre on the supplier invoice form
- THEN the save is refused and the form shows that cost centre is required, with the reason

#### Scenario: Another administration is not affected

- GIVEN the same requirement exists only for Gemeente Voorbeeld
- WHEN a bookkeeper of Adviesbureau Van Dijk saves a supplier invoice without a cost centre
- THEN it is saved
