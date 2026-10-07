# contact-kvk-lookup Specification

## Purpose

A user adding a customer or a supplier looks the company up in the KvK
Handelsregister and the details fill in. From shillinq matrix row `plt-kvk`.

## ADDED Requirements

### Requirement: The KvK number of a customer and a supplier declares the KvK lookup (REQ-KVKL-001)

`CustomerMaster.kvkNumber` and `Payee.kvkNumber` SHALL declare
`x-openregister-property-source` with provider `kvk` and mode `default`, so the
schema published by openregister tells a form that the field is looked up in the
KvK. The declaration MUST pass openregister's schema validation on import.

#### Scenario: The published schema carries the lookup

- GIVEN shillinq's register is imported on an instance with openregister
- WHEN a client reads the CustomerMaster schema
- THEN `propertyMetadata` for `kvkNumber` names provider `kvk` and mode `default`

#### Scenario: The same holds for a supplier

- GIVEN the same instance
- WHEN a client reads the Payee schema
- THEN `propertyMetadata` for `kvkNumber` names provider `kvk` and mode `default`

### Requirement: Picking a company fills the empty details (REQ-KVKL-002)

The declaration SHALL carry a fill map from the KvK record to sibling properties:
for a customer the legal name and the trade name, for a supplier the name, the
trading name and the address. A fill MUST write only empty fields and MUST ask
before replacing a value the user already typed. The stored value is a copy: a
later change in the KvK MUST NOT change a saved record.

#### Scenario: Adding a supplier from the KvK

- GIVEN the KvK source is linked and the user opens New supplier
- WHEN the user types "Eneco" in KvK number and picks Eneco Energie B.V. (24502797)
- THEN KvK number reads 24502797, Name reads Eneco Energie B.V. and the address fills from the main branch
- AND saving stores those values on the Payee

#### Scenario: A typed name is not overwritten without asking

- GIVEN the user typed "Eneco" in Name before picking the company
- WHEN the user picks Eneco Energie B.V.
- THEN the form asks whether to replace "Eneco" with "Eneco Energie B.V." and keeps "Eneco" when the user declines

#### Scenario: A saved customer keeps its name after a KvK change

- GIVEN customer Acme Gemeente B.V. was saved from the KvK in March
- WHEN the company is renamed in the KvK in April
- THEN the customer record and its invoices still read Acme Gemeente B.V.

### Requirement: The KvK connection is on and says what it needs (REQ-KVKL-003)

The `kvk` entry in `lib/Settings/connections.json` SHALL be available, keep
`sourceTemplate` `kvk`, and carry an unconfigured message telling the admin to
link the KvK source. When no source is linked or the KvK does not answer, the
KvK number field MUST stay a plain text field that saves, with a message saying
why the lookup is not offered.

#### Scenario: An admin sees the connection to set up

- GIVEN a fresh install with no KvK source
- WHEN the admin opens the connections overview
- THEN KvK Handelsregister is listed as not set up yet, with the action to create its source from the template

#### Scenario: Without a source the field still works

- GIVEN no KvK source is linked
- WHEN a user adds a customer and types 12345678 in KvK number
- THEN the form says the KvK lookup is not set up and the customer saves with 12345678
