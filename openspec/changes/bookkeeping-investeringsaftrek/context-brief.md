---
status: draft
---

# Investeringsaftrek (KIA/EIA/MIA/Vamil)

## Purpose

The Dutch tax code (Wet IB 2001, art. 3.40-3.45) offers four distinct but partially overlapping investment-relief schemes that allow entrepreneurs and companies to deduct **extra** amounts from their taxable income (winst uit onderneming or Vpb-grondslag) on top of normal depreciation. These schemes — KIA (Kleinschaligheidsinvesteringsaftrek), EIA (Energie-investeringsaftrek), MIA (Milieu-investeringsaftrek) and Vamil (Willekeurige Afschrijving Milieu-investeringen) — represent one of the most fiscally significant areas of MKB bookkeeping. A misclassified asset can cost the entrepreneur thousands of euros of foregone aftrek, or worse, trigger a desinvesteringsbijtelling years later when the asset is disposed of early.

This spec codifies how shillinq tracks, classifies, validates, and reports investments against the four schemes. It must:

1. **Detect eligibility automatically** at the moment an asset is capitalised (factuur boekt op investeringsgoed), based on Energielijst-/Milieulijstcodes, amount thresholds, and cumulation rules.
2. **Compute the optimal claim** when multiple schemes apply (notably MIA + Vamil, or the rare KIA + EIA stack on different assets in the same year).
3. **Generate the RVO digital aanvraag** (electronic notification within 3 months of opdrachtverlening for EIA/MIA/Vamil — a hard statutory deadline; missing it forfeits the entire aftrek).
4. **Roll the totals into the Vpb-aangifte / IB-aangifte** so the boekhouder sees a single authoritative number for "extra investeringsaftrek dit boekjaar".
5. **Track the 5-year desinvesteringsbijtelling window** so disposals trigger a correction without manual intervention.

The spec is scoped narrowly to investment relief; ordinary degressive/lineair depreciation lives in `bookkeeping-fixed-assets-depreciation`, and the Vpb-aangifte assembly itself lives in `bookkeeping-vpb-corporate-tax`. R&D-specific schemes (WBSO, RDA) are out of scope and live in `bookkeeping-r-d-subsidies-mkb`. Energy/environmental subsidies that are *grants* (ISDE, SDE++) are also out of scope — this spec covers only the *tax-base reductions*.

Why this matters operationally: a typical MKB-onderneming met EUR 200.000 jaaromzet in groene investeringen (heat-pump, e-bedrijfsbus, LED-verlichting, solar) leaves between EUR 30.000 en EUR 80.000 aan fiscale aftrek op tafel als de meldingen niet kloppen of te laat zijn. The biggest single foutbron is **niet de berekening** maar het missen van de 3-maandsmeldingstermijn bij RVO. The second biggest is misclassificatie: een ondernemer denkt een asset valt onder EIA terwijl alleen MIA mogelijk is (of vice versa), de melding wordt afgewezen, en op dat moment is de termijn vaak al verstreken om opnieuw te melden onder het juiste regime. Deze spec moet beide voorkomen door (a) early validation tegen de actuele lijsten, en (b) deadline-bewaking met escalatie.

## Data Model

The model centres on six entities. JSON examples below use realistic 2026 Dutch data.

### InvestmentAsset

A capitalised asset that may qualify for one or more aftrek schemes. References the underlying fixed-asset record from `bookkeeping-fixed-assets-depreciation` (one-to-one).

```json
{
  "id": "ia-2026-00417",
  "fixedAssetId": "fa-2026-00417",
  "omschrijving": "Zonnepaneel-installatie dakvlak hoofdkantoor",
  "leverancier": "Solarius BV",
  "factuurnummer": "2026-04-1188",
  "aanschafdatum": "2026-04-22",
  "opdrachtverleningDatum": "2026-03-15",
  "ingebruiknameDatum": "2026-05-02",
  "aanschafwaarde": 50000.00,
  "valuta": "EUR",
  "btwRegime": "verleggings",
  "categorie": "duurzame_energie",
  "energielijstCode": "251701",
  "milieulijstCode": null,
  "kiaEligible": true,
  "eiaEligible": true,
  "miaEligible": false,
  "vamilEligible": false,
  "rvoMeldingStatus": "ingediend",
  "rvoMeldingDatum": "2026-04-25",
  "rvoMeldingDeadline": "2026-06-15",
  "rvoReferentie": "EIA-2026-998877"
}
```

### EnergielijstCode

