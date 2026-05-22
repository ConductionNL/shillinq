# Spec: IB Aangifte (Income Tax Return) Assembly for ZZP

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (regulatory + compliance)  
**Primary spec:** bookkeeping-ib-aangifte-zzp  

**Depends on:**
- zzp-urencriterium-tracker (validates ≥1225 hours for zelfstandigenaftrek)
- bookkeeping-investeringsaftrek (KIA/EIA/MIA/VAMIL aggregation)
- bookkeeping-chart-of-accounts (RGS-compliant GL structure for mapping)
- bookkeeping-ap-ar (revenue & cost source)
- bookkeeping-fixed-assets (depreciation & disposal detail)

---

## Overview

This spec enables ZZP'ers and small entrepreneurs to automatically assemble, validate, and submit their annual income tax return (P-formulier) in compliance with Wet inkomstenbelasting 2001 (Wet IB), Algemene wet inzake rijksbelastingen (AWR), and Dutch Belastingplan annual updates.

The system aggregates GL data, applies fiscal-commercial P&L adjustments per Wet IB art. 3.6–3.79a, calculates relief entitlements (zelfstandigenaftrek, MKB-exemption, investeringsaftrek, heffingskortingen), and generates a valid XBRL instance per Dutch Taxonomy NT17 for Digipoort submission.

Eight new entities are declared:
1. **IBAangifte** — primary return record per fiscal year
2. **IBWinstOpgave** — fiscal profit detail with art. 3.6–3.79a adjustments
3. **IBOndernemersaftrek** — self-employed deductions
4. **IBHeffingskortingenAlgemeen** — tax credits (AHK, arbeidskorting, IACK, ouderenkorting, jonggehandicaptenkorting)
5. **IBLijfrenteAOV** — pension savings room (jaarruimte + reserveringsruimte per art. 3.127)
6. **IBBijtellingAuto** — private-use car bijtelling per art. 3.20 (per vehicle)
7. **IBBox3Vermogen** — savings/investment income per overbruggingswet
8. **IBAuditTrail** — complete drill-down herleidbaarheid to GL journaalposten + external evidence

---

## Requirements

### Requirement: REQ-IB-001 Automated aangifte aggregation from GL

The system MUST aggregate GL account balances, apply RGS-to-rubriek mapping (via OpenRegister aggregation queries), and pre-fill all P-formulier fields automatically upon aangifte creation. Every aggregated value MUST link to its GL source(s) via the IBAuditTrail entity.

#### Scenario: Complete pre-fill within 10 seconds on year-end close

- **GIVEN** a ZZP'er has closed fiscal year 2025 in Shillinq with GL balances posted (omzet, kosten, afschrijvingen, heffingen)
- **WHEN** the user clicks "IB-aangifte 2025 starten"
- **THEN** the system within 10 seconds creates a concept IBAangifte with:
  - **IBWinstOpgave**: omzet (GL 8000–8099), kostprijs (GL 1300–1399), brutowinst, afschrijvingen (GL 6500–6599), huisvesting, autokosten, kantooradministratie, algemene kosten, all aggregated and summed
  - **IBOndernemersaftrek**: zelfstandigenaftrek (EUR 2.470 × eligibility), startersaftrek (EUR 2.123 × 3 if in first 5 years), MKB-exemption (12,7% of winst after aftrek per 2026 tariff)
  - **IBHeffingskortingenAlgemeen**: AHK, arbeidskorting, IACK (if gezinssituatie flags match)
  - **IBLijfrenteAOV**: jaarruimte (13,3% × (premiegrondslag − franchise 2026)), reserveringsruimte (prior 10-year carryforward)
  - **IBBox3Vermogen**: balans-date vermogen (bank/spaartegoeden + overige bezittingen − schulden), heffingvrij-vermogen cap (EUR 57.684 in 2026), rendement (werkelijk or forfait per choice)
  - **IBAuditTrail**: 200+ regelns linking each rubric value to GL journaalpost IDs + external API responses
- **AND** aangifte status = CONCEPT (not yet gevalideerd)
- **AND** the system displays a summary: "Fiscale winst EUR 47.820, Ondernemersaftrek EUR 5.616, MKB-exemption EUR 5.618, Belastbare winst EUR 36.586. Teruggave geschat EUR 5.470."

