# Spec: bookkeeping-r-d-subsidies-mkb

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (MKB / innovation)
**Depends on:** bookkeeping-subsidie-verantwoording

## ADDED Requirements

### REQ-RDS-001: The system SHALL declareren een `subsidieRegeling` enum op het bestaande Subsidie register voor de R&D-regelingen

The T3 `Subsidie` register (uit
`bookkeeping-subsidie-verantwoording`) MUST een
`subsidieRegeling` enum field krijgen met waarden `mit | sbir |
eu-horizon | efro | react-eu | other`. Per ADR-031 + ADR-022 dit
is een schema-overlay — geen parallel `RDSubsidie` register. Wanneer
`subsidieRegeling ≠ 'other'` MUST de regeling-specifieke
kostencategorieën constraint (REQ-RDS-002) gelden.

#### Scenario: Een EU-Horizon subsidie krijgt het regeling-label

- **GIVEN** een nieuw `Subsidie` record
- **WHEN** opgeslagen met `subsidieRegeling: 'eu-horizon'`
- **THEN** de save MUST slagen; **AND** verdere kostendossier-
  inputs MUST uitsluitend de EU-Horizon kostencategorieën accepteren
  (REQ-RDS-002).

### REQ-RDS-002: Per-regeling kostencategorieën SHALL declaratief geconstrained worden

Elke `subsidieRegeling` waarde MUST een specifieke set toegestane
`kostencategorie` waarden hebben (declaratief via JSON Schema
`oneOf` of `if/then` constructie):

| Regeling | Toegestane kostencategorieën |
|---|---|
| `mit` | `personnel`, `materials`, `external-services`, `equipment-depreciation`, `other-direct` |
| `sbir` | `personnel`, `materials`, `equipment-depreciation`, `other-direct` |
| `eu-horizon` | `personnel`, `subcontracting`, `other-direct`, `indirect-25-percent` |
| `efro` | `personnel`, `external-services`, `materials`, `equipment`, `other`, `indirect-flat-rate` |
| `react-eu` | (zelfde als EFRO + REACT-specifieke `green-recovery`) |

Per ADR-031 geen PHP categorie-validator.

#### Scenario: Een ongeldige categorie voor EU-Horizon wordt geweigerd

- **GIVEN** een `Subsidie` met `subsidieRegeling: 'eu-horizon'`
- **WHEN** een kostenpost met `kostencategorie: 'equipment'`
  geboekt wordt (Horizon staat dat niet als directe categorie toe)
- **THEN** de save MUST falen met een schema-validation error.

### REQ-RDS-003: Per-regeling voortgangsrapportage SHALL via een declarative aggregation gegenereerd worden

De voortgangsrapportage per R&D-subsidie MUST een
`x-openregister-aggregations` block zijn dat `kostenpost` records
groepeert per `kostencategorie` + `periodId`, gefilterd op de
parent subsidie. Een docudesk template per regeling MUST de
rapportage in het door de subsidie-verstrekker vereiste formaat
renderen (e.g. EU Horizon Periodic Report layout, MIT
voortgangsrapport). Geen PHP rapportage-renderer.

#### Scenario: Horizon voortgangsrapportage groeperende kosten per Horizon-categorie

- **GIVEN** een Horizon-subsidie met kostenpost-records in elke
  Horizon-categorie
- **WHEN** de voortgangsrapportage gerendered wordt
- **THEN** het docudesk document MUST de Horizon-canonical
  layout volgen, met cumulatieve + periodieke totalen per
  Horizon-categorie.

### REQ-RDS-004: Per-regeling audit-pack SHALL per regelings-eisen samengesteld worden via docudesk templates

Elke R&D-regeling heeft eigen audit-trail eisen — EU Horizon vereist
een `Audit Certificate` met expliciete timesheets bewijzen; MIT
vereist een verklaring van de WBSO/S&O administratie; EFRO vereist
een aanbestedingsdossier per inkoop > drempel. Per regeling MUST een
docudesk template een audit-pack samenstellen uit de OR audit-trail-
immutable + de subsidie's kostendossier + relevante external attachments
(by URI per ADR-022). Geen PHP audit-pack-builder.

#### Scenario: EU-Horizon audit-pack include's timesheets-referenties

- **GIVEN** een Horizon-subsidie met personnel-kosten + S&O-uren-
  staten gerelateerd
- **WHEN** de audit-pack gerendered wordt
- **THEN** het docudesk document MUST per personnel-kostenpost een
  URI-referentie naar de gerelateerde S&O-uren-staat bevatten.

### REQ-RDS-005: Budget bewaking per regeling SHALL warnings surfaceren bij dreigend overschrijden van een kostencategorie-max

Elke R&D-regeling heeft per-kostencategorie sub-maxima (e.g. Horizon
indirect-25% is bound aan 25% van de directe kosten). Een
`x-openregister-calculations` block per regeling MUST dit
verifiëren en een warning surfacen wanneer ≥90% van het max bereikt
is. Per ADR-031 declaratief — geen PHP budget-watcher.

#### Scenario: Horizon indirect-25% warning bij 90% gebruik

- **GIVEN** een Horizon-subsidie met €100 000 directe kosten en
  €22 500 indirect-25% kosten (geprojecteerd over het remaining
  budget)
- **WHEN** de budget-bewaking calculation runs
- **THEN** een warning MUST verschijnen ("indirect-25%-grens van
  €25 000 nadert; huidige bezetting 90%").

### REQ-RDS-006: R&D-subsidies-administratie SHALL be reachable through a feature-flag-controlled manifest navigation entry

`src/manifest.json` MUST een feature-flag-controlled menu entry
(`featureFlags.mkb-r-d-subsidies`) declareren onder
`Bookkeeping > R&D Subsidies` met `type: index` per regeling +
`type: detail` per subsidie (budget, kostendossier,
voortgangsrapportage, audit-pack). Per ADR-024 Tier-4, no bespoke
Vue files.

#### Scenario: R&D-subsidies-menu toggles with the feature flag

- **GIVEN** de manifest declareert `featureFlags.mkb-r-d-subsidies`
- **WHEN** de flag ON staat
- **THEN** het menu MUST verschijnen.
- **WHEN** de flag OFF staat
- **THEN** het menu MUST NOT renderen.
