# Spec: bookkeeping-wbso-sno-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-cost-centers-dimensions

## ADDED Requirements

### Requirement: REQ-WBSO-001 — SoProject register

The system SHALL declare an `SoProject` register for S&O-projecten with an RvO link.

Per Wet vermindering afdracht loonbelasting hoofdstuk VA (WBSO),
S&O-werk MUST be administered per project + per employee. The
`SoProject` register (schema.org type: `schema:Project`) MUST
declare records with fields: `projectNaam` (string),
`rvoProjectNummer` (string — assigned by RvO),
`sEnOCertificaatNummer` (string — RvO certificate id),
`looptijdStart` (date), `looptijdEind` (date), `costCenterId` (FK
to `CostCenter` from T4-base), `status` (enum
`aangevraagd | toegekend | afgerond`). Per ADR-031 a declarative
register — no PHP S&O service.

#### Scenario: A toegekend S&O-project is registered

- **GIVEN** an MKB with an RvO S&O-certificaat 2026/0001
- **WHEN** an `SoProject` with `rvoProjectNummer: '2026/0001'`,
  `status: 'toegekend'` is stored
- **THEN** the save MUST succeed; **AND** the project MUST be
  referenceable from the hours administration (REQ-WBSO-002).

### Requirement: REQ-WBSO-002 — SoUrenStaat register with approval-workflow lifecycle

The system SHALL declare an `SoUrenStaat` register for per-employee per-week per-project hours administration with an `x-openregister-lifecycle` `draft → goedgekeurd → afgesloten` and an approval-workflow on the `goedgekeurd` transition.

The `SoUrenStaat` register (schema.org type: `schema:Action`)
MUST declare records with fields: `soProjectId` (FK to
`SoProject`), `medewerkerId` (string — reference to a Nextcloud
user OR to a `Detachering` record from REQ-DPA-002), `weekISO`
(string in ISO-8601 week format, e.g. `2026-W14`), `aantalUren`
(number ≥ 0, decimals allowed down to 0.25 hour),
`taakOmschrijving` (string), `state` (enum
`draft | goedgekeurd | afgesloten`). An
`x-openregister-lifecycle` MUST declare the state transitions
`draft → goedgekeurd → afgesloten` with an approval-workflow per
ADR-022 on the `goedgekeurd` transition.

#### Scenario: An uren-staat must be goedgekeurd before being afgesloten

- **GIVEN** an `SoUrenStaat` in `state: 'draft'`
- **WHEN** an operator attempts the `afgesloten` transition
  without going through `goedgekeurd`
- **THEN** the transition MUST be refused ("lifecycle
  precondition: state must be goedgekeurd").

### Requirement: REQ-WBSO-003 — Quarterly RvO mededeling docudesk template

The system SHALL produce a quarterly RvO mededeling as a docudesk document populated from an `x-openregister-aggregations` block that sums `SoUrenStaat` records (state ≠ `draft`) per quarter per project.

Per RvO WBSO regulation, a mededeling of actually realised S&O
hours + loonkosten per quarter MUST be reported to RvO. The
mededeling MUST be generated as a docudesk document populated
from an `x-openregister-aggregations` block that sums
`SoUrenStaat` records (with `state ≠ 'draft'`) per quarter per
project. Per ADR-031, no PHP mededeling renderer.

#### Scenario: Mededeling 2026-Q1 sums all goedgekeurde uren

- **GIVEN** 3 S&O-projecten with goedgekeurde uren-staten across
  weeks `2026-W01..W13`
- **WHEN** the Q1 mededeling is rendered
- **THEN** the docudesk document MUST show the total goedgekeurde
  hours per project.

### Requirement: REQ-WBSO-004 — Kwartaalrapportage + jaarrapport docudesk templates

The system SHALL produce kwartaalrapportage and jaarrapport docudesk documents from the same S&O-hours aggregation; templates MUST follow RvO-conform layout and the rendering MUST go through docudesk (no app-local renderer).

In addition to the mededeling, a kwartaalrapportage (operational
progress per project) and a jaarrapport (annual close +
results) MUST be generated as docudesk documents from the same
hours data. Templates MUST follow RvO-conform layout; rendering
MUST go through docudesk; no app-local renderer.

#### Scenario: Jaarrapport bundles all four kwartaalmededelingen

- **GIVEN** four submitted kwartaalmededelingen for 2026
- **WHEN** the jaarrapport 2026 is rendered
- **THEN** the document MUST show the aggregate totals identical
  to the sum of the four kwartaalmededelingen.

### Requirement: REQ-WBSO-005 — RvO submissions ride openconnector sources

Every RvO submission (mededeling, kwartaalrapportage, jaarrapport) SHALL flow through an openconnector source row per ADR-019; shillinq MUST NOT ship an `lib/Service/RvoSubmissieClient.php` or any other app-local HTTP client for RvO.

Per ADR-019, every RvO submission (mededeling, kwartaalrapportage,
jaarrapport) MUST go through an openconnector source row.
Shillinq MUST reference the openconnector source ids from the
docudesk template output-channel declaration. No
`lib/Service/RvoSubmissieClient.php`.

#### Scenario: Mededeling upload flows via openconnector

- **GIVEN** a generated docudesk mededeling document
- **WHEN** the operator triggers the upload
- **THEN** the transmission MUST flow via the openconnector
  source; **AND** the RvO response MUST be recorded in the
  audit-trail-immutable per ADR-022.

### Requirement: REQ-WBSO-006 — Declarative afdrachtvermindering loonheffing calculation

The afdrachtvermindering loonheffing SHALL be computed by an `x-openregister-calculations` block reading `SoUrenStaat.aantalUren × medewerker.sEnOUurloon × actueelAfdrachtPercentage` (RvO 2026 seed: 32% standard, 40% starters); the projected value MUST be shown side-by-side with the authoritative RvO mededeling value, with a delta reconciliation warning.

The afdrachtvermindering loonheffing per loonaangifte period MUST
be an `x-openregister-calculations` block that computes
`SoUrenStaat.aantalUren` × `medewerker.sEnOUurloon` ×
`actueelAfdrachtPercentage` (seeded from RvO 2026, default 32%
for regular S&O and 40% for starters). The afdracht is a
**projected** value — the RvO mededeling is the
**authoritative** value used in the loonaangifte. Shillinq MUST
show both values for reconciliation.

#### Scenario: Projected and RvO mededeling appear side by side in the hours detail view

- **GIVEN** a Q1 with €40 000 projected afdracht and an RvO
  mededeling returning €38 500
- **WHEN** the WBSO detail view for Q1 renders
- **THEN** both amounts MUST appear side by side; **AND** a
  reconciliation warning MUST surface the €1 500 delta for the
  loonheffing administration.

### Requirement: REQ-WBSO-007 — Feature-flag-controlled WBSO manifest navigation

The WBSO administration SHALL be reachable through a `featureFlags.mkb-wbso`-controlled menu entry under `Bookkeeping > WBSO` with sub-pages for Projecten, Uren-staten, Mededelingen + rapportages, and Afdrachtvermindering; per ADR-024 Tier-4 no bespoke Vue files MAY be authored.

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.mkb-wbso`) under `Bookkeeping > WBSO` with
sub-pages for Projecten, Uren-staten, Mededelingen +
Kwartaalrapportages + Jaarrapport, and Afdrachtvermindering. Per
ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: WBSO menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.mkb-wbso`
- **WHEN** the flag is ON
- **THEN** the four WBSO sub-pages MUST appear.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.