#### Scenario: Fiscal-commercial P&L reconciliation with adjustments logged

- **GIVEN** ondernemer booked EUR 4.200 representatiekosten (GL account 6100)
- **WHEN** the IB-aggregation process runs
- **THEN** the system:
  - Retrieves representatiekosten from GL (EUR 4.200)
  - Calculates representatiebeperking (art. 3.15): max 5% winst = 5% × EUR 47.820 = EUR 2.391
  - Creates a fiscal adjustment: `{"post": "REPRESENTATIE_DREMPEL", "bedrag": -1809, "grondslag": "art. 3.15 Wet IB 2001"}`
  - Updates `IBWinstOpgave.representatieCorrectie = 1809`
  - Updates `IBWinstOpgave.winstVoorOndernemersaftrek = 47.820 - 1.809 = 46.011`
  - Logs the adjustment in `IBWinstOpgave.fiscaleAfwijkingenLog` for accountant review
- **AND** the XBRL instance includes the adjustment line with correct rubriek code

### Requirement: REQ-IB-002 Zelfstandigenaftrek validation against urencriterium

The system MUST NOT allow zelfstandigenaftrek (EUR 2.470 in 2026) unless the ondernemer has met the urencriterium (≥1225 hours in the fiscal year), verified via the `zzp-urencriterium-tracker` API. If urencriterium is not met, the system blocks zelfstandigenaftrek with a blokkende waarschuwing and a direct link to the urentracker for repair.

#### Scenario: Aftrek toegestaan bij behaald urencriterium

- **GIVEN** the urencriterium-tracker rapporteert 1462 ondernemingsuren voor 2025
- **WHEN** the IB-assemblagecycle calculates ondernemersaftrek
- **THEN** the system:
  - Fetches the urenrapport from the tracker API
  - Validates `rapport.uren >= 1225` ✓
  - Sets `IBOndernemersaftrek.urencriterium.behaald = true`
  - Sets `IBOndernemersaftrek.zelfstandigenaftrek.toegestaan = true`
  - Sets `IBOndernemersaftrek.zelfstandigenaftrek.bedrag = 2470` (2025 tariff, parameterized per year)
  - Sets `IBOndernemersaftrek.urencriterium.evidenceRef = "uren-tracker-2025-ond-001234"` for audit trail
  - Includes `zelfstandigenaftrek: 2.470` in the final belastbare-winst berekening

#### Scenario: Aftrek geblokkeerd bij onvoldoende uren

- **GIVEN** the urencriterium-tracker rapporteert 1180 uren voor 2025 (45 uren tekort)
- **WHEN** the IB-assemblagecycle calculates ondernemersaftrek
- **THEN** the system:
  - Validates `rapport.uren < 1225` ✗
  - Sets `IBOndernemersaftrek.urencriterium.behaald = false`
  - Sets `IBOndernemersaftrek.zelfstandigenaftrek.toegestaan = false`
  - Sets `IBOndernemersaftrek.zelfstandigenaftrek.bedrag = 0`
  - Sets aangifte status = "GEVALIDEERD_MET_WAARSCHUWING"
  - Displays blokkende error: "Zelfstandigenaftrek niet toegestaan. Urencriterium niet behaald: 1180 uren gerapporteerd; 1225 uren vereist (45 uren tekort). Uren toevoegen in de urentracker."
  - Provides a clickable link to the urentracker module for repair
  - Does NOT allow filing (status = INGEDIEND) until uren are corrected OR accounted-skipped

### Requirement: REQ-IB-003 MKB-winsvrijstelling automatic application

After ondernemersaftrek is calculated, the system MUST automatically compute the MKB-exemption (2026: 12,7% per Belastingplan 2024, subject to annual parameter update) on the profit after aftrek. If the result is a loss (after aftrek), the exemption is not applied (user choice); the system logs this decision.

#### Scenario: MKB-exemption correctly applied

- **GIVEN** winst na ondernemersaftrek = EUR 41.947
- **WHEN** MKB-winsvrijstelling is calculated
- **THEN** the system:
  - Calculates exemption = 41.947 × 0.127 = EUR 5.327,27
  - Sets `IBOndernemersaftrek.mkbWinstvrijstelling = 5.327,27`
  - Sets belastbare winst = 41.947 − 5.327,27 = EUR 36.619,73
  - Includes the exemption amount in the XBRL instance (rubriek: MKB-winsvrijstelling)
  - Logs the exemption calculation with tariff reference ("2026: 12,7% per Belastingplan 2024")

