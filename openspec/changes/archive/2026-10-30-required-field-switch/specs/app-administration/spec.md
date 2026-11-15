# app-administration Specification (delta)

## Purpose

An administrator switches a required field off and on again without
retiring it.

## ADDED Requirements

### Requirement: A required field can be switched off (REQ-RFS-001)

A `FieldRequirement` SHALL carry an `enabled` field, true by default. A save
MUST NOT be checked against a requirement with `enabled` false, and a
requirement without the field SHALL count as switched on. The Required fields
tab of the admin settings SHALL list the active requirements of the
administrator's administrations, each titled by its field and record type,
with a switch that writes `enabled` at once.

#### Scenario: The cost centre check is switched off

- GIVEN cost centre required on supplier invoices in Gemeente Voorbeeld
- WHEN an administrator switches "Cost Center on Supplier invoice" off on the Required fields tab
- THEN a bookkeeper's supplier invoice without a cost centre is saved
- AND the requirement and its reason are kept

@e2e exclude the save check is backend behaviour, covered by FieldRequirementListenerTest::testASwitchedOffRequirementIsNotChecked

#### Scenario: The tab lists the requirements with their switch

- GIVEN two active requirements in Gemeente Voorbeeld, one switched off, and one retired requirement
- WHEN an administrator opens the Required fields tab
- THEN two rows are listed, the first switched on and the second off

@e2e exclude list contents are covered by FieldRequirementListenerTest::testTheListHoldsActiveRequirementsSwitchedOnAndOff and FieldRequirementControllerTest; the widget wiring by tests/vitest/adminSettingsTabs.spec.js
