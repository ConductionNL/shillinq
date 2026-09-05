# Bookkeeping verplichtingenadministratie

## MODIFIED Requirements

### Requirement: The spending mandate is namespaced (REQ-VPA-035)

The mandate schema slug SHALL be `SpendingMandate` and SHALL NOT be `Mandate`
or `Mandaat`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Mandate` resolved to this app's spending ceiling or to
dossiq's administrative-law mandaat depending on which row was reached first.
The two share zero declared fields, so they are renamed apart rather than folded
onto one owner.

`RenameCommitmentSchemas` SHALL map BOTH `Mandaat` and `Mandate` to
`SpendingMandate`. An install still on Dutch reaches the namespaced slug in one
move; one already migrated to `Mandate` follows behind. Mapping only the Dutch
source would strand every install that already ran the vocabulary pass.

When both source slugs exist the step SHALL refuse rather than merge, because
each may own objects.

#### Scenario: A Dutch install lands on the namespaced slug

- **GIVEN** an install carrying `Mandaat`
- **WHEN** the repair step runs
- **THEN** the row is renamed to `SpendingMandate`, keeping its schema id.

#### Scenario: An already-English install follows behind

- **GIVEN** an install carrying `Mandate`
- **WHEN** the repair step runs
- **THEN** the row is renamed to `SpendingMandate`.

#### Scenario: The seeded mandates are actually checked

- **WHEN** the seeded mandates are validated against the commitment kind enum
- **THEN** at least one seeded mandate is checked, so the assertion cannot pass
  by matching nothing.
