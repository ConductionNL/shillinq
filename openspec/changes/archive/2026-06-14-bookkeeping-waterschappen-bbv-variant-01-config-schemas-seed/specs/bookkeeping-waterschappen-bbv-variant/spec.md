# Spec Delta: bookkeeping-waterschappen-bbv-variant (member 01 — config schemas + seed)

## ADDED Requirements

### Requirement: The system SHALL declare a BBVProgramme register

The system SHALL declare a `BBVProgramme` register in
`lib/Settings/shillinq_register.json` holding the BBV policy programme
taxonomy per fiscal year. Each record SHALL carry `programmeName`,
`programmeCode`, `description`, `fiscalYear`, and a lifecycle `status`
(`active` | `archived`), with a many-to-one relation to
`Administration`. Programmes SHALL NOT be hard-deleted — only archived.

#### Scenario: Admin creates a new BBV programme

- **GIVEN** a logged-in Nextcloud admin for waterboard "Rijn & IJssel"
- **WHEN** the admin adds a programme with code "1.1.1", name "Core
  Administration", fiscal year 2026
- **THEN** a `BBVProgramme` record SHALL be created with
  `status = "active"`
- **AND** the programme SHALL appear in the programme master list.

### Requirement: The system SHALL declare a BudgetBBVMapping register

The system SHALL declare a `BudgetBBVMapping` register linking a GL
account to a BBV programme with an allocation percentage. Each record
SHALL carry `glAccountNumber`, `allocationPercentage` (0–100),
`effectiveFrom`, and an optional `effectiveTo`, with many-to-one
relations to `BBVProgramme`, `Account` (Chart of Accounts), and
`Administration`.

#### Scenario: Admin maps a GL account to a programme

- **GIVEN** fiscal year 2026 with programmes "1.1.1" and "1.2.1"
  declared
- **WHEN** the admin creates a mapping for GL 4100 → programme "1.1.1"
  at 50%, effective 2026-01-01
- **THEN** a `BudgetBBVMapping` record SHALL be created referencing the
  GL account, the programme, and the administration.

### Requirement: The system SHALL provide demo seed data for BBV programmes and mappings

The system SHALL load demo seed data (5 programmes + demo mappings for
fiscal 2026) via `ConfigurationService::importFromApp()` at install.
Re-import SHALL be idempotent and SHALL NOT create duplicate records.

#### Scenario: Seed data is idempotent on re-import

- **GIVEN** the demo seed has already been imported
- **WHEN** the import runs again
- **THEN** the 5 programmes and demo mappings SHALL remain unchanged
- **AND** no duplicate records SHALL be created.

### Requirement: The system SHALL provide a BBV integration-test scaffold

The system SHALL provide an integration-test scaffold that materialises
`BBVProgramme` records, `BudgetBBVMapping` records, and GL transaction
fixtures, reusable by later chain members for aggregation, service, and
end-to-end tests.

#### Scenario: Scaffold materialises programmes, mappings, and GL fixtures

- **GIVEN** the integration-test scaffold is configured
- **WHEN** a test requests the BBV fixture set
- **THEN** the scaffold SHALL create the seeded programmes, mappings,
  and GL transactions for fiscal 2026.