#### Scenario: MKB-exemption skipped when loss

- **GIVEN** winst na ondernemersaftrek = EUR −3.200 (loss)
- **WHEN** MKB-exemption calculation is attempted
- **THEN** the system:
  - Detects loss condition
  - Sets `IBOndernemersaftrek.mkbWinstvrijstelling = 0` (not applicable)
  - Logs decision: "MKB-exemption overgeslagen wegens verlies. Verlies EUR 3.200 beschikbaar voor inbreng volgend jaar."
  - Allows the user to optionally carry forward the loss to the next fiscal year (form field: `verliesvortrag`)

### Requirement: REQ-IB-004 Investment deduction integration (KIA/EIA/MIA/VAMIL)

The spec MUST automatically pull KIA, EIA, MIA, and VAMIL deduction amounts from the `bookkeeping-investeringsaftrek` spec, validate the linkage, and include them in IBOndernemersaftrek with full evidence trails.

#### Scenario: KIA included from investeringsaftrek spec

- **GIVEN** the investeringsaftrek module has calculated EUR 2.490 KIA-recht for fiscal year 2025
- **WHEN** the IB-aangifte aggregation runs
- **THEN** the system:
  - Queries the investeringsaftrek API: `getDeductionsForYear(2025, ondernemer-id)`
  - Retrieves `{ "kia": 2490, "eiaE": 0, "mia": 0, "vamil": 0 }`
  - Creates `IBOndernemersaftrek.investeringsaftrek = { "kia": 2490, "eiaE": 0, "mia": 0, "vamil": 0 }`
  - Links each deduction to the underlying investering-record(s): `"kia_evidence_ref": "investeringsaftrek-2025-ond-xxx-staffel-1-3"`
  - Includes the total investeringsaftrek in the ondernemersaftrek sum
  - Allows the accountant to drill down from XBRL rubriek to investeringsaftrek staffel detail

### Requirement: REQ-IB-005 Lijfrente jaarruimte and reserveringsruimte per art. 3.127

The system MUST calculate jaarruimte (13,3% × (premiegrondslag − franchise 2026)) and cumulative 10-year reserveringsruimte on each fiscal year. The calculations use age-adjusted formulae and annually-indexed franchises. Both are pre-filled but editable by the accountant with grondslag override.

#### Scenario: Jaarruimte 2026 correctly calculated

- **GIVEN** winst 2025 = EUR 41.947, ondernemer age 47 (AOW eligible per normal schedule)
- **AND** franchise 2026 = EUR 17.546 (indexed annually per law)
- **WHEN** jaarruimte 2026 is calculated
- **THEN** the system:
  - Calculates premiegrondslag = min(winst 2025 na MKB, EUR 107.000 cap in 2026) = EUR 41.947
  - Calculates room = 13.3% × (41.947 − 17.546) = 13.3% × 24.401 = EUR 3.245,33
  - For age 47 (no AOW reduction yet), the full 13.3% rate applies
  - Sets `IBLijfrenteAOV.jaarruimte2025 = {"berekend": 3.245,33, "benut": 0, "resterend": 3.245,33}`
  - Stores the grondslag: "art. 3.127 Wet IB 2001, 13.3% (13th general rate 2026)"
  - Displays in UI: "Jaarruimte 2026: EUR 3.245,33. (U kunt EUR 3.245,33 sparen in lijfrente- of pensioenpolissen.)"

#### Scenario: Reserveringsruimte from prior 10-year history

- **GIVEN** the ondernemer has not fully utilized jaarruimte in 2016–2024
  - 2016: jaarruimte EUR 2.800, benut EUR 1.500, carryover EUR 1.300
  - 2017–2024: various carryovers totaling EUR 7.200 cumulative unused
