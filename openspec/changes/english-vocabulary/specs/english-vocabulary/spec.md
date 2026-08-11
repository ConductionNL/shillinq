## ADDED Requirements

### Requirement: Renames SHALL operate on schemas, not on files

A property rename SHALL be applied to every fragment declaring the affected schema, in
one change. A file-by-file sweep SHALL NOT be used, because register fragments merge by
concatenating list values and a partially-renamed schema is additive rather than broken.

#### Scenario: A schema declared by many fragments is renamed as one unit

- **WHEN** a property on a schema declared by more than one fragment is renamed
- **THEN** every declaring fragment SHALL be located first
- **AND** all of them SHALL be edited in the same change

#### Scenario: A partial rename is recognised as silently additive

- **WHEN** one declaring fragment retains the old property name
- **THEN** the merged schema SHALL be understood to carry both names
- **AND** the old name MAY persist in a concatenated required list without any error being raised

#### Scenario: Register validation runs per batch

- **WHEN** renames are applied in batches
- **THEN** register validation SHALL run after each batch
- **AND** it SHALL NOT be deferred until all batches are complete

#### Scenario: The declaring-fragment index precedes the work

- **WHEN** the change begins
- **THEN** an index of schema to declaring fragments SHALL be built
- **AND** that index SHALL be the work plan rather than the file listing

### Requirement: Statutory financial concepts SHALL take English names with markers

Concepts defined by Dutch financial, tax and employment statute SHALL be renamed to
English and SHALL carry a marker naming the instrument.

#### Scenario: A budget-law concept is renamed and marked

- **WHEN** a schema models a concept defined by the BBV or IV3
- **THEN** it SHALL take an English name
- **AND** it SHALL carry a marker naming the instrument

#### Scenario: Statutory classification codes are preserved as values

- **WHEN** a property holds a published statutory classification code
- **THEN** the code values SHALL be unchanged
- **AND** only the property name SHALL be renamed

#### Scenario: A statutory abbreviation is retained inside an English name

- **WHEN** an identifier names a statute by its own abbreviation
- **THEN** that abbreviation MAY be retained within the English identifier

### Requirement: Filing payload field names SHALL be preserved

Field names belonging to SBR/XBRL, Peppol/UBL or tax-authority filing formats SHALL be
preserved, and SHALL be classified before any rename touches them.

#### Scenario: A filing field is classified before renaming

- **WHEN** a property may belong to an external filing payload
- **THEN** it SHALL be classified before the rename
- **AND** an unclassified property SHALL NOT be renamed

#### Scenario: A filing still validates after the change

- **WHEN** the rename has landed
- **THEN** a filing SHALL be generated and validated against its schema
- **AND** a passing unit test SHALL NOT be accepted in its place

### Requirement: Names shared with a consuming app SHALL be agreed before renaming

Where shillinq and another app both declare a schema name, the English name SHALL be
agreed between them rather than chosen independently.

#### Scenario: A shared schema name is agreed with the consuming app

- **WHEN** shillinq and another app both declare a schema of the same name
- **THEN** the English name SHALL be agreed before either renames
- **AND** the two SHALL land in the same window

#### Scenario: A slug claimed by an in-flight branch is respected

- **WHEN** an open pull request will introduce a schema name on merge
- **THEN** that name SHALL be treated as taken
- **AND** other apps SHALL NOT claim it

### Requirement: Schemas that mirror another schema's field names SHALL be renamed together

Two schemas SHALL be renamed together where one deliberately mirrors the other's field
names so that a single guard serves both.

#### Scenario: A mirrored schema pair stays aligned

- **WHEN** a property is renamed on a schema whose field names another schema mirrors
- **THEN** the mirroring schema SHALL be renamed identically
- **AND** the shared guard SHALL be re-verified against both

#### Scenario: The mirroring relationship is documented

- **WHEN** the change identifies a mirrored pair
- **THEN** the relationship SHALL be recorded in the schema
- **AND** it SHALL NOT remain knowledge held only by the guard that depends on it

### Requirement: Renames SHALL NOT be applied by scripted substitution

Renames SHALL be applied as anchored, per-file edits. Pattern substitution across the
repository SHALL NOT be used.

#### Scenario: A bare-word substitution is rejected

- **WHEN** a rename could be applied by substituting a bare word
- **THEN** that approach SHALL be rejected
- **AND** anchored forms SHALL be edited instead, to avoid corrupting longer identifiers
  and documentation paths that contain the word
