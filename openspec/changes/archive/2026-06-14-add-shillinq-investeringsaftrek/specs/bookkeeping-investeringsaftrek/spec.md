# Spec: bookkeeping-investeringsaftrek

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-fixed-assets-depreciation

## ADDED Requirements

This capability is bound by **ADR-022** (consume OpenRegister abstractions; no
parallel app-local audit / RvO HTTP client) and **ADR-031** (declarative
composition over imperative service classes). Each requirement below is a
restatement of those ADRs applied to the investeringsaftrek slice, plus
**ADR-019** for the RvO aanvraag + mededeling roundtrip routed through
openconnector source rows, and **ADR-024 Tier-4** for the manifest navigation
shape (`CnIndexPage` / `CnDetailPage` library components preferred, no
bespoke Vue). The single ADR-031 exception path is the KIA-schalen lookup
guard — a tiny single-method PHP seam permitted because the calculation
engine cannot natively express tiered-threshold lookup against a year-versioned
seed.

### Requirement: REQ-INV-001 — The system SHALL declare an `InvesteringClassifier` overlay on `FixedAsset` for the four aftrek regimes

The system SHALL declare an `InvesteringClassifier` overlay register on `FixedAsset` covering the four investeringsaftrek regimes (KIA / EIA / MIA / Vamil). The `FixedAsset` register (from T4-base
`bookkeeping-fixed-assets-depreciation`) MUST gain an overlay
`InvesteringClassifier` (schema.org type: `schema:Thing`) with
fields: `fixedAssetId` (FK), `aftrekType` (enum
`kia | eia | mia | vamil`), `bedrijfsmiddelCode` (string — the
RvO bedrijfsmiddel-code as listed on the EIA/MIA/Vamil lijsten),
`aanvraagDatum` (date, optional — required for EIA/MIA/Vamil),
`aanvraagNummer` (string, optional — filled after RvO award),
`toegekendBedrag` (number, optional — filled after award). An
asset MAY carry multiple classifiers (KIA + MIA cumulatively on
the same bedrijfsmiddel). Per ADR-031 an overlay register — no
PHP investerings service.

#### Scenario: A fixed-asset is classified for both EIA + MIA

- **GIVEN** a `FixedAsset` for an energy-saving installation
- **WHEN** two `InvesteringClassifier` records (one for EIA, one
  for MIA) are created for the same asset
- **THEN** both records MUST save; **AND** the aftrek calculation
  MUST apply both regimes (cumulatively where RvO rules permit).

### Requirement: REQ-INV-002 — KIA / EIA / MIA / Vamil aftrek SHALL be computed declaratively against the annual tarieven seed

The aftrek MUST be an `x-openregister-calculations` block on
`FixedAsset` that consumes the seeded tarieven (REQ-INV-003) and
computes the allowed aftrek per asset + per regime. Per ADR-031
no PHP investerings calculator (a possible exception for the
KIA-schalen function applies if the calculation engine cannot
express lookup tables — in that case, a single-method PHP guard
per ADR-031 §"PHP guards remain a legitimate seam").

Calculation rules per regime:

- **KIA** (kleinschaligheidsinvesteringsaftrek): a flat-rate
  percentage on the total invested amount, with a threshold +
  ramp-up + maximum + taper zone (per RvO 2026 schalen).
- **EIA** (energie): 40% (default 2026, configurable via seed)
  on the purchase price of energy bedrijfsmiddelen on the
  Energielijst.
- **MIA** (milieu): 13.5% / 27% / 36% depending on Milieulijst
  category A/B/C (configurable).
- **Vamil** (vrije afschrijving): vrije afschrijving up to 75%
  in the first year for Milieulijst assets.

#### Scenario: KIA aftrek follows the threshold-rampup-maximum schaal

- **GIVEN** an MKB with €30 000 total invested in 2026 (above
  the KIA threshold, in the ramp-up zone)
- **WHEN** the aftrek calculation runs
- **THEN** the KIA aftrek MUST match the 2026 schaal for
  €30 000 as loaded from the seed (tolerance: €1).

### Requirement: REQ-INV-003 — The system SHALL ship an annual tarieven seed (`investeringsaftrek-tarieven-2026.json`)

