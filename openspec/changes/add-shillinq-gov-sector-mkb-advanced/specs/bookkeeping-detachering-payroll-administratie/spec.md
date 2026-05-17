# Spec: bookkeeping-detachering-payroll-administratie

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB — payroll + detachering)
**Depends on:** bookkeeping-accounts-payable-core

## ADDED Requirements

### REQ-DPA-001: Salarisbureau imports SHALL flow via openconnector sources — no app-local payroll client

Per ADR-019 + ADR-022, imports from salarisbureaus (ADP, Loket,
Visma, Nmbrs) MUST go through openconnector source rows.
Shillinq MUST declare one source row per salarisbureau in the
openconnector config (endpoint URL, OAuth2 flow, mapping
target). No `lib/Service/AdpClient.php`, no
`lib/Service/LoketClient.php`, no comparable PHP clients.

The incoming salaris-feed MUST materialise as a `SalarisFeed`
register (schema.org type: `schema:DataFeed`) carrying the raw
import batch before mapping to journal entries.

#### Scenario: Reviewer confirms no app-local payroll HTTP clients

- **GIVEN** the shillinq codebase
- **WHEN** scanned for direct `Http\Client\IClient` usage
  targeting ADP / Loket / Visma / Nmbrs hostnames
- **THEN** no such usage SHALL exist.

### REQ-DPA-002: The salaris-feed SHALL materialise as balanced `JournalEntry` records of subtype `loonkosten` per employee per loontijdvak

Each incoming salaris-feed batch MUST materialise a balanced
`JournalEntry` per employee per loontijdvak — the journal entry
MUST produce a balanced GL transaction per T1 REQ-GL-001
(loonkosten DR / nettoloon CR / sociale-premies CR /
loonheffing CR / pensioen CR). The mapping from
salarisbureau-feed to journal-entry lines MUST be an
`x-openregister-mappings` declaration — no PHP mapper service.

#### Scenario: An ADP-feed batch materialises a balanced journal entry

- **GIVEN** an ADP-feed batch for one employee over one
  loontijdvak with loonkosten €4 000
- **WHEN** the feed is processed
- **THEN** a `JournalEntry` of subtype `loonkosten` MUST appear
  with a balanced GL transaction where loonkosten-DR =
  nettoloon-CR + premies-CR + loonheffing-CR + pensioen-CR =
  €4 000.

### REQ-DPA-003: The system SHALL declare an `OpdrachtgeversVerklaring` register for Wet DBA opdrachtgever positions

Per Wet Deregulering Beoordeling Arbeidsrelaties (DBA), the
opdrachtgevers-verklaring per ZZP assignment MUST be recorded
administratively. The `OpdrachtgeversVerklaring` register
(schema.org type: `schema:DigitalDocument`) MUST declare records
with fields: `zzpId` (string — external identifier), `zzpNaam`
(string), `opdrachtBeschrijving` (string), `looptijdStart`
(date), `looptijdEind` (date), `verklaringStatus` (enum
`concept | overeengekomen | beëindigd`), `modelOvereenkomst`
(string — URI to the Belastingdienst model overeenkomst used,
optional), `verklaringDocumentUri` (string — docudesk
attachment URI), `risicoBeoordeling` (enum
`geen | laag | midden | hoog`). Per ADR-031 declarative — no PHP
DBA service.

#### Scenario: An overeengekomen verklaring with laag risico appears in the DBA administration

- **GIVEN** a ZZP detachering
- **WHEN** an `OpdrachtgeversVerklaring` with
  `verklaringStatus: 'overeengekomen'`, `risicoBeoordeling: 'laag'`
  is stored
- **THEN** the save MUST succeed; **AND** the DBA administration
  view MUST show the verklaring.

### REQ-DPA-004: The system SHALL produce the standard opdrachtgeversverklaring as a docudesk template

The Belastingdienst standard opdrachtgeversverklaring MUST be
rendered as a docudesk template from `OpdrachtgeversVerklaring`
fields. The render flow MUST be docudesk-side per ADR-022 —
shillinq only declares the template + field bindings.

#### Scenario: Opdrachtgeversverklaring document is generated

- **GIVEN** an `OpdrachtgeversVerklaring` record
- **WHEN** the operator triggers the "generate document" action
- **THEN** a docudesk document MUST appear with the verklaring
  fields filled in; **AND** the `verklaringDocumentUri` MUST
  point at the generated document.

### REQ-DPA-005: The system SHALL declare an `IB47Record` register for the annual IB47 form submission to the Belastingdienst

For freelance assignments + other non-loonhoudingsplichtige
payments, an IB47 form MUST be assembled annually (with a
monthly dry-run option). The `IB47Record` register MUST declare
records with fields: `belastingjaar` (integer), `opdrachtgeverId`
(FK to the administration), `ontvangerNaam` (string),
`ontvangerBSN` (string — stored encrypted per RBAC; only the
payroll-officer role may read it), `ontvangerAdres` (string),
`betalingenTotaal` (number ≥ 0), `betalingTypeCode` (enum per
Belastingdienst IB47 codes). Aggregation over a tax year MUST
be declarative via `x-openregister-aggregations` per
`(belastingjaar, opdrachtgeverId)`. Per ADR-022, RBAC on
personnel data is mandatory.

#### Scenario: IB47 dry-run and final yearly batch produce consistent totals

- **GIVEN** 12 monthly dry-run batches across 2026
- **WHEN** the final yearly batch for 2026 is rendered
- **THEN** the final payment totals per recipient MUST equal the
  sum of the 12 monthly dry-runs (tolerance: €0).

### REQ-DPA-006: The IB47 submission SHALL flow to the Belastingdienst via an openconnector source

Per ADR-019, the IB47 yearly batch submission to the
Belastingdienst MUST go through an openconnector source row. The
docudesk template MUST render the IB47 form in the format
required by the Belastingdienst (per the IB47 XML schema 2026).
Shillinq references the openconnector source by id from the
docudesk template output-channel.

#### Scenario: IB47 batch transmission logs an audit event

- **GIVEN** a complete IB47 yearly batch for 2026
- **WHEN** the operator triggers the Belastingdienst submission
- **THEN** the payload MUST flow via the openconnector source;
  **AND** an audit-trail event MUST record the submission hash +
  response status per ADR-022.

### REQ-DPA-007: Detachering + payroll administration SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.mkb-detachering`) under
`Bookkeeping > Detachering en payroll` with sub-pages for
Salaris-feeds, Opdrachtgevers-verklaringen + DBA-administratie,
and IB47-jaarbatch. Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Detachering menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.mkb-detachering`
- **WHEN** the flag is ON
- **THEN** the three sub-pages MUST appear.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.