Reference data — the RVO Energielijst 2026 with ~170 codes. Updated yearly (effective 1 januari). Each code carries category, omschrijving, eventual deelpercentage (sometimes only part of the asset qualifies), and maximum bedrag per kWp/m² where applicable.

```json
{
  "code": "251701",
  "jaartal": 2026,
  "categorie": "Duurzame energie",
  "omschrijving": "Zonnepaneelsysteem voor elektriciteitsopwekking, vermogen > 15 kWp en aangesloten op kleinverbruikersaansluiting (>3x80A)",
  "deelpercentage": 100,
  "maxBedragPerEenheid": null,
  "eenheid": null,
  "ingangsdatum": "2026-01-01",
  "vervaldatum": null
}
```

### MilieulijstCode

Reference data — RVO Milieulijst 2026 with ~250 codes. Critically, each code carries a `miaPercentage` (27%, 36% or 45%) and a `vamilToegestaan` flag. A code can be MIA-only, Vamil-only, or both.

```json
{
  "code": "G3110",
  "jaartal": 2026,
  "categorie": "Circulaire economie",
  "omschrijving": "Productiemiddel voor het recyclen van textiel uit afgedankte kleding",
  "miaPercentage": 45,
  "vamilToegestaan": true,
  "deelpercentage": 100,
  "maxBedrag": 25000000,
  "ingangsdatum": "2026-01-01"
}
```

### InvesteringsaftrekClaim

One row per (asset, scheme, boekjaar) combination. An asset claiming MIA + Vamil produces two rows. A KIA claim is aggregated at boekjaar-level (one row covering all KIA-eligible assets together) because KIA is tiered on the *total* annual investment.

```json
{
  "id": "claim-2026-EIA-00417",
  "investmentAssetId": "ia-2026-00417",
  "boekjaar": 2026,
  "scheme": "EIA",
  "grondslag": 50000.00,
  "percentage": 40.0,
  "aftrekbedrag": 20000.00,
  "status": "definitief",
  "ingediendInAangifte": "Vpb-2026",
  "rvoBeschikking": {
    "referentie": "EIA-2026-998877",
    "beschikkingsdatum": "2026-08-12",
    "toegekendBedrag": 50000.00
  }
}
```

### VamilDepreciation

Tracks the willekeurige afschrijving schedule. Under 2026 rules: **75% direct afschrijfbaar** in year of ingebruikname (or earlier if betaald), remaining **25% via reguliere afschrijving** over the normal useful life.

```json
{
  "id": "vamil-2026-00219",
  "investmentAssetId": "ia-2026-00219",
  "boekjaar": 2026,
  "aanschafwaarde": 80000.00,
  "directeAfschrijving": 60000.00,
  "gespreidDeel": 20000.00,
  "regulierAfschrijfschema": {
    "methode": "lineair",
    "looptijdJaren": 10,
    "restwaarde": 0.00,
    "jaarlijkseAfschrijving": 2000.00
  }
}
```

### KIATier

Reference data — the 2026 KIA tiered table. Tiers are recomputed yearly; values below are the 2026 official drempelbedragen (geïndexeerd op art. 3.41 Wet IB 2001).

```json
[
  {"tier": 1, "vanaf": 0.00,      "tot": 2800.00,     "percentage": 0,   "vastBedrag": 0,     "regel": "Onder drempel, geen aftrek"},
  {"tier": 2, "vanaf": 2800.00,   "tot": 70602.00,    "percentage": 28,  "vastBedrag": null,  "regel": "28% over geheel investeringsbedrag"},
  {"tier": 3, "vanaf": 70602.00,  "tot": 130744.00,   "percentage": null,"vastBedrag": 19769, "regel": "Vast maximumbedrag"},
  {"tier": 4, "vanaf": 130744.00, "tot": 392230.00,   "percentage": -7.56,"vastBedrag": 19769, "regel": "Vast bedrag minus 7,56% over deel boven 130.744"},
  {"tier": 5, "vanaf": 392230.00, "tot": null,        "percentage": 0,   "vastBedrag": 0,     "regel": "Boven plafond, geen KIA"}
]
```

## Requirements

### REQ-001: Asset categorisation at capitalisation

The system SHALL classify every newly capitalised asset against the four schemes at the moment the asset is created (or modified) in `bookkeeping-fixed-assets-depreciation`. Classification logic:

