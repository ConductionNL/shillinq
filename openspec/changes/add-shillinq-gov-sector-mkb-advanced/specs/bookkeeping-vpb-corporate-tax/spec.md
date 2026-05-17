# Spec: bookkeeping-vpb-corporate-tax

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation — Vpb)
**Depends on:** bookkeeping-bbv-compliance, bookkeeping-market-government-separation

## ADDED Requirements

### REQ-VPB-001: The system SHALL declare a `vpbPligtig` flag on `Account` distinguishing Vpb-pligtig accounts

Per Wet modernisering Vpb-plicht (2016), muni
ondernemingsactiviteiten en bepaalde stichtingen / verenigingen zijn
Vpb-pligtig. The `Account` schema MUST gain a `vpbPligtig: boolean`
field (default `false`). When `true`, postings tegen het account
MUST contribute to the Vpb-balans (REQ-VPB-003). Per ADR-031 the
flag MUST be schema metadata — no parallel `VpbAccount` register.

#### Scenario: A Vpb-pligtig account contributes to the Vpb-balans

- **GIVEN** een account met `vpbPligtig: true`
- **WHEN** een posting tegen het account geboekt wordt
- **THEN** de posting MUST in the Vpb-balans aggregation
  verschijnen (REQ-VPB-003).

### REQ-VPB-002: The system SHALL declare a `VpbBalansLink` overlay tying ondernemingsactiviteit cost-centers to Vpb-pligtig accounts

A `VpbBalansLink` register MUST declare records with fields:
`costCenterId` (FK to `CostCenter` met
`ondernemingsActiviteit: true`), `accountNumbers` (array of
`Account.accountNumber` strings — de Vpb-pligtige accounts die tot
deze ondernemingsactiviteit horen), `vpbPligtigVanaf` (date — de
ingangsdatum van de Vpb-pligtigheid voor deze cluster). Per
ADR-031 dit is een schema overlay — geen Vpb-service.

#### Scenario: Een ondernemingsactiviteit wordt aan Vpb-pligtige accounts gekoppeld

- **GIVEN** een cost-center met `ondernemingsActiviteit: true`
- **WHEN** een `VpbBalansLink` met die cost-center + lijst van 5
  accountNumbers wordt opgeslagen
- **THEN** de save MUST slagen; **AND** de Vpb-balans per
  ondernemingsactiviteit MUST deze 5 accounts groeperen.

### REQ-VPB-003: The system SHALL produce a Vpb-balans per ondernemingsactiviteit as a declarative aggregation

The Vpb-balans MUST be een `x-openregister-aggregations` declaration
filtering `GLLine` op (`accountNumber IN VpbBalansLink.accountNumbers`
AND `periodId IN fiscalYearPeriods`) en groeperend per
`costCenterId`, producerend de balans-kolom (Activa / Passiva /
Resultaat) per ondernemingsactiviteit. Per ADR-031 geen PHP Vpb-
balans service.

#### Scenario: Vpb-balans matched de Vpb-pligtige postings

- **GIVEN** een ondernemingsactiviteit met 5 Vpb-pligtige accounts
  + €50 000 saldo over het closed jaar
- **WHEN** de Vpb-balans aggregation runs
- **THEN** de uitkomst MUST €50 000 als nettoresultaat surfacen;
  **AND** Activa- en Passiva-kolommen MUST in balance zijn (per
  T1's balance invariant REQ-GL-005).

### REQ-VPB-004: The system SHALL produce a Vpb-aangifte voorbereiding document via docudesk

The Vpb-aangifte voorbereiding MUST be gegenereerd als een docudesk
document, gevuld vanuit de Vpb-balans aggregation. The actual
aangifte transmission MUST ride the SBR pad uit T4-base
`bookkeeping-sbr-xbrl-reporting` (Belastingdienst SBR endpoint).
Shillinq MUST de docudesk template reference + SBR payload-binding
declareren; geen PHP Vpb-aangifte service.

#### Scenario: Vpb-aangifte voorbereiding refereert naar de Vpb-balans

- **GIVEN** een closed fiscal year met Vpb-balans aggregation
  output
- **WHEN** de operator de Vpb-aangifte voorbereiding triggert
- **THEN** een docudesk document MUST verschijnen met de Vpb-balans
  velden ingevuld; **AND** de SBR-payload MUST tegen het
  Belastingdienst Vpb XSD valideren.

### REQ-VPB-005: Vpb-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.mkb-vpb`) declareren onder
`Bookkeeping > Vennootschapsbelasting` met `type: index` voor de
Vpb-pligtige cost-centers / accounts + `type: detail` voor de
Vpb-balans + aangifte voorbereiding per ondernemingsactiviteit. Per
ADR-024 Tier-4, no bespoke Vue files.

#### Scenario: Vpb-menu toggles with the feature flag

- **GIVEN** de manifest declareert `featureFlags.mkb-vpb`
- **WHEN** de flag ON staat
- **THEN** het Vpb-menu MUST verschijnen.
- **WHEN** de flag OFF staat
- **THEN** het menu MUST NOT renderen.