The system SHALL ship an annual tarieven seed file containing the 2026 KIA / EIA / MIA / Vamil schalen. The seed file at
`lib/Settings/seeds/investeringsaftrek-tarieven-2026.json` MUST
carry an EUPL-1.2 SPDX in the docblock, a `_meta` block
(`source: 'RvO investeringsaftrek-regelingen'`, `year: 2026`),
and MUST contain:

- KIA threshold / ramp-up zone / maximum / taper zone tarieven
  for 2026.
- EIA percentage 2026 + Energielijst codes.
- MIA percentages 2026 (per category A/B/C) + Milieulijst codes.
- Vamil-eligible bedrijfsmiddel codes 2026.

Filename version-pinning MUST allow a 2027 update to live
alongside (`investeringsaftrek-tarieven-2027.json`).

#### Scenario: Seed validates and is loaded idempotently

- **GIVEN** a fresh install
- **WHEN** the repair-step runs
- **THEN** the tarieven MUST appear in the tarieven table;
  **AND** re-running MUST not duplicate records or overwrite
  operator edits.

### Requirement: REQ-INV-004 — The system SHALL produce an RvO aanvraagdossier per aftrek aanvraag via docudesk

The system SHALL produce a docudesk-rendered RvO aanvraagdossier for every EIA / MIA / Vamil aanvraag. For EIA / MIA / Vamil applications (KIA requires no separate
application), a docudesk template MUST generate an
aanvraagdossier containing: asset description, bedrijfsmiddel
code, purchase price, investment date, in-service date, and
attached bewijsstukken (invoices, technical specifications — by
docudesk attachment URI per ADR-022). The RvO submission MUST
go via an openconnector source (REQ-INV-006).

#### Scenario: EIA aanvraagdossier appears with the bewijsstukken references

- **GIVEN** a `FixedAsset` + `InvesteringClassifier` with
  `aftrekType: 'eia'` + 2 invoices attached in docudesk
- **WHEN** the operator triggers the aanvraagdossier render
- **THEN** a docudesk document MUST appear with the asset
  fields + URI references to the 2 invoices.

### Requirement: REQ-INV-005 — Toegekende-bedragen MUST be ingested asynchronously from the RvO mededeling

Toegekende-bedragen from RvO MUST be ingested asynchronously through the openconnector mededeling feed and an audit-trail event MUST be written on every award update. After RvO award, the `InvesteringClassifier.toegekendBedrag`
field MUST be populated via an openconnector source row (RvO
mededeling endpoint). The update MUST write an audit-trail event
with the RvO mededeling id + date + amount.

#### Scenario: An award update logs an audit-trail event

- **GIVEN** an EIA application pending
- **WHEN** RvO returns an award via the openconnector feed
  (`toegekendBedrag: €4 000`)
- **THEN** the `InvesteringClassifier` record MUST carry
  `toegekendBedrag: 4000`; **AND** an audit-trail event MUST
  record the mededeling id + date.

### Requirement: REQ-INV-006 — RvO submission + mededeling-feed SHALL ride openconnector — no app-local HTTP client

All RvO HTTP calls SHALL ride openconnector source rows; shillinq MUST NOT carry an app-local RvO HTTP client. Per ADR-019, every RvO call (aanvraag submission + mededeling
poll) MUST go through an openconnector source row. Shillinq MUST
reference the source id from the docudesk template
output-channel declaration + the mededeling-poll declaration.
No `lib/Service/RvoClient.php`.

#### Scenario: No direct HTTP client for RvO

- **GIVEN** the shillinq codebase
- **WHEN** scanned for direct `Http\Client\IClient` usage
  targeting `rvo.nl`
- **THEN** no such usage SHALL exist.

### Requirement: REQ-INV-007 — Investeringsaftrek SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST declare a feature-flag-controlled menu
entry (`featureFlags.mkb-investeringsaftrek`) under
`Bookkeeping > Investeringsaftrek` with `type: index` of
classifiers + `type: detail` per classifier (asset link,
aanvraag, toekenning, aftrek impact). Per ADR-024 Tier-4, no
bespoke Vue files.

#### Scenario: Investeringsaftrek menu toggles with the feature flag

- **GIVEN** the manifest declares
  `featureFlags.mkb-investeringsaftrek`
- **WHEN** the flag is ON
- **THEN** the menu MUST appear.
- **WHEN** the flag is OFF
- **THEN** the menu MUST NOT render.
