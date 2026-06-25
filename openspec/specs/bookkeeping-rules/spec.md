# bookkeeping-rules Specification

## Purpose
TBD - created by archiving change bookkeeping-rule-corpus. Update Purpose after archive.
## Requirements
### Requirement: REQ-BKR-001 — Versioned static rule corpus

The system SHALL provide a versioned static rule corpus loaded by
`OCA\Shillinq\Standards\RuleCatalogue` from per-domain JSON files under
`lib/Standards/rules/` (one obligation per rule). The corpus SHALL be read-only
reference data (laws/standards, identical for every tenant, changing only with
releases), NOT OpenRegister config, and SHALL be the machine-readable source for
turning rules into validations and specs.

#### Scenario: corpus loads with a version

- **WHEN** business logic calls `RuleCatalogue::version()` and `RuleCatalogue::count()`
- **THEN** it returns a version stamp and the merged rule total across all domain files

### Requirement: REQ-BKR-002 — Rule contract and uniqueness

Every rule SHALL carry `id`, `domain`, `jurisdiction`, `framework`, `source`,
`statement`, `severity` (`mandatory` | `conditional` | `recommended`),
`machineCheckable` (boolean), `effectiveDate` and `sourceUrl`, and every `id`
SHALL be globally unique across all domain files. Malformed rules SHALL be skipped
by the loader rather than break it.

#### Scenario: ids are globally unique and rules well-formed

- **WHEN** the full corpus is loaded
- **THEN** every rule has the required fields, a valid severity, and no id appears twice

### Requirement: REQ-BKR-003 — Domain and jurisdiction coverage

The corpus SHALL cover the operative bookkeeping domains — invoicing, vat,
retention, ledger-integrity, recognition, measurement, presentation, disclosure,
reporting, chart-of-accounts and tax — across the international (IFRS), US (GAAP /
ASC) and national (NL, DE, FR, IT, ES, BE) frameworks, and SHALL expose query
helpers `byDomain()`, `byFramework()`, `byJurisdiction()` and `machineCheckable()`.
`byJurisdiction()` SHALL include EU-wide and global rules for an EU member and
SHALL NOT leak EU-wide rules into a non-EU (US) query.

#### Scenario: jurisdiction resolution

- **WHEN** `RuleCatalogue::byJurisdiction("NL")` is called
- **THEN** it returns NL + EU-wide + global rules, while `byJurisdiction("US")` excludes EU-wide rules

#### Scenario: machine-checkable subset

- **WHEN** `RuleCatalogue::machineCheckable()` is called
- **THEN** it returns only rules flagged `machineCheckable: true`, a subset of the corpus

### Requirement: REQ-BKR-004 — Sourced, not fabricated

Every rule SHALL carry a real `source` citation and `sourceUrl`; a rule whose
exact citation cannot be verified SHALL be flagged with `(verify)` in its
statement rather than dropped or given a fabricated citation.

#### Scenario: unverified citation is flagged

- **WHEN** a rule's exact article/paragraph could not be confirmed against the primary text
- **THEN** its statement ends with `(verify)` and `source` is set to the best-known level, never invented

### Requirement: REQ-BKR-005 — Behaviour, not navigation

The rule corpus SHALL be consumed as behaviour / reference data by business logic
and SHALL NOT add menu entries or pages. Surfacing rule status to a user SHALL be
report-only and is out of scope unless a report is explicitly required.

#### Scenario: no navigation for the corpus

- **WHEN** the corpus is added or extended
- **THEN** no menu item or page is introduced — only static data + accessors consumed by behaviour

