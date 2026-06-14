# Spec: bookkeeping-archiefwet-retention

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-general-ledger (T1), consumes OR's lifecycle retention abstraction

## ADDED Requirements

### Requirement: REQ-ARC-001: Records retention SHALL be enforced via OpenRegister's lifecycle retention abstraction, NOT reimplemented in shillinq

Per Archiefwet 1995 + the actuele Selectielijst Gemeenten 2020 (and
sector-specific selectielijsten for provincies and waterschappen),
every shillinq-managed record carries a retention obligation. The
enforcement (purge, archive, anonymise on expiry) MUST consume
OpenRegister's `x-openregister-lifecycle.retention` abstraction
per ADR-022; shillinq MUST NOT implement a parallel retention
sweep, retention job, or retention enforcement service.

Per ADR-031, retention rules are **schema metadata** — declared
on each register's `x-openregister-lifecycle.retention` field
referencing a `RetentionRule` record by code. The runtime
enforcement is OR's responsibility.

Statutory basis: Archiefwet 1995 art. 3 + 5 + Selectielijst
Gemeenten 2020 + Archiefbesluit 1995.

#### Scenario: Reviewer confirms no parallel retention sweep

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Cron/*Retention*Job.php`,
  `lib/Service/*Retention*Service.php`, or any `lib/` class with
  `retention` or `archive` in the name
- **THEN** no such classes SHALL exist — retention enforcement is
  entirely OR's.

### Requirement: REQ-ARC-002: Retention rules SHALL ship as a `RetentionRule` register seeded from `selectielijst-gemeenten-2020.json`

A `RetentionRule` register MUST be declared and seeded from
`lib/Settings/seeds/selectielijst-gemeenten-2020.json`. Each rule
record carries:

Schema.org annotation: `schema:DefinedTerm` (a policy declaration — a coded retention classifier from the statutory Selectielijst, with operator-authored overrides forming an extended controlled vocabulary).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `selectielijstCode` | string | Yes | The Selectielijst classifier (e.g. `5.1.2`, `3.5.1`, `1.1.1`) |
| `description` | string | Yes | Plain-Dutch description |
| `recordCategory` | enum | Yes | `financial`, `subsidie`, `personeel`, `algemeen-bestuur`, `verantwoording`, `correspondentie`, `archief` |
| `retentionYears` | integer | No | Absolute retention in years from record creation |
| `retentionTrigger` | string | No | Relative retention (e.g. `"10 years after vaststellingDate"`) — mutually exclusive with `retentionYears` |
| `disposition` | enum | Yes | `destroy`, `archive`, `anonymise`, `keep_indefinite` |
| `legalBasis` | string | Yes | Citation: Archiefwet article + Selectielijst paragraph |
| `effectiveFrom` / `effectiveTo` | date | Yes / No | Validity window for the rule |
| `_meta.source` | string | No | `"seeded"` or `"operator-edited"` |

Per ADR-022, this is a register — NOT an app-local enum, NOT a
config file. Operators MAY add administration-specific overrides
that the OR retention engine prefers over the seeded default.

#### Scenario: The seed parses and validates

- **GIVEN** `selectielijst-gemeenten-2020.json`
- **WHEN** parsed as JSON
- **THEN** every record MUST validate against the `RetentionRule`
  schema AND the file MUST cover at minimum: financial records,
  subsidie records, personeel records, and bestuur records.

#### Scenario: An operator override prevails over the seeded default

- **GIVEN** the seed declares `selectielijstCode: "5.1.2"` with
  `retentionYears: 7` AND an operator adds an override with the
  same code but `retentionYears: 10` for administration `gem-a`
- **WHEN** OR's retention engine sweeps for `gem-a`'s records
- **THEN** the 10-year override MUST be applied (per ADR-022
  override convention).

### Requirement: REQ-ARC-003: Every shillinq schema SHALL declare its retention rule reference

Each schema declared by shillinq's registers (T1 `Account`,
`GLTransaction`, `GLLine`, `JournalEntry`; T2 `Invoice`,
`Payment`, `BankTransaction`, `FiscalPeriod`, `TrialBalance`,
`FinancialStatement`; T3 every register declared by the other 9
specs) MUST declare an `x-openregister-lifecycle.retention` block
referencing a rule by `selectielijstCode`. The expected mapping:

| Schema | Selectielijst code | Disposition |
|---|---|---|
| `Account` | `5.1.1` (chart-of-accounts) | `keep_indefinite` |
| `GLTransaction`, `GLLine` | `5.1.2` (financial records) | `archive` after 7 years |
| `JournalEntry` | `5.1.2` | `archive` after 7 years |
| `Invoice` (T2), `Payment` (T2) | `5.1.2` | `archive` after 7 years |
| `FiscalPeriod` (T2) | `5.1.1` | `keep_indefinite` |
| `TrialBalance`, `FinancialStatement` (T2) | `5.1.4` (verantwoording) | `archive` after 10 years |
| `VatReturn`, `IcpStatement`, `VatCorrection`, `VatTariff` | `5.1.2` | `archive` after 7 years |
| `BbvAccountMapping`, `BbvTaakveld` | `5.1.1` | `keep_indefinite` |
| `Iv3Export` | `5.1.4` | `archive` after 10 years |
| `BcfClaim` | `5.1.4` | `archive` after 10 years |
| `KorRegime` | `5.1.2` | `archive` after 7 years |
| `UrenRegistratie`, `ZzpDeduction`, `IbAangifteExport` | `5.1.2` | `archive` after 7 years |
| `SchatkistPosition` | `5.1.2` | `archive` after 7 years |
| `Subsidie` | `3.5.1` (subsidie-records) | `archive` after 10 years (relative: after settlement) |
| `RepaymentInstallment` | `3.5.1` | same as parent |
| `Project`, `ProjectAssignment`, `BillableHour`, `WipBalance`, `RateCard` | `5.1.2` | `archive` after 7 years |

This mapping is a **default**; per-administration override is
allowed via the `RetentionRule` register (REQ-ARC-002).

#### Scenario: A schema without a retention declaration is rejected at register-load time

- **GIVEN** a future shillinq schema is added to
  `shillinq_register.json` without a retention reference
- **WHEN** OR's register validator runs
- **THEN** the validator SHOULD reject the schema (or warn loudly,
  depending on OR's enforcement level) — every regulated record
  needs a retention rule.

#### Scenario: A GL transaction past 7 years is archived per the rule

- **GIVEN** a `GLTransaction` created in 2017 with
  `retention.rule: "selectielijst:5.1.2"` (disposition: `archive`,
  retention: 7 years)
- **WHEN** OR's retention engine sweeps in 2026
- **THEN** the record MUST be moved to the archived state per OR's
  lifecycle archival, NOT deleted; the audit trail MUST remain
  verifiable.

### Requirement: REQ-ARC-004: Retention rules with PII-anonymisation disposition SHALL be marked accordingly

Records bearing personal identifiable information (PII) under
AVG/GDPR — primarily `BillableHour.personId`, `UrenRegistratie.personId`,
`Subsidie.counterpartyName`, and any contact-name fields —
MAY have rules with `disposition: anonymise`. Anonymisation MUST
preserve aggregate-able fields (amounts, dates, account refs) and
clear or hash the PII fields. The exact field-by-field anonymisation
shape is OR's responsibility; shillinq MUST declare the rule.

#### Scenario: An anonymised hour record retains aggregate fields

- **GIVEN** a `UrenRegistratie` record from 2017 with PII
  (`personId`)
- **WHEN** OR's anonymisation runs (per a hypothetical seeded
  rule with `disposition: anonymise`)
- **THEN** the `personId` MUST be cleared or hashed; the `hours`,
  `date`, `category`, `administrationId` MUST remain so the
  aggregate urencriterium history is auditable.

### Requirement: REQ-ARC-005: Retention overrides for administration-specific archiefverordening SHALL be operator-editable

Per Archiefwet art. 3, every gemeente has a local
archiefverordening that MAY extend (lengthen) retention beyond the
Selectielijst defaults. shillinq MUST allow operator-authored
override records in the `RetentionRule` register scoped to a
`administrationId`. Each override MUST cite the local
archiefverordening (`legalBasis` field with the article reference).

The OR retention engine MUST prefer the most-specific override
per record. Per ADR-022, no app-local override resolution code.

#### Scenario: A gemeente extends financial-records retention to 10 years

- **GIVEN** `gem-a` has a local archiefverordening art. 6.2
  extending financial-records retention to 10 years
- **WHEN** the operator adds a `RetentionRule` with
  `administrationId: "gem-a"`, `selectielijstCode: "5.1.2"`,
  `retentionYears: 10`, `legalBasis: "Archiefverordening gem-a
  art. 6.2"`
- **THEN** subsequent retention sweeps for `gem-a` records MUST
  use 10 years (not 7).

### Requirement: REQ-ARC-006: The audit-trail hash chain SHALL remain verifiable across retention dispositions

When a record is archived, anonymised, or destroyed per a
retention rule, the OR audit-trail's hash chain MUST remain
intact and verifiable. shillinq MUST NOT modify, truncate, or
re-hash the audit trail; the trail itself MAY survive the
underlying record per Archiefwet (the audit metadata of a
destroyed record is often itself a record subject to retention,
typically `keep_indefinite`).

This requirement is an OR contract; shillinq declares the
expectation. If OR's retention engine does not yet support
hash-chain preservation across anonymisation, the gap MUST be
filed as an OR issue and the spec annotated.

#### Scenario: A destroyed GL transaction leaves an immutable audit metadata record

- **GIVEN** a GL transaction from 2017 is destroyed by retention
  sweep in 2026
- **WHEN** the audit trail is queried for that transaction
- **THEN** the trail MUST still contain hash-chained events
  (creation, transitions, destruction) referencing the
  now-destroyed record by its prior UUID; the trail's hash chain
  MUST verify intact.

### Requirement: REQ-ARC-007: Operators SHALL be able to query records nearing retention via a derived field

A derived field `daysUntilRetention` (calculated per ADR-031 from
the rule + creation/settlement date) MUST be available on every
schema bound by a retention rule. The field enables a "records
nearing retention" report — useful for operators to review
records before automatic disposition.

#### Scenario: A record 30 days from disposition is queryable

- **GIVEN** a `JournalEntry` created in 2019-04-15 with 7-year
  retention (disposition due 2026-04-15)
- **WHEN** queried on 2026-03-16
- **THEN** `daysUntilRetention` MUST equal 30 (approximately).

### Requirement: REQ-ARC-008: A "records nearing retention" widget SHALL be declared via `x-openregister-widgets`

A widget MUST be declared via `x-openregister-widgets` showing
records bound by retention rules where `daysUntilRetention <
THRESHOLD_DAYS` (default 90, operator-configurable). Consumable
by `CnDashboardPage`. No bespoke Vue. Visible to operators with
role `archivist`.

#### Scenario: An archivist sees the upcoming-disposition widget

- **GIVEN** an `archivist` opens the shillinq dashboard
- **WHEN** the page renders
- **THEN** the widget MUST list the count of records nearing
  retention (and a link to the filtered index page).

### Requirement: REQ-ARC-009: Retention administration SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry
`Administratie > Bewaartermijnen` with:

- `type: index` on `RetentionRule` (seeded + operator-added).
- `type: detail` on `RetentionRule` for rule editing.
- A `type: dashboard` page surfacing the "nearing retention"
  widget (REQ-ARC-008).

Visibility predicated on the `archivist` role being granted on
the administration.

#### Scenario: An archivist edits a retention rule

- **GIVEN** an `archivist` opens the Bewaartermijnen index
- **WHEN** they click an existing rule and modify
  `retentionYears`
- **THEN** the save MUST succeed (approval-workflow per
  administration policy MAY gate the save) AND the audit trail
  MUST record the change.

### Requirement: REQ-ARC-010: Audit trail of retention rule changes SHALL be consumed from OR's abstractions

Every change to a `RetentionRule` (seeded or operator-added) MUST
be audited via OR's audit-trail-immutable. Changing a retention
rule has compliance impact (operator could shorten retention to
hide records); the audit trail MUST capture actor + before/after.

#### Scenario: A retention shortening is fully audited

- **GIVEN** the operator changes a rule's `retentionYears` from
  10 to 7 on `gem-a`
- **WHEN** the audit trail is queried
- **THEN** the change MUST appear with actor + timestamp +
  before/after values + hash-chain link.