- **WHEN** reserveringsruimte 2026 is calculated
- **THEN** the system:
  - Queries prior-year IBAangifte records (or IBAuditTrail for historical data)
  - Calculates cumulative unused room: min(EUR 7.200, EUR 9.200 cap in 2026) = EUR 7.200
  - Sets `IBLijfrenteAOV.reserveringsruimte2026 = {"berekend": 7.200, "benut": 0, "resterend": 7.200}`
  - Displays in UI: "Reserveringsruimte 2026 (vorig jaarruimte niet benut): EUR 7.200. (Totale beleggingsruimte 2026: EUR 3.245 + EUR 7.200 = EUR 10.445.)"
  - Stores audit trail: "Reserveringsruimte calculated from prior-year history 2016–2024; total unused EUR 7.200 (capped at EUR 9.200)"

### Requirement: REQ-IB-006 Tax credits (heffingskortingen) pre-calculation

Algemene heffingskorting (AHK), arbeidskorting (AK), IACK (alleenstaande ouders), ouderenkorting, and jonggehandicaptenkorting MUST be automatically calculated based on box-1-inkomen, gezinssituatie flags, and age. All credits use annually-updated parameters (stored in IBTaxParameterYear metadata).

#### Scenario: Algemene heffingskorting with phase-out

- **GIVEN** box-1-inkomen EUR 56.000 in 2026
- **AND** ondernemer is single (geen partner) with no eligible children (IACK criteria not met)
- **WHEN** heffingskortingen are calculated
- **THEN** the system:
  - Calculates AHK per formula: max(EUR 3.362 − 6.337% × (inkomen − EUR 28.406), 0) for 2026
  - AHK = max(3.362 − 6.337% × (56.000 − 28.406), 0) = max(3.362 − 1.750, 0) = EUR 1.612
  - Sets `IBHeffingskortingenAlgemeen.algemeneHeffingskorting = 1.612`
  - Calculates arbeidskorting (AK) based on income tier (separate formula for 2026)
  - Sets total heffingskortingen sum
  - Displays in UI: "Heffingskortingen: Algemene heffingskorting EUR 1.612 + Arbeidskorting EUR X = EUR Y"

#### Scenario: IACK for single parent

- **GIVEN** ondernemer is alleenstaande ouder with:
  - 1 child, age 6 (< 12, IACK-eligible)
  - No working partner (ineligible for IACK per criteria)
  - Box-1-inkomen EUR 35.000
- **WHEN** IACK is calculated
- **THEN** the system:
  - Checks gezinssituatie flags: alleenstaande=true, child_<12=true, partner_werkend=false
  - Since partner is not working, IACK is EUR 0 (per art. 1.2 Wet IB: requires inkomstenbelaste partner earning income)
  - Sets `IBHeffingskortingenAlgemeen.iack = 0`
  - Logs: "IACK ineligible: no inkomstenbelaste working partner. (IACK criterion: inkomstenbelaste partner with earned income required.)"

### Requirement: REQ-IB-007 XBRL instance generation per Dutch Taxonomy NT17

The system MUST generate a valid XBRL instance per Dutch Taxonomy (NT17 for belastingjaar 2025+) with all mandatory P-formulier rubrics populated. The instance MUST pass validation against the NT17 schema before marking the aangifte as GEVALIDEERD or INGEDIEND.

#### Scenario: XBRL instance valid and submittable

- **GIVEN** a completed IBAangifte with all entities filled (winstopgave, aftrekken, heffingskortingen, box3)
- **WHEN** "Genereer XBRL-instance" is clicked
- **THEN** the system:
  - Maps each IBx entity field to the corresponding XBRL rubriek (NT17 codification)
  - Serializes the XBRL instance (XML per SBR/XBRL standard)
  - Validates the instance against the NT17 schema (all mandatory rubrics present, data types correct)
  - If validation passes ✓:
    - Sets `IBAangifte.xbrlInstanceId = "xbrl-2025-ond-xxx-v1"`
    - Stores the XBRL instance in openregister storage (7-year retention per AWR art. 52)
    - Sets status = XBRL_GEVALIDEERD
    - Displays message: "XBRL-instance gegenereerd en gevalideerd. Klaar voor indiening."
  - If validation fails ✗:
    - Displays error: "XBRL-validation faalt: Verplichte rubriek 'bsn_fiscaal_partner' ontbreekt. NT17 code: bsn_fp_xxx. Vul deze in en probeer opnieuw."
    - Blocks "Indienen" action until error is resolved

#### Scenario: XBRL validation fails due to missing mandatory field

