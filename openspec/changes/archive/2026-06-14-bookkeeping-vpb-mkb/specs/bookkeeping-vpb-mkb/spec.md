# Spec: bookkeeping-vpb-mkb

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (regulatory + compliance)  
**Depends on:** bookkeeping-financial-statements (T2), bookkeeping-sbr-xbrl-reporting (T3), bookkeeping-general-ledger (T1), bookkeeping-tax-calendar (T2)

---

## ADDED Requirements

### Requirement: REQ-VPB-001 — The system SHALL allow exactly one Vpb-aangifte per belastingplichtige per belastingjaar

The system MUST enforce a unique `(belastingplichtige, belastingjaar)` constraint on `VpbAangifte` and MUST refuse creation of a second aangifte for the same pair until the prior aangifte is `onherroepelijk` (or formally heropend under the wettelijke 5-jaars heropeningstermijn per art. 53 Wet Vpb / art. 16 AWR).

#### Scenario: Duplicate aangifte for the same belastingjaar is refused

- **GIVEN** belastingplichtige "ACME BV" already has a `VpbAangifte` for belastingjaar 2026 in status `ingediend`
- **WHEN** the fiscalist attempts to create a second `VpbAangifte` for "ACME BV" belastingjaar 2026
- **THEN** the system rejects the creation on the `(belastingplichtige, belastingjaar)` unique constraint and surfaces a validation error.

#### Scenario: New aangifte allowed once the prior is onherroepelijk

- **GIVEN** belastingplichtige "ACME BV" has a `VpbAangifte` for belastingjaar 2025 in status `onherroepelijk`
- **WHEN** a new `VpbAangifte` for "ACME BV" belastingjaar 2026 is created
- **THEN** the system accepts it as a distinct record (different belastingjaar) without conflict.

---

### Requirement: REQ-VPB-002 — The system SHALL bind commerciële winst to a vastgestelde jaarrekening and reconcile to fiscale winst

The system MUST store `VpbAangifte.commercieleWinst` as a FK to a specific, vastgestelde jaarrekening-version (`AnnualReport`, bookkeeping-financial-statements) and MUST refuse the `concept → ingediend` transition when that jaarrekening is not vastgesteld. Fiscale winst vóór verliezen MUST equal commerciële winst plus the sum of `FiscaleCorrectie.correctieBedrag` (each `correctieBedrag` = `fiscaalBedrag − commercieelBedrag`), each correction NTP-classified and motivated with a Wet Vpb-artikelverwijzing.

#### Scenario: Indiening blocked while the jaarrekening is not vastgesteld

- **GIVEN** a `VpbAangifte` whose `commercieleWinst` FK points at an `AnnualReport` in status `concept`
- **WHEN** the fiscalist triggers the `indienen` transition
- **THEN** `VpbAangifteGuard::canIndienen` returns false and the aangifte stays in `concept`.

#### Scenario: Fiscale correctie raises fiscale winst

- **GIVEN** a `VpbAangifte` with commerciële winst €420.000 and one `FiscaleCorrectie` (niet-aftrekbare kosten, art. 3.14) with commercieelBedrag €0 and fiscaalBedrag €30.000
- **WHEN** fiscale winst vóór verliezen is computed
- **THEN** `correctieBedrag` resolves to €30.000 and `fiscaleWinstVoorVerliezen` resolves to €450.000.

---

### Requirement: REQ-VPB-003 — The system SHALL apply the schijftarieven of the belastingjaar from the VpbTariefcatalogus

The system MUST compute `verschuldigdeVpb` as `tarief1 × min(belastbaar, belastbaarBedragGrens) + tarief2 × max(0, belastbaar − belastbaarBedragGrens)` using the `VpbTariefcatalogus` record for the aangifte's belastingjaar (2026: 19% / 25.8% / €245.000), and MUST parameterise the tarieven by belastingjaar so navorderingen reproducibly herrekenen.

#### Scenario: Graduated bracket over the grens

- **GIVEN** a belastbare fiscale winst of €450.000 for belastingjaar 2026
- **WHEN** `verschuldigdeVpb` is computed
- **THEN** the result is `0,19 × €245.000 + 0,258 × €205.000 = €46.550 + €52.890 = €99.440`.

#### Scenario: Below the grens only tarief1 applies

- **GIVEN** a belastbare fiscale winst of €100.000 for belastingjaar 2026
- **WHEN** `verschuldigdeVpb` is computed
- **THEN** the result is `0,19 × €100.000 = €19.000`.

