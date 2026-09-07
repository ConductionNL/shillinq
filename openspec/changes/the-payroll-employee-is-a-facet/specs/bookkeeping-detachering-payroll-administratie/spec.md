# Bookkeeping detachering payroll administratie

## MODIFIED Requirements

### Requirement: The payroll employee is a facet of a person (REQ-DPA-020)

The payroll record's slug SHALL be `payrollEmployee` and SHALL NOT be
`Employee`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `Employee` resolved to this record or to humaniq's
depending on which row was reached first.

The record SHALL carry an `employee` property holding the UUID of the humaniq
`Employee` it belongs to. humaniq owns the person; this schema owns the payroll
and detachering facet of one.

That reference SHALL be a plain uuid string and SHALL NOT be a `$ref`. humaniq's
register is a different register, and ADR-062 rule 7 gives a cross-register
target no `$ref`.

The reference MAY be empty. Without humaniq there is no person record to point
at, and the payroll record SHALL still stand on `employeeNumber` alone.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows. The import matches an existing schema by
`(application, slug)` and CREATES a new one when that misses, so a fragment-only
rename orphans the old schema and every object on it without erroring. Without
the application filter the step would rename humaniq's row, which is the damage
it exists to prevent.

Lookups that target humaniq's `Employee` SHALL keep naming `Employee`. That slug
does not move, and `HrmqCostRateAdapter` resolves it register-scoped.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a shillinq-owned `Employee` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: humaniq's row is not touched

- **WHEN** the step looks for rows to rename
- **THEN** the query is filtered on this app's application id.

#### Scenario: The renamed schema points at its owner

- **WHEN** the merged fragment is read
- **THEN** `payrollEmployee` is declared, `Employee` is not, and
  `payrollEmployee` carries an `employee` property.
