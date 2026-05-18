# Spec: bookkeeping-cbs-bestanden-extended

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-iv3-reporting

## ADDED Requirements

### REQ-CBSE-001: The system SHALL declare each extended CBS-bestand as a transformation atop existing IV3 aggregations — no new ledger data

Every additional CBS-bestand (Iv3-detail, Kerngegevens jaarstaten,
Iv3-OZB, EMU-bestand, periodieke statistiekleveringen) MUST be
expressible as a combination of:

1. One or more `x-openregister-aggregations` declarations rolling
   up existing GL data (no new postings, no new register).
2. A `docudesk` template producing the output format (CSV, XML,
   or SBR per CBS specifiek).
3. An `openconnector` source row pointing at the CBS-endpoint that
   accepts the bestand.

Per ADR-031, no PHP transformation service.

#### Scenario: Reviewer confirms each CBS-bestand is purely transformation

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Service/Cbs*` or
  `lib/Service/Statistiek*` classes
- **THEN** no such classes SHALL exist; all CBS outputs MUST flow
  through declared aggregations + docudesk templates +
  openconnector sources.

### REQ-CBSE-002: The system SHALL produce the Iv3-detail bestand (inkomsten-en-uitgaven detail) per quarter

Iv3-detail is the per-taakveld-per-categorie detail breakdown
beyond the base IV3 rollup. The aggregation MUST group `GLLine`
records by `(periodId, taakveld, categorie)` summing
`(debit - credit)` in EUR. The docudesk template MUST produce the
CBS-canonical CSV layout. The openconnector source MUST submit
each bestand to the CBS endpoint per quarter.

#### Scenario: Iv3-detail for a closed quarter matches the base IV3 totals

- **GIVEN** a closed quarter with IV3-base totals already computed
- **WHEN** the Iv3-detail aggregation runs
- **THEN** the sum across alle categorieën per taakveld MUST equal
  the base IV3 total for that taakveld (tolerance: €0).

### REQ-CBSE-003: The system SHALL produce the Kerngegevens jaarstaten bestand annually

Kerngegevens jaarstaten is the annual summary CBS bestand with
ratios (e.g. lasten per inwoner, baten per inwoner, schuldquote).
The aggregation MUST consume the closed fiscal-year jaarrekening
output (`bookkeeping-financial-statements`), plus an
administration-level `kernGegevensConfig` schema declaring the
denominators (inwoner-aantal, oppervlak, etc.). Output MUST be a
CBS-conformant XML payload.

#### Scenario: Kerngegevens computation uses the configured inwoner-aantal

- **GIVEN** an administration with `kernGegevensConfig.inwonerAantal:
  50 000`
- **WHEN** the kerngegevens jaarstaten aggregation runs for the
  closed year
- **THEN** the `lasten per inwoner` ratio MUST equal
  `totaleLasten / 50 000`.

### REQ-CBSE-004: The system SHALL produce the Iv3-OZB bestand (onroerende-zaken belasting) periodically

The Iv3-OZB bestand reports OZB-inkomsten + WOZ-waarden per
heffingstijdvak. The aggregation MUST group OZB-postings by
`(periodId, ozbCategorie)` where `ozbCategorie` is a `GLLine` flag
distinguishing eigenaars-deel, gebruikers-deel, woning vs niet-
woning. Output MUST conform to the CBS Iv3-OZB layout.

#### Scenario: OZB rollup splits eigenaars- en gebruikers-deel

- **GIVEN** OZB-postings carrying
  `ozbCategorie: ['eigenaars-woning', 'gebruikers-niet-woning']`
- **WHEN** the Iv3-OZB aggregation runs
- **THEN** the rollup MUST produce separate totals per
  ozbCategorie waarde.

### REQ-CBSE-005: The system SHALL produce the EMU-bestand quarterly and annually

The EMU-bestand reports EMU-saldo and EMU-schuld per period. The
aggregation MUST consume the ESA-2010 classifier declared in
`bookkeeping-emu-reporting` (REQ-EMU-002) and the EMU-bijlage
inclusion/exclusion rules (per REQ-WSB-005 voor waterschappen).
Output MUST conform to the CBS EMU XML layout.

#### Scenario: EMU-bestand matches the EMU-reporting computation

- **GIVEN** a closed period with EMU-reporting saldo computed at
  €X
- **WHEN** the EMU-bestand transformation runs
- **THEN** the reported saldo in the bestand MUST equal €X exact.

### REQ-CBSE-006: CBS-bestanden MUST submit via openconnector sources, not via app-local HTTP

Per ADR-019, every external submission MUST ride an openconnector
source row. Shillinq MUST declare the CBS endpoint sources in the
seeded openconnector source list (Iv3, Iv3-detail, Iv3-OZB,
Kerngegevens, EMU). The actual HTTP call MUST be openconnector's;
shillinq references the source by id from the aggregation's
output-channel declaration.

#### Scenario: No app-local HTTP client to CBS

- **GIVEN** the shillinq codebase
- **WHEN** scanned for direct `Http\Client\IClient` usage targeting
  CBS endpoints
- **THEN** no such usage SHALL exist; all CBS submissions MUST
  ride openconnector.

### REQ-CBSE-007: Each extended CBS-bestand SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.gov-cbs-extended`) under
`Bookkeeping > CBS-bestanden` listing the available bestanden, with
per-bestand `type: detail` pages showing the latest run + history.
Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: CBS menu toggles with the feature flag

- **GIVEN** the manifest declares `featureFlags.gov-cbs-extended`
- **WHEN** the flag is ON
- **THEN** the CBS-bestanden submenu MUST list at minimum Iv3-detail,
  Kerngegevens, Iv3-OZB, en EMU-bestand.
- **WHEN** the flag is OFF
- **THEN** the CBS menu MUST NOT render.