- **GIVEN** an IBAangifte with a fiscaal partner (2 partners filed jointly), but `IBOndernemersaftrek` does not include partner's BSN
- **WHEN** XBRL generation runs
- **THEN** the system:
  - Detects NT17 mandatory rubriek "bsn_fiscaal_partner" as empty
  - Validation fails
  - Sets status = XBRL_VALIDATIE_FOUT
  - Displays blocking error with exact rubriek name + UI remediation action
  - Disables "Indienen"-knop until correction is made

### Requirement: REQ-IB-008 Becon-route fiscal intermediary filing

The system MUST support the Becon-route (fiscal intermediary with Beconnummer and PKIoverheid-services-certificaat) for tax professionals to file on behalf of ZZP'ers. The intermediary signs the XBRL with their certificate and submits to Digipoort.

#### Scenario: Fiscalist approves and digitally signs

- **GIVEN** a fiscalist (status = ROLE_FISCALIST, Beconnummer = B12345) is linked to a ZZP'er-client's aangifte (status = GEVALIDEERD)
- **WHEN** the fiscalist clicks "Indienen namens cliënt"
- **THEN** the system:
  - Verifies fiscalist has valid PKIoverheid-services-certificaat (stored in user profile / eID store)
  - Displays final review UI: all P-formulier rubrics, client name, BSN, aangifte amount
  - Fiscalist confirms: "Ik verklaar dat deze aangifte nauwkeurig is en conform de wettelijke bepalingen."
  - System digitally signs the XBRL instance with the fiscalist's PKIoverheid-certificaat (private-key operation, possibly via KSP or HSM in production)
  - Generates signed XBRL + Digipoort SOAP envelope (openconnector integration, future T4 hardening)
  - Sends to Digipoort FRC/AGV webservice
  - Upon successful submission, Digipoort returns `ontvangstbevestiging-ID` (e.g., "BD123456789")
  - System records:
    - `IBAangifte.status = INGEDIEND`
    - `IBAangifte.indieningKanaal = SBR_DIGIPOORT_BECON`
    - `IBAangifte.fiscalistBeconNummer = B12345`
    - `IBAangifte.ingediendOp = 2026-03-15T10:24:18Z`
    - `IBAuditTrail.digipoortOntvangst = "BD123456789"` (stored for Belastingdienst correspondence)
  - Displays success message: "Aangifte succesvol ingediend via Digipoort. Ontvangstbevestiging: BD123456789. (Bewaar deze code voor correspondentie met de Belastingdienst.)"

### Requirement: REQ-IB-009 Audit trail and full herleidbaarheid

Every P-formulier rubriek value MUST be herleidbaar to underlying GL journaalposten, external API responses, or brontabellen. Upon filing (status = INGEDIEND), the aangifte MUST be "gefreezed" so that post-filing GL changes do NOT retroactively alter the filed return.

#### Scenario: Drill-down from rubriek to journaalposten

