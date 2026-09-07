# Spec: hours-to-humaniq (delta)

## ADDED Requirements

### Requirement: REQ-H2H-001 · Hours on a domain object live in humaniq

An hour worked on a domain object (a dossiq case, any case or matter object)
MUST be recorded as a humaniq `TimeEntry` carrying `domainObjectRef` and
`domainObjectType`. Shillinq MUST NOT hold the hour. Per ADR-107 decision 6,
the domain app supplies context and classification, humaniq supplies the wage
base, and shillinq supplies the ledger-derived additions and the booking.

#### Scenario: `UrenRegistratie` no longer declares a domain subject

- **GIVEN** the shillinq register fragments
- **WHEN** `lib/Settings/register.d/uren-domain-subject-link.json` is looked up
- **THEN** the file does not exist
- **AND** no fragment declares `subjectApp` or `subjectId` on `UrenRegistratie`
- @e2e exclude verified by file-existence and a static grep over the register
  fragments. A removed schema field has no browser surface of its own.

#### Scenario: A case shows hours booked in humaniq

- **GIVEN** a dossiq case with two humaniq `TimeEntry` records whose
  `domainObjectType` is `dossiq:case` and whose `domainObjectRef` is the
  case uuid
- **WHEN** a handler opens the case detail page
- **THEN** the hours tile shows the sum of those two entries

### Requirement: REQ-H2H-002 · An hours surface never reports zero for a missing app

Every surface that shows booked hours MUST distinguish "humaniq is absent" from
"no hours were booked". When humaniq is not installed or not reachable, the
surface MUST render a named empty state that says so. It MUST NOT render 0.

This requirement exists because the behaviour it forbids already shipped.
Dossiq's `case-kpis-hours` tile summed a shillinq field no code ever wrote, so
it reported 0 hours on every case in every install and looked correct doing it.

#### Scenario: Humaniq is not installed

- **GIVEN** an install with humaniq disabled
- **WHEN** a handler opens a case detail page
- **THEN** the hours tile names humaniq as unavailable
- **AND** the tile does not show a numeric total

#### Scenario: Humaniq is installed and the case has no hours

- **GIVEN** an install with humaniq enabled and a case with no `TimeEntry`
- **WHEN** a handler opens the case detail page
- **THEN** the hours tile shows 0

### Requirement: REQ-H2H-003 · Statutory hour counts are proven before cutover

The WBSO export guard and the urencriterium guard both feed a tax position. For
each, the humaniq-backed count MUST be proven equal to the `UrenRegistratie`
count over the same period and administration before the old read path is
removed.

A wrong urencriterium count costs a self-employed person a real deduction, so
"the tests pass" is not the bar. The two counts must be compared directly.

#### Scenario: Both guards agree across the cutover

- **GIVEN** an administration with hours booked over a full calendar year
- **WHEN** the urencriterium total is computed from `UrenRegistratie` and from
  humaniq `TimeEntry` over the same year
- **THEN** the two totals are equal
- @e2e exclude a numeric equivalence check between two service read paths, with
  no UI of its own. Covered by an integration test.
