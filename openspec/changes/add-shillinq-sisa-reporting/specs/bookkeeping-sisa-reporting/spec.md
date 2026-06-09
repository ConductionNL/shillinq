# Spec: bookkeeping-sisa-reporting

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-subsidie-verantwoording

## ADDED Requirements

### Requirement: REQ-SISA-001 — The system SHALL declare a `SisaRegelingIndicator` register attaching per-regeling indicatoren to specifieke uitkeringen

The system MUST administer SiSa (Single Information Single Audit)
per-regeling indicatoren so that they can be reported in the annual
jaarrekening-bijlage. The `SisaRegelingIndicator` register MUST
declare records with fields:
`subsidieId` (FK to the parent `Subsidie` record of subtype
`specifieke-uitkering`), `regelingCode` (string — the BZK regeling
identifier, e.g. `D8`), `indicatorCode` (string — per
controleprotocol, e.g. `D8.01`), `indicatorOmschrijving` (string),
`indicatorWaarde` (number or string per indicator type),
`indicatorEenheid` (string), `peilDatum` (date). Per ADR-022, this
register attaches to the existing subsidie register — no parallel
SiSa subsidie register.

#### Scenario: An indicator attaches to a specifieke uitkering

- **GIVEN** a `Subsidie` record of subtype `specifieke-uitkering`
- **WHEN** a `SisaRegelingIndicator` with `regelingCode: 'D8'`,
  `indicatorCode: 'D8.01'`, `indicatorWaarde: 42` is saved
- **THEN** the save MUST succeed; **AND** the indicator MUST be
  retrievable via the parent subsidie's relations.

### Requirement: REQ-SISA-002 — The system SHALL ship the annual SiSa-controleprotocol indicatoren as seed data

The system MUST ship a seed file
`lib/Settings/seeds/sisa-controleprotocol-2026.json` declaring the
indicatoren per regeling for the 2026 SiSa controleprotocol release.
The file MUST carry SPDX header inside
docblock, `_meta` block (`source: 'BZK SiSa-controleprotocol'`,
`year: 2026`), and indicator definitions (`regelingCode`,
`indicatorCode`, `indicatorOmschrijving`, `indicatorType`,
`verplicht: boolean`). Seed loading MUST be idempotent.

#### Scenario: Seed file validates and indicates required indicatoren

- **GIVEN** the seed file is loaded
- **WHEN** the regeling `D8` indicatoren are queried
- **THEN** all `verplicht: true` indicatoren MUST be present in the
  loaded set.

### Requirement: REQ-SISA-003 — The annual SiSa-bijlage SHALL be produced as a declarative aggregation per controleprotocol

The SiSa-bijlage at jaarrekening MUST be produced as an
`x-openregister-aggregations` declaration grouping
`SisaRegelingIndicator` records by `(regelingCode, controleprotocol)`
voor het closed fiscal year, rendered via a docudesk template
matching the BZK-vastgestelde layout. Per ADR-031, no PHP SiSa-
bijlage service.

#### Scenario: SiSa-bijlage matches the seeded controleprotocol

- **GIVEN** the controleprotocol seed loaded en N indicatoren
  ingevuld voor het closed year
- **WHEN** the SiSa-bijlage rendert
- **THEN** every `verplicht: true` indicator declared in the
  controleprotocol MUST appear in the bijlage; **AND** missing
  verplichte indicatoren MUST surface as warnings in the audit
  preview.

### Requirement: REQ-SISA-004 — SiSa submission to BZK SHALL ride an openconnector source — no app-local HTTP client

Per ADR-019, the SiSa BZK upload MUST be configured as an
openconnector source row (the BZK endpoint, OAuth or PKI-cert
authentication per BZK specifiek). Shillinq MUST reference the
source by id from the docudesk template's output-channel
declaration. No `lib/Service/SisaSubmissionService.php`.

#### Scenario: BZK submission flows via openconnector

- **GIVEN** the SiSa-bijlage docudesk document
- **WHEN** the operator triggers submission
- **THEN** the upload MUST flow through the openconnector source;
  **AND** the response (acceptance / rejection) MUST log to the
  audit-trail-immutable per ADR-022.

### Requirement: REQ-SISA-005 — Each SiSa submission SHALL be recorded as an immutable audit event with cryptographic linkage to the submitted document

Per the SiSa controleprotocol, the submission lineage MUST be
auditable. Every submission attempt MUST write an audit event
including: operator id, regeling list submitted,
`controleprotocolVersion`, document SHA-256, openconnector
response status, and the docudesk document URI. The event MUST be
linked to the parent jaarrekening via the audit-trail hash chain.

#### Scenario: SiSa submission audit event is queryable

- **GIVEN** a successful SiSa submission for fiscal year 2026
- **WHEN** the audit-trail is queried for events of type
  `sisa.submitted`
- **THEN** an event MUST exist with the submission's SHA-256 +
  document URI + BZK response status.

### Requirement: REQ-SISA-006 — SiSa reporting SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-sisa`) under
`Bookkeeping > SiSa-rapportage` with `type: index` listing
indicatoren per regeling per year + `type: detail` for the annual
bijlage met submission status. Per ADR-024 Tier-4, no bespoke Vue
files.

#### Scenario: SiSa menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.gov-sisa`
- **WHEN** the flag is ON
- **THEN** the SiSa-rapportage menu entry MUST appear.
- **WHEN** the flag is OFF
- **THEN** the entry MUST NOT render.
