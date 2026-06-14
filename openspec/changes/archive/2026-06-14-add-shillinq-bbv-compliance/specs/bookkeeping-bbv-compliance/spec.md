# Spec: bookkeeping-bbv-compliance

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (operations + NL compliance core)
**Depends on:** bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1)

## ADDED Requirements

### Requirement: REQ-BBV-001: The system SHALL provide BBV-conformant posting infrastructure for decentralised government administrations

For administrations of type `gemeente`, `provincie`, or `waterschap`,
shillinq MUST support posting GL transactions in conformance with the
Besluit Begroting en Verantwoording decentrale overheden (BBV). The
infrastructure consists of a `BbvAccountMapping` register linking
each T1 `Account` to its required BBV metadata (taakveld, programma,
paragraaf, autorisatieniveau). Per ADR-022, this is a register —
NOT an app-local link table — and per ADR-031, posting-rule
enforcement is declarative via `x-openregister-lifecycle`
preconditions on `GLLine`.

Statutory basis: Besluit Begroting en Verantwoording decentrale
overheden (BBV) + Commissie BBV handreikingen.

#### Scenario: A non-municipal administration is not subject to BBV

- **GIVEN** an administration with `administrationType: "mkb"`
- **WHEN** a `GLLine` is posted
- **THEN** the BBV precondition MUST NOT fire (taakveld is not required).

#### Scenario: Reviewer confirms no parallel link table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `bbv_`,
  `taakveld_`, or `programma_`
- **THEN** no such classes SHALL exist.

### Requirement: REQ-BBV-002: The `BbvAccountMapping` schema SHALL declare a fixed minimum field set

Schema.org annotation: `schema:DefinedTerm` (a controlled-vocabulary mapping linking an `Account` to its BBV classifiers — same pattern as `Account` per REQ-CoA-004).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration (gemeente/provincie/waterschap) |
| `accountNumber` | string | Yes | FK to `Account.accountNumber` (T1) |
| `taakveld` | enum | Yes | Code from the BBV taakveld catalogue (seeded; see REQ-BBV-005) |
| `programmaCode` | string | Yes | Operator-defined programma identifier (e.g. `01-Bestuur`) |
| `paragraafCode` | string | No | Operator-defined paragraaf if applicable |
| `autorisatieniveau` | enum | Yes | One of `programma`, `taakveld`, `kostenplaats` (the level at which raadsautorisatie applies) |
| `bcfCompensable` | boolean | Yes (default `false`) | Whether the account's VAT is recoverable via BCF (see `bookkeeping-bcf-vat-compensation`) |
| `iv3Bucket` | enum | Yes | The IV3 reporting bucket (see `bookkeeping-iv3-reporting`) |
| `_meta.source` | string | No | `"seeded"` or `"operator-edited"` for audit traceability |

The mapping MUST be **unique per `(administrationId, accountNumber)`**
— exactly one mapping per account per administration, enforced via
OR's uniqueness constraint or a thin lifecycle precondition.

#### Scenario: A duplicate mapping fails

- **GIVEN** account `4250 Subsidies cultuur` already has a mapping
  to taakveld `5.3 Cultuurpresentatie` in administration `gem-a`
- **WHEN** a second mapping for the same account+admin is saved
- **THEN** the save MUST fail with a uniqueness error.

#### Scenario: Different admins may map the same account differently

- **GIVEN** account `4250` exists in two municipalities
- **WHEN** `gem-a` maps it to taakveld `5.3` and `gem-b` maps it
  to `5.6`
- **THEN** both saves MUST succeed — uniqueness is per-administration.

### Requirement: REQ-BBV-003: GL postings on municipal administrations SHALL require a valid `BbvAccountMapping` before transitioning to `posted`

A lifecycle precondition on `GLTransaction.post` (per T1) MUST
verify that for administrations with `administrationType ∈
{gemeente, provincie, waterschap}`, every `GLLine.accountNumber`
resolves to a non-archived `BbvAccountMapping` for the same
administration. Per ADR-031, this is an `x-openregister-lifecycle.requires`
precondition declared on the `GLTransaction` schema's `post`
transition — NOT a PHP `BbvValidationService`.

