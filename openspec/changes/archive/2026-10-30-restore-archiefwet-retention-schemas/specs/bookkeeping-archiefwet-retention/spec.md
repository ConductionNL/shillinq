# bookkeeping-archiefwet-retention

## ADDED Requirements

### Requirement: The retention schemas the code uses are in the register (REQ-RET-014)

The `shillinq` register SHALL carry the schemas `RetentionPolicy`, `RetentionSchedule` and `DocumentRetention`, and SHALL declare them in its `schemas` list. Every default policy in `lib/Settings/seeds/retention-policies.json` SHALL be a valid `RetentionPolicy`, including the `slug` the seeder matches on, so seeding on install and on every repair run SHALL create each default once and SHALL NOT fail.

#### Scenario: A fresh install seeds the three default policies
- **GIVEN** a fresh instance with OpenRegister and shillinq
- **WHEN** shillinq is enabled and its repair step runs
- **THEN** `RetentionPolicy` SHALL hold `default-financial-5yr`, `tax-documents-7yr` and `general-admin-3yr`, and "retention policy seeding failed" SHALL NOT be logged
- @e2e exclude {install-time seeding; the schema and seed fit are covered by ArchiefwetRetentionSchemasTest}

#### Scenario: A second repair run seeds nothing
- **GIVEN** the three default policies seeded
- **WHEN** the repair step runs again
- **THEN** no policy SHALL be created and all three SHALL be reported as skipped
- @e2e exclude {covered by SettingsServiceTest}