- **KIA**: eligible if `aanschafwaarde` is between EUR 450 (per-asset minimum) and EUR 392.230 (per-asset/per-year combined plafond), the asset is a `bedrijfsmiddel`, NOT excluded under art. 3.45 Wet IB 2001 (excluded: woonhuizen, grond, personenauto's behalve elektrisch/zakelijk, effecten, dieren, vaartuigen voor representatieve doeleinden, bedrijfsmiddelen bestemd voor verhuur aan derden).
- **EIA**: eligible if an Energielijstcode 2026 matches, AND aanschafwaarde per asset ≥ EUR 2.500, AND total EIA-claim across the boekjaar does not exceed EUR 151.000.000.
- **MIA**: eligible if a Milieulijstcode 2026 matches, AND aanschafwaarde per asset ≥ EUR 2.500.
- **Vamil**: eligible if Milieulijstcode carries `vamilToegestaan: true`, AND aanschafwaarde per asset ≥ EUR 2.500. Yearly Vamil-budget plafond EUR 25.000.000.

The classification SHALL be presented as a checklist with rationale ("EIA: code 251701 matches; KIA: ja, valt in tier 2 bij huidige jaartotaal van EUR 65.000") so the boekhouder can override.

### REQ-002: Energielijst/Milieulijst lookup with version pinning

The system SHALL maintain immutable yearly snapshots of the Energielijst and Milieulijst (typically loaded from RVO's published JSON / PDF in januari). When a claim is filed, the code MUST be resolved against the lijst of the `opdrachtverleningDatum`'s year — NOT today's lijst. This matters because codes are added, removed, and renumbered annually. The system SHALL provide a search UI keyed on omschrijving + category, and surface the most recent 3 years of lijsten for late filings.

### REQ-003: Threshold and minimum checks

Per-asset minimum of EUR 2.500 for EIA/MIA/Vamil SHALL be enforced. Per-asset minimum of EUR 450 for KIA SHALL be enforced. The yearly KIA plafond of EUR 392.230 of total investments SHALL trigger a warning at 80% utilisation. EIA and MIA each have absolute aftrek-maxima per boekjaar (EIA: EUR 151M aftrek, capped on a per-bedrijf basis under EU staatssteun-regels at EUR 25M; MIA: EUR 50M aftrek). For MKB these are theoretical, but the system SHALL still validate.

### REQ-004: Cumulation logic and combination matrix

The system SHALL apply the legal cumulation rules. A single asset can never claim both EIA and MIA (art. 3.42 lid 7 Wet IB 2001 — `samenloop verboden`). KIA, however, stacks freely with EIA *and* with MIA on a per-asset basis. Vamil is a depreciation method, not an aftrek, so it combines with MIA on the same asset (very common) and is the **only** scheme that touches the depreciation schedule rather than the taxable result directly.

| Scheme combination on **same asset** | Allowed? | Notes |
|---|---|---|
| KIA + EIA | Yes | Both applied; common for ≥ EUR 2.500 energie-investeringen |
| KIA + MIA | Yes | Both applied; common for ≥ EUR 2.500 milieu-investeringen |
| KIA + Vamil | Yes | Vamil affects timing of afschrijving, not aftrek |
| EIA + MIA | **No** | Art. 3.42 lid 7 — kies één; system MUST refuse the second claim |
| EIA + Vamil | **No** | Vamil only allowed on Milieulijst assets, not Energielijst |
| MIA + Vamil | Yes | Default combination for most Milieulijst codes that allow Vamil |
| KIA + EIA + Vamil | **No** | Vamil never with EIA |
| KIA + MIA + Vamil | Yes | The typical "triple stack" for green MKB-investeringen |

When EIA and MIA both technically apply (a rare asset on both lijsten), the system SHALL compute both and recommend the higher net-present-value option (taking Vamil into account on the MIA side).

### REQ-005: KIA tier calculation at boekjaar level

KIA is **not** per-asset but per-boekjaar aggregated. The system SHALL maintain a running `kiaJaartotaal` across all KIA-eligible assets in the boekjaar and recompute the KIA-aftrek using the 2026 tiered table (REQ-006 below) every time an asset is added, removed, or revalued. The tier-4 formula is non-trivial: `aftrek = 19.769 − 7,56% × (jaartotaal − 130.744)`. The system SHALL show the marginal effect ("Deze investering verhoogt uw KIA met EUR 1.250") in the asset detail view.

### REQ-006: KIA 2026 tier table

The system SHALL apply the 2026 KIA tiers (art. 3.41 Wet IB 2001, geïndexeerd):

- Investering ≤ EUR 2.800: geen KIA
- EUR 2.800 < investering ≤ EUR 70.602: **28% × investering**
- EUR 70.602 < investering ≤ EUR 130.744: vast bedrag **EUR 19.769**
- EUR 130.744 < investering ≤ EUR 392.230: **EUR 19.769 − 7,56% × (investering − EUR 130.744)**
- Investering > EUR 392.230: geen KIA

The 28% / 17% / 8% common-knowledge percentages refer to **effective marginal rates** at the top of each tier; the legal table uses the absolute formulas above. The system SHALL display both for boekhouder transparency.

### REQ-007: RVO digital aanvraag generation

For every EIA/MIA/Vamil claim the system SHALL generate the RVO eLoket aanvraag payload (JSON conforming to RVO's investeringsregelingen API contract) and provide a one-click submission. The statutory deadline is **3 maanden na opdrachtverlening** (the date of the binding order, NOT the invoice or delivery date). The system SHALL:

- Capture `opdrachtverleningDatum` as a mandatory field on every potential EIA/MIA/Vamil asset.
- Compute `rvoMeldingDeadline = opdrachtverleningDatum + 3 maanden` and surface this in a deadline-monitoring widget.
- Send a reminder email at deadline minus 14 days and minus 3 days if the melding is still `concept`.
- Block the melding from being marked `definitief` if the deadline has passed (the aftrek is then irrevocably forfeited; the system MUST NOT silently proceed).

### REQ-008: Jaaraangifte Vpb/IB preparation

At boekjaarafsluiting the system SHALL produce a single "Bijlage Investeringsaftrek" report grouping all claims by scheme, showing:

- Total KIA-aftrek (one number, after tier calculation).
- Total EIA-aftrek (sum of all EIA-claims × 40%).
- Total MIA-aftrek (sum of all MIA-claims × their respective 27/36/45%).
- Total Vamil-effect on afschrijving (informatief; flows via `bookkeeping-fixed-assets-depreciation`).
- Open RVO-beschikkingen still awaiting toekenning (with reservation of the aftrek pending RVO).

This report SHALL be exportable as PDF and as XBRL-fragments suitable for inclusion in the SBR Vpb-aangifte rendered by `bookkeeping-vpb-corporate-tax`.

### REQ-009: Vrijwillige verlaging tracking

An entrepreneur may **vrijwillig de aftrek verlagen** (art. 3.42a lid 4 / 3.42b lid 4) — i.e. claim less than the full statutory amount in the current year, typically to avoid loss-relief expiry or to preserve verliesverrekening across years. The system SHALL:

- Allow per-claim manual reduction with a mandatory rationale field.
- Refuse reduction below zero.
- Track the reduced amount separately from the legal entitlement so the contrast is auditable.
- Make clear that EIA/MIA reductions are **not carry-forwardable** — the foregone amount is lost.

### REQ-010: Desinvesteringsbijtelling on early disposal

If an asset on which KIA/EIA/MIA was claimed is **disposed of within 5 jaar na aanvang kalenderjaar van investering** (art. 3.47 Wet IB 2001), a desinvesteringsbijtelling MUST be added to the taxable result of the year of disposal. The bijtelling equals the original aftrek-percentage × the lower of (a) opbrengst bij vervreemding or (b) aanschafwaarde. The system SHALL:

- Maintain a 5-year disposal watch on every asset with an active claim.
- On disposal event from `bookkeeping-fixed-assets-depreciation`, automatically compute the bijtelling and post a draft journal entry against grootboekrekening `8120 Desinvesteringsbijtelling`.
- Notify the boekhouder with a clear before/after impact on the lopende Vpb-positie.
- For Vamil-assets, additionally trigger a **terugneming van de versnelde afschrijving** if disposal occurs before the gespreid deel is exhausted.

The bijtelling is capped on a per-scheme basis: er kan nooit meer worden teruggepakt dan oorspronkelijk is afgetrokken. Voorbeeld: EIA-aftrek 40% × EUR 50.000 = EUR 20.000 op het zonnepaneel-systeem in 2026. Verkoop in 2028 voor EUR 35.000. Bijtelling = 40% × min(EUR 35.000, EUR 50.000) = **EUR 14.000** terug in winst 2028. De terugneming staat dus los van de boekwinst op de vervreemding zelf (die loopt via de fixed-assets-spec). Bij vervreemding na ≥ 5 jaar: géén bijtelling, ook niet als verkoopprijs hoog is. De grens is hard op kalenderjaarbasis (aanvang kalenderjaar van investering — een asset gekocht in december 2026 valt tot 31 december 2030 onder de bijtelling-regel; het wordt 1 januari 2031 vrij).

### REQ-011: Ex-ante calculator voor aanschafbeslissingen

Het systeem SHALL een "wat-als"-modus bieden waarin de ondernemer een voorgenomen aanschaf kan invoeren (omschrijving, geschatte aanschafwaarde, vermoedelijke categorie) zonder dat er een asset wordt aangemaakt. De calculator zoekt automatisch de waarschijnlijke Energielijst-/Milieulijstcode(s) op via tekstmatch en toont een drietal scenario's: (a) "alleen reguliere afschrijving", (b) "met EIA óf MIA-claim", (c) "met MIA+Vamil triple stack indien van toepassing". Het resultaat moet de netto contante waarde van het belastingvoordeel over 5 jaar tonen, gegeven het IB- of Vpb-tarief van de huidige onderneming. Dit ondersteunt go/no-go beslissingen voordat de opdracht wordt verleend — cruciaal omdat de melding na opdrachtverlening niet meer ongedaan te maken is en de keuze EIA-of-MIA vaak strategisch is (verschillende percentages en verschillende toekomstige disposal-implicaties).

### REQ-012: Audit trail en RVO-correspondentie-archief

Elke claim, melding, beschikking, bezwaar en correctie SHALL onveranderlijk worden gelogd met tijdstempel, gebruiker, en — voor RVO-interactie — het volledige request/response payload. De RVO-beschikking (PDF download via eLoket) SHALL als bijlage aan de claim worden gehangen. Bij een bezwaar of beroepsprocedure moet het systeem op één scherm kunnen tonen: oorspronkelijke melding, RVO-beschikking, eventueel afwijzingsgrond, ingediend bezwaar, finale uitspraak, en de doorwerking in de aangifte. Deze audit trail is een controle-eis voor accountants onder NV COS 4410 (samenstellingsopdracht) en NV COS 4400N (overeengekomen specifieke werkzaamheden).

## Standards & Sources

- **Wet inkomstenbelasting 2001**, art. 3.40 t/m 3.45 — kerngeval investeringsaftrek; art. 3.42 (EIA), 3.42a (MIA), 3.36 (Vamil — willekeurige afschrijving milieubedrijfsmiddelen), 3.47 (desinvesteringsbijtelling).
- **Uitvoeringsregeling EIA 2001** — administratieve eisen, meldingstermijn 3 maanden, RVO als uitvoerder.
- **Uitvoeringsregeling MIA / Vamil 2001** — soortgelijke regels voor milieu-investeringen.
- **Energielijst 2026** — RVO publicatie, jaarlijks per 1 januari, ~170 codes ingedeeld in categorieën (energiebesparing in gebouwde omgeving, processen, transport, duurzame energie).
- **Milieulijst 2026** — RVO publicatie, jaarlijks per 1 januari, ~250 codes, ingedeeld naar 5 themacategorieën (klimaatadaptatie, circulaire economie, voedselvoorziening en landbouwproductie, mobiliteit, duurzame energie) en 3 MIA-percentageklassen (27/36/45%).
- **Besluit IB 2001** — nadere uitvoeringsregels investeringsaftrek.
- **Belastingdienst Handboek Ondernemen 2026**, hoofdstuk 6 — gezaghebbende toelichting voor MKB-praktijk.
- **RVO eLoket investeringsregelingen API** — technische koppeling voor digitale melding (REST + OAuth2, productie- en testomgeving).
- **Staatscourant** — jaarlijkse publicatie van geïndexeerde drempelbedragen voor KIA.

## Cross-app integration

- **bookkeeping-fixed-assets-depreciation** — bron van de InvestmentAsset (1-op-1 koppeling via `fixedAssetId`). De Vamil-claim retourneert een aangepast afschrijfschema dat dit andere spec consumeert. Disposal-events worden door fixed-assets gepubliceerd en door investeringsaftrek geconsumeerd voor de desinvesteringsbijtelling-bewaking.
- **bookkeeping-vpb-corporate-tax** — consumeert de "Bijlage Investeringsaftrek"-rapportage (REQ-008) als XBRL-fragmenten voor de SBR Vpb-aangifte. De totalen vullen rubrieken `Investeringsaftrek` en `Desinvesteringsbijtelling` op het aangifteformulier.
- **bookkeeping-r-d-subsidies-mkb** — complementair: WBSO en RDA gelden voor **uren en kosten** binnen S&O-projecten, terwijl EIA/MIA/Vamil voor **bedrijfsmiddelen** gelden. Een S&O-werkasset (zoals een prototype-productielijn) kan dus zowel onder WBSO (loonheffingen-korting) als onder MIA (winstaftrek) vallen — cumulation is toegestaan want het zijn verschillende grondslagen. Cross-app moet wel signaleren als hetzelfde activum in beide aanmeldingen zit.
- **bookkeeping-invoicing** — bron van leverancier-factuurdata; opdrachtverleningDatum komt vaak uit een bijbehorende inkooporder/offerte-acceptatie eerder in het proces.
- **bookkeeping-general-ledger** — desinvesteringsbijtelling-journaalpost (REQ-010) wordt gepost via de GL-spec; rekening 8120 moet in het seed-grootboekschema staan.

## Target users

- **Boekhouder MKB** — primaire gebruiker. Verantwoordelijk voor het tijdig melden bij RVO, het correct boeken in het grootboek, en het correct invullen van de aangifte. Heeft baat bij automatische detectie en de deadline-bewaking — het missen van de 3-maandstermijn is een van de meest voorkomende dure fouten in MKB-fiscaliteit.
- **Fiscalist** — gebruikt het systeem voor reviewen en optimaliseren. Wil de cumulation-matrix snel kunnen valideren, vrijwillige verlaging kunnen toepassen voor verliesverrekening-planning, en de bijlage Investeringsaftrek als startpunt voor zijn Vpb-werk.
- **Ondernemer** — beoordeelt aanschafbeslissingen ex ante: "als ik deze EUR 50k zonnepaneel-installatie koop, wat is mijn netto fiscale voordeel?" De tool moet een ex-ante calculator bieden die op basis van geschatte aanschafwaarde en geschatte Energielijst-/Milieulijstcode het totale aftrekvoordeel toont (KIA-tier-effect + EIA/MIA/Vamil).
- **Controlerend accountant** — gebruikt het systeem voor jaarrekeningcontrole: kan elke claim herleiden tot de onderliggende RVO-melding, beschikking, en grootboekpost. Moet de 5-jaars disposal-bewaking als compleet en betrouwbaar kunnen accepteren.

### Concreet voorbeeld (zonnepaneel-installatie EUR 50.000)

Een MKB-ondernemer (eenmanszaak, IB-ondernemer, jaartotaal investeringen EUR 65.000) plaatst op 15 maart 2026 een opdracht voor een zonnepaneel-installatie van EUR 50.000 op het bedrijfsdak. Factuur 22 april, ingebruikname 2 mei.

- **EIA**: Energielijstcode 251701 matcht (zonnepaneelsysteem > 15 kWp op kleinverbruikersaansluiting). Aanschafwaarde ≥ EUR 2.500 — voldoet aan drempel. EIA-aftrek = 40% × EUR 50.000 = **EUR 20.000**.
- **KIA**: investering valt in tier 2 (jaartotaal EUR 65.000 ≤ EUR 70.602), dus KIA-aftrek = 28% × EUR 65.000 = **EUR 18.200** (gerelateerd aan alle KIA-eligible assets, niet alleen deze ene).
- **MIA**: niet van toepassing — zonnepaneel staat op Energielijst, niet op Milieulijst. EIA + MIA cumulation verboden, geen issue want geen Milieulijstcode.
- **Vamil**: niet van toepassing — geen Milieulijstcode.

Totaal extra aftrek door deze ene investering: EUR 20.000 (EIA) + marginale KIA-effect EUR 14.000 × 28% = EUR 3.920 (KIA-bijdrage van deze EUR 50k binnen het jaartotaal van EUR 65k) = **EUR 23.920 lagere winst**. Bij IB-tarief 49,5% scheelt dat circa EUR 11.840 belasting in 2026, **bovenop** de normale afschrijving (die ook nog steeds over de volle EUR 50.000 plaatsvindt — KIA en EIA verlagen de boekwaarde NIET).

Hard kritisch: de RVO-melding moet uiterlijk **15 juni 2026** binnen zijn (3 maanden na opdrachtverlening 15 maart). De system trigger MOET het EUR 20.000 EIA-voordeel beschermen door automatisch de melding-flow te starten zodra de asset wordt aangemaakt met `opdrachtverleningDatum ≤ vandaag`.