#### Scenario: A municipal posting with unmapped account fails

- **GIVEN** administration `gem-a` (type `gemeente`) and account
  `9999 Onbekend` with no `BbvAccountMapping`
- **WHEN** a balanced `GLTransaction` referencing account `9999` is
  transitioned `draft → posted`
- **THEN** the transition MUST fail with a "BBV mapping required"
  error naming the unmapped account.

#### Scenario: A non-municipal posting bypasses the check

- **GIVEN** administration `mkb-z` (type `mkb`) and the same
  unmapped account
- **WHEN** the same transition runs
- **THEN** the transition MUST succeed — the BBV precondition
  conditional fires only for `gemeente`/`provincie`/`waterschap`.

### Requirement: REQ-BBV-004: BBV reporting fields SHALL be derived from declarative aggregations, not service methods

The standard BBV reporting views — sum by `taakveld`, sum by
`programmaCode`, sum by `autorisatieniveau` — MUST be expressed as
`x-openregister-aggregations` declarations on
`GLLine` joined with `BbvAccountMapping`. shillinq MUST NOT author a
`BbvReportingService` walking the GL — per ADR-031, this is the
exact aggregation anti-pattern that drove the decidesk decision.

#### Scenario: A programma summary aggregates the period's postings

- **GIVEN** administration `gem-a` with 12 accounts mapped across 3
  programma codes and 2026-Q1 has posted balanced transactions
- **WHEN** the `bbvProgrammaSummary` aggregation is queried for Q1
- **THEN** the result MUST be 3 rows, one per programma, with
  `totalDebit` and `totalCredit` summed correctly.

### Requirement: REQ-BBV-005: BBV taakveld catalogue SHALL ship as versioned seed data

The complete BBV taakveld catalogue (currently 2024 revision; ~50
codes spanning 0.x Bestuur through 8.x VHROSV) MUST be shipped as
`lib/Settings/seeds/bbv-taakvelden-2024.json` and loaded into a
`BbvTaakveld` register via the repair step. Each record:

Schema.org annotation for `BbvTaakveld`: `schema:DefinedTerm` (a coded classifier from the statutory BBV bijlage IV catalogue).

| Field | Type | Required | Purpose |
|---|---|---|---|
| `taakveldCode` | string | Yes | e.g. `0.1`, `1.2`, `7.1` |
| `name` | string | Yes | Official name per Besluit BBV bijlage IV |
| `category` | enum | Yes | `bestuur`, `veiligheid`, `verkeer`, `economie`, `onderwijs`, `sport-cultuur-recreatie`, `sociaal-domein`, `volksgezondheid-milieu`, `vhrosv`, `algemene-dekkingsmiddelen`, `overhead` |
| `legalBasis` | string | Yes | Citation: `Besluit BBV bijlage IV §X` |
| `effectiveFrom` / `effectiveTo` | date | Yes / No | Validity window |

The file's `_meta.bbvVersion` field MUST identify the BBV revision
(currently `"2024"`). A future `bbv-taakvelden-2026.json` MAY coexist;
the mapping seed (REQ-BBV-006) is regenerated for the new version.

#### Scenario: The seed file parses and validates

- **GIVEN** `bbv-taakvelden-2024.json`
- **WHEN** parsed as JSON
- **THEN** every record MUST validate against the `BbvTaakveld`
  schema AND the file MUST contain at minimum the canonical
  category structure listed in Besluit BBV bijlage IV.

#### Scenario: A BBV controller adds a custom taakveld variant

- **GIVEN** a controller wants to track sub-taakveld `7.1a Gezondheid
  preventief` separately
- **WHEN** they add the record via the OR API
- **THEN** the save MUST succeed AND re-running the repair step
  MUST NOT delete it.