---

### Requirement: REQ-VPB-004 — The system SHALL implement the innovatiebox with a verplichte S&O-verklaring

The system MUST support both the forfaitaire benadering (maximaal `innovatieboxForfaitDrempel` voordeel, beperkt tot the first 3 years after S&O-afgifte) and the werkelijke-winst-methode (nexus-factor begrensd op 1) per art. 12b/12bd Wet Vpb, and MUST bind every `Innovatiebox` claim to a `soVerklaringReferentie` (RVO/WBSO). The system MUST refuse the aangifte `indienen` transition when any innovatiebox claim lacks an S&O-verklaring.

#### Scenario: Indiening blocked when an innovatiebox claim lacks the S&O-verklaring

- **GIVEN** a `VpbAangifte` with one `Innovatiebox` claim whose `soVerklaringReferentie` is empty, jaarrekening vastgesteld and the belastingplichtige Digipoort-ready
- **WHEN** the fiscalist triggers `indienen`
- **THEN** `VpbAangifteGuard::canIndienen` returns false.

#### Scenario: Werkelijke-winst innovatiebox applies the nexus-factor

- **GIVEN** an innovatiebox claim via werkelijke-winst on €800.000 innovatieboxwinst with nexus-factor 0,85 and a valid S&O-verklaring
- **WHEN** the innovatiebox is applied
- **THEN** €680.000 (= €800.000 × 0,85) is taxed at the effectief 9%-tarief and the remainder follows the reguliere staffel.

---

### Requirement: REQ-VPB-005 — The system SHALL implement the deelnemingsvrijstelling with the three cumulative tests

The system MUST flag a `Deelneming` as kwalificerend at ≥5% nominaal gestort kapitaal and MUST require motivation for the oogmerktoets, onderworpenheidstoets and bezittingentoets before `deelnemingsvrijstellingVanToepassing` may be claimed per art. 13 Wet Vpb. The system MUST flag a possible low-taxed-portfolio-investment but MUST defer the final judgment to the fiscalist (with a motivated override preserved in the audit trail).

#### Scenario: Kwalificerende deelneming detected at 5%

- **GIVEN** a `Deelneming` with `aandeelhouderschapPercentage` 100
- **WHEN** `kwalificerendeDeelneming` is computed
- **THEN** it resolves to true.

#### Scenario: Vrijstelling requires the three test motivations

- **GIVEN** a `Deelneming` claiming `deelnemingsvrijstellingVanToepassing` without an oogmerktoets motivation
- **WHEN** the claim is reviewed
- **THEN** the system surfaces the missing-motivation gap for fiscalist attention.

---

### Requirement: REQ-VPB-006 — The system SHALL apply the voorvoegingsverlies regime of the verliesjaar

The system MUST auto-determine the verrekeningsregime per `verliesjaar` (≤2018 → 9 jaar voorwaarts; 2019–2021 → 6 jaar; ≥2022 → onbeperkt voorwaarts met de 50%-beperking boven `verliesverrekening50PctDrempel`), MUST compute `restant` = `oorspronkelijkBedrag − reedsVerrekend` and `verjaartIn` per regime, and MUST surface an expiration warning 12 months before verjaring. Expired losses MUST be preserved (not deleted) for navordering.

#### Scenario: Regime boundaries

- **GIVEN** voorvoegingsverliezen with verliesjaren 2018, 2020 and 2023
- **WHEN** the regime is determined
- **THEN** 2018 → `9jr`, 2020 → `6jr`, 2023 → `onbeperkt-50pct`.

#### Scenario: Verjaring date per regime

- **GIVEN** a voorvoegingsverlies with verliesjaar 2023 (6jr-regime)
- **WHEN** `verjaartIn` is computed
- **THEN** it resolves to 2029-12-31; for a ≥2022 verlies `verjaartIn` is null (onbeperkt).

---

### Requirement: REQ-VPB-007 — The system SHALL validate fiscale-eenheid voegingen against art. 15 conditions

The system MUST refuse a `FiscaleEenheid` voeging that does not satisfy `bezitPercentage` ≥ 95, gelijke boekjaren and vestiging in Nederland per art. 15 Wet Vpb, and MUST track voorvoegingsverliezen per dochter so an ontvoeging does not destroy dormant restricted carryforwards.

#### Scenario: Voeging below 95% bezit is refused

