# Spec: bookkeeping-market-government-separation

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (NL gov sector)
**Depends on:** bookkeeping-cost-centers-dimensions

## ADDED Requirements

### REQ-MGS-001: The system SHALL declare an `ondernemingsActiviteit` flag on `CostCenter` distinguishing market activities from public-task activities

Per Wet Markt en Overheid (Mededingingswet hoofdstuk 4b), gemeenten /
provincies / waterschappen die ondernemingsactiviteiten verrichten
MUST these activities scheiden van publieke taken. The
`CostCenter` schema (from T4-base `bookkeeping-cost-centers-
dimensions`) MUST gain an `ondernemingsActiviteit: boolean` field
(default `false`). When `true`, the cost-center MUST be subject to
the integrale-kostprijs eis (REQ-MGS-002) and the transparantie-
administratie (REQ-MGS-005). Per ADR-031 the flag MUST be schema
metadata — no parallel `OndernemingsActiviteit` register.

#### Scenario: A cost-center flagged as ondernemingsactiviteit is subject to the kostprijs eis

- **GIVEN** a `CostCenter` met `ondernemingsActiviteit: true`
- **WHEN** kosten op deze cost-center geboekt worden
- **THEN** de integrale-kostprijs calculatie (REQ-MGS-002) MUST
  automatically apply.

### REQ-MGS-002: The system SHALL compute integrale kostprijs declaratively per ondernemingsactiviteit cost-center

The integrale-kostprijs per ondernemingsactiviteit MUST include:

- Directe kosten (lines posted directly to the cost-center).
- Toe te rekenen overhead via a configurable verdeelsleutel
  (re-using the `GRVerdeelsleutel` shape from
  `bookkeeping-gr-consolidation` REQ-GRC-002 or a similar overlay
  named `OverheadVerdeelsleutel`).
- Een redelijke vergoeding voor het gebruikte eigen vermogen
  (per Wet Markt en Overheid art. 25i, percentage configureerbaar
  per administratie).

The computation MUST be declared as an
`x-openregister-calculations` block on `CostCenter` — no PHP
kostprijs service.

#### Scenario: Integrale kostprijs sums directe kosten + verdeelde overhead + vermogensvergoeding

- **GIVEN** een ondernemingsactiviteit met €100 000 directe kosten,
  een verdeelsleutel die €20 000 overhead toerekent, en een
  geconfigureerde 4% vermogensvergoeding op €50 000 ingezet
  vermogen
- **WHEN** de integrale-kostprijs calculatie draait
- **THEN** de uitkomst MUST `€100 000 + €20 000 + (€50 000 × 0.04)
  = €122 000` zijn.

### REQ-MGS-003: Tarieven voor ondernemingsactiviteit-prestaties SHALL minimaal de integrale kostprijs dekken (default; uitzonderingen expliciet gemarkeerd)

Per Wet Markt en Overheid art. 25i, gemeenten MUST tarieven hanteren
die minimaal de integrale kostprijs dekken voor markt-activiteiten,
tenzij een algemeen-belang-besluit een lager tarief rechtvaardigt.
The shillinq model MUST surface dit via een aggregation that
compares de gerealiseerde omzet per ondernemingsactiviteit met de
integrale kostprijs voor die periode. Een onder-kostendekkend
resultaat MUST een waarschuwing surface'n in de transparantie-
administratie view (REQ-MGS-005).

#### Scenario: Onder-kostendekkende ondernemingsactiviteit triggert een warning

- **GIVEN** een ondernemingsactiviteit met integrale kostprijs
  €122 000 en gerealiseerde omzet €100 000 voor het period
- **WHEN** de transparantie-administratie view rendert
- **THEN** een warning MUST verschijnen aanduidend "€22 000 onder-
  kostendekkend; vereist algemeen-belang-besluit of tariefaanpassing".

### REQ-MGS-004: Algemeen-belang-besluiten SHALL be administratively tracked as a structured override on the ondernemingsactiviteit cost-center

Een `algemeenBelangBesluit` overlay MUST kunnen worden gekoppeld
aan een ondernemingsactiviteit cost-center, met fields:
`besluitNummer` (string), `besluitDatum` (date), `geldigheidsperiode`
(date-range), `motivering` (string of docudesk attachment URI),
`getrokkenBedrag` (number, the cost-dekking exception bedrag). De
warning uit REQ-MGS-003 MUST suppress wanneer een geldig
algemeen-belang-besluit van toepassing is.

#### Scenario: Een geldig algemeen-belang-besluit onderdrukt de waarschuwing

- **GIVEN** een ondernemingsactiviteit met onder-kostendekkend
  resultaat
- **AND** een `algemeenBelangBesluit` met
  `geldigheidsperiode: 2026-01-01..2026-12-31` aan de cost-center
  gekoppeld
- **WHEN** de transparantie-administratie view voor 2026-Q1 rendert
- **THEN** de warning MUST gesupprimeerd zijn; **AND** een
  informatief banner MUST het besluit-nummer vermelden.

### REQ-MGS-005: De transparantie-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.gov-markt-overheid`) declareren onder `Bookkeeping
> Markt en Overheid` met `type: index` per ondernemingsactiviteit
(directe kosten, overhead, vermogensvergoeding, integrale kostprijs,
omzet, marge, status) + `type: detail` voor de onderbouwing per
cost-center. Per ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Transparantie-administratie toggles with the feature flag

- **GIVEN** de manifest declareert `featureFlags.gov-markt-overheid`
- **WHEN** de flag is ON
- **THEN** het Markt en Overheid menu MUST verschijnen onder
  Bookkeeping.
- **WHEN** de flag is OFF
- **THEN** het menu MUST NOT renderen.