### Requirement: REQ-BBV-006: The default RGS-to-BBV mapping SHALL ship as `rgs-to-bbv-mapping.json` seed

A starting mapping from RGS 3.5 accounts to BBV taakvelden (with
`bcfCompensable` and `iv3Bucket` defaults) MUST be shipped as
`lib/Settings/seeds/rgs-to-bbv-mapping.json` and loaded into the
`BbvAccountMapping` register on first install of a `gemeente`-type
administration. Per design.md D3 + ADR-022, this is a register, not
an enum.

Per-administration override is the default behaviour — operators
edit the seeded mappings or add new ones; the repair step MUST NOT
overwrite operator edits on re-run (per the T1 repair-step
idempotency pattern).

#### Scenario: Fresh gemeente install seeds the mapping

- **GIVEN** a fresh administration with `administrationType: "gemeente"`
- **WHEN** the repair step runs
- **THEN** the `BbvAccountMapping` register MUST contain at minimum
  the seed entries from `rgs-to-bbv-mapping.json`.

#### Scenario: Repair re-run preserves operator overrides

- **GIVEN** the operator has changed the mapping of account `4250`
  from taakveld `5.3` to `5.6`
- **WHEN** the repair step is re-run after an app upgrade
- **THEN** the operator's override MUST persist.

### Requirement: REQ-BBV-007: BBV mappings SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry `Overheid >
BBV-mapping` with a `type: index` page binding to
`BbvAccountMapping` and a `type: detail` page for individual
mappings. Visibility MUST be predicated on `administrationType ∈
{gemeente, provincie, waterschap}`.

#### Scenario: A non-municipal admin does not see the BBV menu

- **GIVEN** an administration with `administrationType: "mkb"`
- **WHEN** the operator opens the dashboard
- **THEN** the BBV-mapping menu entry MUST NOT appear.

#### Scenario: A bbv-controller drills into a mapping

- **GIVEN** a `bbv-controller` opens the BBV-mapping index
- **WHEN** they click on the row for account `4250`
- **THEN** the detail page MUST render via `CnDetailPage` showing
  every field from REQ-BBV-002 AND the related `Account` (T1) +
  `BbvTaakveld` records.

### Requirement: REQ-BBV-008: Audit trail and retention SHALL be consumed from OR's abstractions

Every `BbvAccountMapping` and `BbvTaakveld` operation MUST be
audited via OR's audit-trail-immutable (ADR-022) — shillinq MUST
NOT write to a private audit table. Retention MUST be declared via
`x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.1" }`
(Selectielijst code for begroting/verantwoording records: typically
indefinite for the canonical chart; 7 years for derived postings).

#### Scenario: A renamed mapping retains its prior name in the audit trail

- **GIVEN** a mapping was originally created with
  `programmaCode: "01-Bestuur"` and later renamed to
  `01-Bestuur en ondersteuning`
- **WHEN** the audit trail is queried
- **THEN** both the original and renamed values MUST appear with
  their respective timestamps and actor identifiers, hash-chain
  intact.

### Requirement: REQ-BBV-009: BBV mapping changes SHALL be subject to approval-workflow per administration policy

Significant mapping changes — especially `bcfCompensable` flag
flips (which affect BCF claim values, see `bookkeeping-bcf-vat-
compensation`) and `taakveld` reassignments (which affect IV3
reporting) — MAY require approval per administration policy. The
`update` lifecycle on `BbvAccountMapping` MUST declare an optional
`x-openregister-lifecycle.requires.approval-workflow` block; the
policy is operator-configurable. Per ADR-022, shillinq MUST NOT
author a custom approval routing.

#### Scenario: A bcfCompensable flip on a high-value account requires approval

- **GIVEN** the administration's policy requires approval for
  `bcfCompensable` changes on accounts with prior-year postings
  > €50.000
- **WHEN** a `bbv-controller` attempts to flip the flag on account
  `4250`
- **THEN** the save MUST be queued for approval AND the actor MUST
  see "approval required" surfaced from OR's approval-workflow.
