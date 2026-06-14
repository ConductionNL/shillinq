# Spec: bookkeeping-provincies-bbv-variant

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-bbv-compliance

## ADDED Requirements

### Requirement: REQ-PRB-001 — The system SHALL support a `bbvVariant: 'provincie'` overlay on the existing BBV-compliance register

The same `bbvVariant` overlay declared by REQ-WSB-001 MUST accept
the value `'provincie'`. When set, records MUST be interpreted
under the provinciale BBV-variant. Per ADR-031 + ADR-022, the
variant MUST be a schema flag — no parallel `ProvincieAccount`
register.

#### Scenario: A provincie administration carries the variant flag

- **GIVEN** a fresh shillinq install configured as a `provincie`
- **WHEN** the repair step seeds the chart of accounts
- **THEN** every seeded `Account` and `BBVProgramma` record MUST
  carry `bbvVariant: 'provincie'`.

### Requirement: REQ-PRB-002 — The system SHALL declare the provinciale BBV programma-indeling per `kerntaak`

Provincies MUST group postings by `kerntaak` — the seven canonical
provinciale kerntaken (ruimte, mobiliteit, water, milieu, cultuur,
economie, bestuur). The `BBVProgramma` schema's
`programmaStructure` discriminator MUST accept the value
`'kerntaak'`. Aggregations rolled up to the programma level MUST
honour the discriminator per the same shape as the waterschap
variant (REQ-WSB-002).

#### Scenario: Posting rolls up by kerntaak

- **GIVEN** a provincie administration with the kerntaken seed
  loaded
- **WHEN** a `GLLine` is posted with `programmaCode: 'mobiliteit'`
- **THEN** the programma-level aggregation MUST roll up under the
  `kerntaak` structure.

### Requirement: REQ-PRB-003 — The system SHALL ship a provinciale BBV kerntaken seed (`bbv-provincies-kerntaken-2026.json`)

The seed file MUST live at
`lib/Settings/seeds/bbv-provincies-kerntaken-2026.json`, MUST carry
an SPDX header inside the docblock, MUST carry an `_meta` block
(`source: 'Provinciale handleiding BBV'`, `year: 2026`), and MUST
declare the seven canonical kerntaken with their RGS-aligned
account sub-trees. Seed loading MUST be idempotent.

#### Scenario: Seed file declares all seven kerntaken

- **GIVEN** the seed file is loaded
- **WHEN** the kerntaken set is read
- **THEN** all seven canonical kerntaken (ruimte, mobiliteit,
  water, milieu, cultuur, economie, bestuur) MUST be present.

### Requirement: REQ-PRB-004 — The system SHALL declare a `ProvincialeFondsPosting` register for provinciefonds and decentralisatie-uitkering boekingen

The system MUST express provinciale fonds boekingen (provinciefonds,
algemene uitkering, decentralisatie-uitkering, integratie-uitkering)
via a `ProvincialeFondsPosting` register with fields:
`fondsType` (enum), `uitkeringJaar` (integer), `uitkeringBedrag`
(number ≥ 0), `uitkeringBeschikking` (string — beschikkingnummer),
`journalEntryId` (FK to the materialised journal). The register
MUST NOT carry its own ledger lines — the posting materialises a
balanced `GLTransaction` per T1 REQ-GL-001.

#### Scenario: Provinciefonds uitkering materialises a balanced journal

- **GIVEN** the provincie administration
- **WHEN** a `ProvincialeFondsPosting` with
  `fondsType: 'provinciefonds'`, `uitkeringBedrag: 50000.00` is
  posted
- **THEN** a balanced `JournalEntry` MUST be materialised against
  the provinciefonds inkomstenrekening; **AND** the journal MUST
  reference the fonds-posting via `sourceReference`.

### Requirement: REQ-PRB-005 — The system SHALL declare opcenten MRB als een sub-administratie van motorrijtuigenbelasting-inkomsten

The provincie's opcenten administratie MUST be expressible as a
sub-administration of MRB-inkomsten — provincies heffen `opcenten`
op de motorrijtuigenbelasting (MRB) en a `GLLine` field
`opcentenTarief` (number ≥ 0, the per-provincie tariefopslag in
procenten) MUST be available on lines posted to the MRB-opcenten
inkomstenrekening. Aggregations MUST be able to roll up opcenten-
inkomsten per provincie per period.

#### Scenario: Opcenten MRB posting carries the tarief

- **GIVEN** the provincie administration with opcentenTarief = 80
  (procent)
- **WHEN** a `GLLine` is posted with `accountNumber: 'mrb-opcenten'`
  and `opcentenTarief: 80`
- **THEN** the line MUST validate; **AND** the opcenten-rollup
  aggregation for the period MUST include the posting.

### Requirement: REQ-PRB-006 — Provincie sector view SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-provincie`) under
`Bookkeeping > Provinciale fondsen` with `type: index` + `type:
detail` pages binding to `ProvincialeFondsPosting`. Per ADR-024
Tier-4, no bespoke Vue files.

#### Scenario: Feature flag toggles visibility

- **GIVEN** the manifest declares `featureFlags.gov-provincie`
- **WHEN** the flag is OFF
- **THEN** the Provinciale fondsen menu entry MUST NOT render.
- **WHEN** the flag is ON
- **THEN** the entry MUST appear under Bookkeeping.