- **GIVEN** an filed IBAangifte 2025 (status = INGEDIEND)
- **WHEN** the user (accountant or ZZP'er) clicks on "omzet excl btw EUR 82.400" in the aangifte summary
- **THEN** the system:
  - Queries `IBAuditTrail.regels` for rubriek = "omzet_excl_btw"
  - Displays: "Omzet: EUR 82.400 (GL account 8000–8099 geaggregeerd)"
  - Lists underlying GL journaalposten:
    - `jp-2025-01-001: 2025-01-15 Klant ABC, EUR 5.200`
    - `jp-2025-01-002: 2025-01-20 Klant XYZ, EUR 3.100`
    - `... (total 247 entries summing to EUR 82.400)`
  - Each journaalpost links to the invoice / transaction detail (from bookkeeping-ap-ar spec)
  - Provides export option: "Download journaalposten CSV for audit verification"

#### Scenario: Freeze post-filing prevents GL change backfill

- **GIVEN** an IBAangifte (status = INGEDIEND, ingediendOp = 2026-03-15)
- **AND** a GLLine record on account 8000 (omzet) is later created on 2026-03-20
- **WHEN** the accountant attempts to record the GL entry
- **THEN** the system:
  - Detects that GL account 8000 is referenced in an INGEDIEND aangifte (frozen)
  - Displays warning: "Wijziging aan GL-rekeningen die in ingediende aangifte voorkomen, vereist correctieaangifte (suppletie). Wilt u een correctieaangifte starten?"
  - Offers two options:
    1. "Correctieaangifte starten" — create new aangifte entity linked to original, with delta-calculation
    2. "Toch posten" — user confirms override; system logs override with justification (edit + timestamp)
  - Does NOT allow the GL change to retroactively affect the frozen aangifte

### Requirement: REQ-IB-010 Fiscale partner income distribution

For married/partnered ZZP'ers, the system MUST calculate the optimal distribution of aftrekposten (e.g., hypotheekrente, persoonsgebonden aftrekken) between partners to maximize belastingbesparing.

#### Scenario: Optimal partner income distribution

- **GIVEN** ondernemer and fiscale partner have combined hypotheekrente-aftrek EUR 8.200
- **AND** ondernemer: box-1-inkomen EUR 45.000 (marginal rate 37,35%)
- **AND** partner: box-1-inkomen EUR 25.000 (marginal rate 49,50%)
- **WHEN** "Verdeel optimaal" action is invoked
- **THEN** the system:
  - Calculates tax saving per EUR aftrek for each partner (49,50% > 37,35%)
  - Proposes: "Verdeel EUR 8.200 hypotheekrente volledig naar partner (lager inkomen, hogere tarief)"
  - Calculates gerealiseerde belastingbesparing: EUR 8.200 × (49,50% − 37,35%) = EUR 981,80 extra besparing vs. even split
  - Creates split: `IBAangifte.fiscalePartner = { "verdeling": "OPTIMAAL_BEREKEND", "hypotheekrente_partner": 8200 }`
  - Stores both IBAangifte records (ondernemer + partner) with linked cross-reference
  - Displays: "Optimale verdeling: EUR 8.200 hypotheekrente naar partner. Geschatte extra teruggave: EUR 982."

### Requirement: REQ-IB-011 Voorlopige aanslag actualization

The system MUST monitor the voorlopige aanslag (VA) vs. actual profit projection. If a significant divergence is detected (e.g., actual profit EUR 55.000 vs. VA based on EUR 30.000), the system MUST recommend a VA-wijzigingsverzoek to avoid belastingrente (art. 30hb AWR).

#### Scenario: VA too low; system recommends increase

- **GIVEN** voorlopige aanslag 2026 is EUR 5.000 (estimated on 2025 profit EUR 30.000)
- **AND** at 30 June 2026, the actuals show cumulative 6-month profit EUR 32.000, projected full-year EUR 55.000
- **WHEN** the "Controle VA" monitor runs (monthly or on-demand)
- **THEN** the system:
  - Calculates divergence: EUR 55.000 (projected) − EUR 30.000 (VA basis) = EUR 25.000 gap
  - Computes estimated belastingrente impact: EUR 25.000 × 4% (approx rate art. 30hb) = EUR 1.000 risk exposure
  - Displays warning: "Voorlopige aanslag te laag op basis van actuele resultaten. Rente risico EUR 1.000. Advies: Dienstorder VA-wijziging indienen bij Belastingdienst."
  - Provides prefilled form for VA-wijzigingsverzoek (to be filed by ZZP'er or fiscalist separately)

### Requirement: REQ-IB-012 Correctieaangifte and suppletie workflow

If a post-filing error is discovered (e.g., forgotten lijfrentepremie, uren-tracker correction, late KIA-claim), the system MUST support a correctieaangifte (amendment return) with full diff-tracking and delta teruggave/aanvullende-betaling calculation.

#### Scenario: Forgotten lijfrentepremie corrected

- **GIVEN** IBAangifte 2025 (status = INGEDIEND, filed 2026-03-14)
- **AND** on 2026-03-22, a overlooked lijfrentepremie EUR 2.400 is discovered
- **WHEN** the user clicks "Correctieaangifte starten"
- **THEN** the system:
  - Creates a new `IBAangifte` entity with `aangifteType = CORRECTIE_SUPPLETIE`
  - Pre-fills all fields from the original IBAangifte 2025 record
  - Allows edits: e.g., update `IBLijfrenteAOV.aovPremies.bedrag = 2.400`
  - Automatically recalculates:
    - New `IBOndernemersaftrek.totaalAftrek = 5.616 + 2.400 = 8.016` (example)
    - New belastbare winst EUR 36.420 (vs. original EUR 36.586)
    - New verschuldigde IB EUR 9.216 (vs. original EUR 9.420)
    - New teruggave EUR 5.671 (vs. original EUR 5.470, delta +EUR 201 in favor of ZZP'er)
  - Generates diff-report:
    ```
    Correctieaangifte 2025 vs. Original
    −−−−−−−−−−−−−−−−−−−−−−−−−−−−−−
    Lijfrentepremies: +EUR 2.400
    Ondernemersaftrek: +EUR 2.400
    Belastbare winst: −EUR 166 (EUR 36.586 → EUR 36.420)
    Verschuldigde IB: −EUR 204 (EUR 9.420 → EUR 9.216)
    Teruggave: +EUR 201 (EUR 5.470 → EUR 5.671)
    ```
  - Marks `IBAangifte.vorige_aangifte_id = "ib-aangifte-2025-ond-xxx"` (links to original)
  - Status = GEVALIDEERD (not yet INGEDIEND); ready for approval + filing
  - Stores both aangiften (original + correctie) for 7-year retention per AWR art. 52

### Requirement: REQ-IB-013 Auto bijtelling for private-use company cars

Per art. 3.20 Wet IB, the system MUST calculate fiscal bijtelling (private-use taxation) for each company vehicle based on cataloguswaarde, bijtellingspercentage (22% standard, 17% for zero-emission up to EUR 30K per staffel), and ownership duration.

#### Scenario: Regular company car with 22% bijtelling

- **GIVEN** ondernemer operates a zakelijke auto with:
  - Cataloguswaarde nieuw: EUR 38.000
  - Date eerste registratie: 2024-04-15 (in use <1 full year on 2025-12-31)
  - Fuel type: benzine
  - No sluitende kilometeradministratie for privé <500 km
- **WHEN** IBBijtellingAuto is calculated for 2025
- **THEN** the system:
  - Determines bijtellingspercentage = 22% (standard per art. 3.20)
  - Calculates bijtelling = EUR 38.000 × 22% = EUR 8.360
  - Sets `IBBijtellingAuto.bijtellingsPct = 0.22, bijtellingBedrag = 8.360`
  - Includes the bijtelling as a **non-deductible** component added back to winst in IBWinstOpgave
  - Updates winst: `winstUitOnderneming += 8.360` (bijtelling is an untaxed benefit, increases taxable base)
  - Logs grondslag: "art. 3.20 Wet IB 2001, 22% standaardtarief"

#### Scenario: Electric vehicle with tiered bijtelling

- **GIVEN** ondernemer operates a zero-emission auto with:
  - Cataloguswaarde: EUR 52.000
  - Fuel type: electric
  - Full year in use on 2025-12-31
  - No kilometeradministratie
- **WHEN** IBBijtellingAuto is calculated for 2025
- **THEN** the system:
  - Applies tiered EV rate per 2026 law (for EV in use during 2025):
    - First EUR 30.000: 17% → EUR 5.100
    - Excess EUR 22.000: 22% → EUR 4.840
    - Total bijtelling: EUR 9.940
  - Sets `IBBijtellingAuto.bijtellingsPct = 0.19 (weighted ~), bijtellingBedrag = 9.940`
  - Includes in IBWinstOpgave as non-deductible benefit
  - Logs grondslag: "art. 3.20 Wet IB 2001, EV-staffel 2025 (17% tot EUR 30K, 22% daarbovenGrammar)"

### Requirement: REQ-IB-014 Home-office deduction per art. 3.16 Wet IB

Per art. 3.16 lid 2 Wet IB, home-office deduction is subject to strict qualification rules (separate entrance, dedicated space, economic use, 65% income threshold). The system MUST enforce these rules; if not met, the deduction is rejected with a clear grondslag reference.

#### Scenario: Home-office does not qualify; deduction rejected

- **GIVEN** ondernemer claims EUR 1.800 home-office-aftrek for kantoor in woonkamer
- **AND** no separate ingang or dedicated ruimte (shared with living room)
- **WHEN** the system validates home-office claim (REQ-IB-014)
- **THEN** the system:
  - Checks ondernemer's response to kwalificatievragen:
    - "Eigen ingang?" → NO
    - "Dedicated werkruimte?" → NO
  - Determines kwalificatie = NOT_MET
  - Rejects aftrek with message: "Home-office-aftrek niet toegestaan. Art. 3.16 Wet IB 2001 vereist een zelfstandige werkruimte (eigen ingang, eigen sanitair). Uw kantoor in de woonkamer voldoet niet aan deze criteria."
  - Suggests alternative: "Aftrek werkruimte via huisvesting-componenten (art. 3.17) is mogelijk. Vul huisvesting-kosten in en wij berekenen het toelaatbare deel."
  - Excludes EUR 1.800 from IBWinstOpgave.huisvestingskosten

### Requirement: REQ-IB-015 Box 3 savings and investments per overbruggingswet

The system MUST aggregate box-3 vermogen (bank/spaartegoeden, overige bezittingen, schulden) from the balans as of 1 January (peildatum), apply heffingvrijVermogen cap per overbruggingswet, and calculate rendement per werkelijk (reported interest + dividend) or forfait (% per law), whichever is lower.

#### Scenario: Box 3 with savings and investments

- **GIVEN** ondernemer's 1 January 2026 balans shows:
  - Bank & spaartegoeden: EUR 28.000
  - Overige bezittingen (aandelen, obligaties): EUR 41.000
  - Schulden: EUR 0
- **AND** heffingvrij vermogen 2026 = EUR 57.684 (indexed annually)
- **WHEN** IBBox3Vermogen is calculated for 2026
- **THEN** the system:
  - Sums totaalRendementsgrondslag = EUR 28.000 + EUR 41.000 = EUR 69.000
  - Calculates belastbareGrondslag = EUR 69.000 − EUR 57.684 = EUR 11.316
  - Calculates rendement (both werkelijk + forfait options):
    - **Werkelijk rendement** (from bank statements, dividend reports): EUR 680 interest + EUR 350 dividend = EUR 1.030
    - **Forfaitrendement** per overbruggingswet: EUR 11.316 × assume 14% statutory rate = EUR 1.584
    - System chooses lower: werkelijk EUR 1.030 ✓
  - Sets `IBBox3Vermogen.berekendRendement = { "methode": "WERKELIJK", "rendement": 1.030 }`
  - Includes EUR 1.030 in `IBBox3Vermogen.belastingverplichting` (box-3 income taxed at flat 49,5% per overbruggingswet)
  - Displays in aangifte: "Box 3 Vermogen: EUR 69.000. Heffingvrij: EUR 57.684. Belastbare grondslag: EUR 11.316. Werkelijk rendement: EUR 1.030 (gekozen boven forfaitrendement EUR 1.584)."

---

## Lifecycle and Validations

### IBAangifte Status Machine
```
CONCEPT
  ↓ (all fields filled, no validation errors)
GEVALIDEERD (or GEVALIDEERD_MET_WAARSCHUWING if urencriterium missing)
  ↓ (XBRL generated & validated)
XBRL_GEVALIDEERD
  ↓ (approval, sign, submit)
INGEDIEND (frozen, post-filing amendments via correctieaangifte)
```

### Blocking Validation Gates
- ✗ **urencriterium < 1225** → zelfstandigenaftrek blocked; aangifte stuck in GEVALIDEERD_MET_WAARSCHUWING
- ✗ **XBRL validates failure** → no "Indienen" allowed; status = XBRL_VALIDATIE_FOUT
- ✗ **Becon signing fails** (no valid cert) → submit blocked

### Audit Retention (AWR art. 52)
- All entities (IBx, IBAuditTrail, XBRL instance, GL snapshots) retained in openregister for 7 fiscal years
- Freeze mechanism prevents post-filing GL alterations of frozen aangifte fields

---

## Success Metrics

1. ✓ ZZP'er pre-fill completion time: **<10 seconds** from "Start IB 2025" click
2. ✓ Audit trail herleidbaarheid: **100% of rubrics** linked to GL journaalposten or external API
3. ✓ Urencriterium validation: **blocking gate** if <1225 hours, with clear remediation path
4. ✓ XBRL validity rate: **100% valid** for NT17 schema on first Digipoort submission
5. ✓ Becon-route e2e time: **<5 minutes** from approval to Digipoort ontvangstbevestiging
6. ✓ Fiscale adjustments transparency: **zero audit queries** due to unexplained deduction denials (e.g., representatie, goodwill)
7. ✓ Optimization suggestions: **>50% adoption** of lijfrente room, FOR drawdown, MKB-exemption recommendations