- **GIVEN** a `FiscaleEenheid` with `bezitPercentage` 80, gelijke boekjaren true and vestiging NL true
- **WHEN** `VpbAangifteGuard::canVoegen` is evaluated
- **THEN** it returns false.

#### Scenario: Compliant voeging is allowed

- **GIVEN** a `FiscaleEenheid` with `bezitPercentage` 100, gelijke boekjaren true and vestiging NL true
- **WHEN** `VpbAangifteGuard::canVoegen` is evaluated
- **THEN** it returns true.

---

### Requirement: REQ-VPB-008 — The system SHALL cumulate KIA/EIA/MIA/Vamil according to the statutory rules

The system MUST look up `aftrekPercentage` per belastingjaar from `VpbTariefcatalogus.facilityPercents`, MUST compute `aftrekBedrag` = `investeringsbedrag × aftrekPercentage`, and MUST flag a `cumulatieConflict` when EIA and MIA are stacked on the same investering (KIA+EIA and KIA+MIA are permitted; EIA+MIA is not).

#### Scenario: Forbidden EIA+MIA stacking flagged

- **GIVEN** an `InvesteringsAftrek` of type `EIA` whose `gecombineerdMet` contains `MIA`
- **WHEN** `cumulatieConflict` is computed
- **THEN** it resolves to true.

#### Scenario: Allowed KIA+EIA stacking not flagged

- **GIVEN** an `InvesteringsAftrek` of type `KIA` whose `gecombineerdMet` contains `EIA`
- **WHEN** `cumulatieConflict` is computed
- **THEN** it resolves to false.

---

### Requirement: REQ-VPB-009 — The system SHALL generate the SBR-XBRL instance and require eHerkenning EH3 for Digipoort

The system MUST require `Belastingplichtige.eHerkenningsNiveau` EH3+ and a non-empty `digipoortCertificaat` before the aangifte may be ingediend, MUST delegate SBR-XBRL-instance generation to `bookkeeping-sbr-xbrl-reporting` (NT-taxonomie-conform), MUST sign with the PKIO-Digipoort certificate (SGA-intermediair toegestaan) and MUST persist the Digipoort receipt in `digipoortReceiptId`. The certificate secret MUST live in the credential vault and MUST never be hardcoded or returned to the client.

#### Scenario: Indiening blocked when eHerkenning is below EH3

- **GIVEN** a belastingplichtige with `eHerkenningsNiveau` EH2 and a jaarrekening vastgesteld
- **WHEN** the fiscalist triggers `indienen`
- **THEN** `VpbAangifteGuard::canIndienen` returns false.

#### Scenario: Receipt persisted on successful submission

- **GIVEN** a fully eligible aangifte transitioning to `ingediend`
- **WHEN** the Digipoort transmission succeeds
- **THEN** the Digipoort receipt id is persisted in `digipoortReceiptId` and the status is `ingediend`.

---

### Requirement: REQ-VPB-010 — The system SHALL run the bezwaar/beroep workflow with statutory termijnen

The system MUST model the dispute as a state machine (bezwaar → uitspraak-inspecteur → beroep → hoger-beroep → cassatie), MUST compute `DefinitieveAanslag.bezwaartermijnEinde` = dagtekening + 6 weken and `BezwaarBeroep.termijnEinde` per type, and MUST refuse a bezwaar/beroep transition once the wettelijke termijn (6 weken per Awb art. 6:7) has passed, surfacing escalation alerts at T-7d, T-3d and on-day.

#### Scenario: Bezwaar within the 6-week termijn is admissible

- **GIVEN** a `DefinitieveAanslag` with a dagtekening 1 week ago linked to an `aanslag-ontvangen` aangifte
- **WHEN** `BezwaarTermijnGuard::canBezwaarMaken` is evaluated
- **THEN** it returns true.

#### Scenario: Bezwaar after the termijn is inadmissible

- **GIVEN** a `DefinitieveAanslag` with a dagtekening 8 weeks ago
- **WHEN** `BezwaarTermijnGuard::canBezwaarMaken` is evaluated
- **THEN** it returns false and the aanslag trends toward `onherroepelijk`.

#### Scenario: Beroep within 6 weeks of the inspecteur uitspraak is admissible

- **GIVEN** a `BezwaarBeroep` whose `uitspraakDatum` is 2 weeks ago
- **WHEN** `BezwaarTermijnGuard::canBeroepInstellen` is evaluated
- **THEN** it returns true.
