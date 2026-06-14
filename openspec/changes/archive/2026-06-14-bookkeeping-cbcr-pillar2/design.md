# Design — Country-by-Country Reporting (CbCR) & Pillar Two (GloBE)

## Context

CbCR (BEPS Action 13, Wet Vpb art. 29b–29h) verplicht sinds 1-1-2016 voor
multinationale groepen met geconsolideerde omzet ≥ EUR 750M. Jaarlijks
landenrapport per Belastingdienst met 7 kernvelden per jurisdictie (omzet
derden, omzet intragroep, winst, Vpb cash, Vpb accrual, aandelenkapitaal,
ingehouden winsten, FTE, materiële activa). Automatische uitwisseling via
MCAA naar alle jurisdicties waar groep aanwezig is.

Pillar Two (OESO GloBE Model Rules, EU Richtlijn 2022/2523, Wet minimumbelasting
2024) werkt via drie mechanismen: (1) **Income Inclusion Rule (IIR)** — moeder
heft top-up tax op laagbelaste dochters, (2) **Undertaxed Profits Rule (UTPR)**
— vangnetregel vanaf 2024, (3) **Qualified Domestic Minimum Top-up Tax (QDMTT)**
— NL kan zelf verschil tot 15% bij NL-resident entiteiten ophalen.

Per-jurisdictie berekening: **GloBE income** (commercieel + 35 OESO-correcties),
**adjusted covered taxes** (Vpb + soortgelijke heffingen), **ETR** (taxes / GloBE),
**top-up tax** = (15% − ETR) × (GloBE − SBIE) met SBIE carve-out (5% payroll +
5% tangible assets, afgebouwd).

Per ADR-031, het gehele GloBE-calculatiemodel is declaratief: schema metadata +
aggregatiequery's die per-jurisdictie summaries + Pillar 2 computaties emitteren.
Per ADR-022, actuarial inputs van Big-4 bureaus worden via docudesk gearchiveerd,
niet in app-local storage.

De verandering is **spec-only**. Implementatie volgt later via `opsx-apply` en
standard Hydra pipeline.

## Goals

- Expreseer de gehele CbCR & Pillar 2 oppervlak als **declaratieve metadata** —
  schemas + lifecycle + aggregatie formules — per ADR-031.
- Maak de spec een **Head of Tax-readable contract** — OESO CbCR + GloBE
  jaarlijkse cycle herkenbaar end-to-end (entiteit registratie, per-jurisdictie
  aggregatie, GloBE-correcties, ETR, top-up toewijzing, XML export).
- Enforce EUR 750M drempel detectie + CbCR 7-velden aggregatie + GloBE 35-item
  correctie applicatie + ETR berekening + QDMTT prioriteit zonder PHP service logic.
- Ondersteun both OESO-schema XML export (CbCR) en GloBE Information Return
  XML/XBRL voor indiening Belastingdienst / andere jurisdicties.
- Hou de per-jurisdictie summatie + GloBE-correctie + ETR berekening declaratief
  zodat alles auditable is.

## Non-Goals

- Geen PHP GloBE calculatieservice (GlobeIncomeCalculator.php).
- Geen real-time transfer pricing optimizer.
- Geen DNB/AFM rapportageverplichting enforcement.
- Geen governance workflows (audit committee approval) — owned by decidesk (T4).

## Decisions

### D1 — Acht registers: entiteiten + CbCR summaries + Pillar 2 computaties + safe harbour + returns

CbCR / Pillar 2 workflow is ontleed in:
- **group-entity-registry**: groepsentiteiten (naam, rechtsvorm, jurisdictie,
  consolidatie%, consolidatiemethode, LEI, VAT, CbCR/Pillar2 scope flags)
- **cbcr-jurisdiction-summary**: per-jurisdictie 7 CbCR velden (omzet derden,
  omzet intragroep, totaal, winst, Vpb cash, Vpb accrual, kapitaal, winsten,
  FTE, MVA netto, hoofdactiviteiten)
- **pillar2-jurisdiction-computation**: per-jurisdictie ETR + top-up (GloBE income,
  adjusted covered taxes, SBIE carve-out, ETR, top-up tax rate, top-up amount,
  QDMTT/IIR/UTPR verdeling)
- **pillar2-safe-harbour**: per-jurisdictie safe harbour test resultaat
  (de minimis, simplified ETR, routine profits; pass/fail + ondersteuning)
- **qdmtt-return**: NL QDMTT-aangifte (entiteit, periode, GloBE income, ETR,
  QDMTT payable, indienstatus)
- **globe-information-return**: GIR XML/XBRL (UPE, alle per-jurisdictie
  computaties, top-up verdeling, indienstatus)
- **cbcr-return**: CbCR XML (reportingentiteit, jurisdictie summaries, constituent
  entities, XML submission, Belastingdienst ref)
- **tax-treaty-overview**: DTA referentie (withholding rates per DTA paar;
  support voor adjusted covered taxes correcties)

**Alternatief beschouwd**: Monolithische per-jurisdictie-computation met alles
embedded. Verworpen — separate CbCR + Pillar 2 + safe harbour records nodig voor
audit trail, per-jurisdictie drill-down, staging.

### D2 — EUR 750M drempel detectie automatisch

Per FY wordt op basis voorgaand boekjaar consolidatieomzet automatisch getest:
≥ EUR 750M → CbCR + Pillar 2 applicable. Onderdrempel → geen verplichting,
records optional. Schema constraint op `groupRevenue` field.

**Alternatief beschouwd**: Manual flag per groep. Verworpen — wettelijke
drempel is objectief; automatische detectie voorkomt compliance-fout.

### D3 — CbCR aggregatie: 7 velden per jurisdictie uit group-entity-registry

Alle `group-entity-registry` records met dezelfde `jurisdiction` worden in één
`cbcr-jurisdiction-summary` opgeteld (omzet, winst, Vpb, kapitaal, winsten,
FTE, MVA). Intra-jurisdictie eliminations vallen weg; cross-jurisdictie
intragroep transacties blijven zichtbaar als `relatedPartyRevenue`.

**Alternatief beschouwd**: Handmatige entry per jurisdictie. Verworpen —
aggregatie uit consolidatiedata vermijdt transcription-fout.

### D4 — GloBE income: commercieel winst + 35 OESO-correcties

GloBE income = commerciële winst + 35 voorgeschreven adjustments per OESO
Model Rules. Inclusief: exclusion dividend, exclusion stock-based comp,
terugneming goodwill impairment, depreciation leasing, DTA effect, etc.
Schema fields: `globeIncome` (computed) + `globeIncomeAdjustments` array
met per correctie: type, amount, description.

**Alternatief beschouwd**: Dubbele GloBE-correctie service in PHP. Verworpen
— 35-item checklist is declaratief; metadata enforcement volstaat.

### D5 — ETR berekening: adjusted covered taxes / GloBE income

ETR per jurisdictie = adjusted covered taxes / GloBE income (min 0%). Adjusted
covered taxes = Vpb current + Vpb deferred + soortgelijke heffingen met
correcties (basis-erosion adjustments, DTA, etc.). Schema: `adjustedCoveredTaxes`
+ `coveredTaxAdjustments` array.

**Alternatief beschouwd**: Blinde aanname op 15%. Verworpen — ETR moet exact
berekend worden per OESO guidance.

### D6 — SBIE carve-out: 5% payroll + 5% tangible assets, afgebouwd 2023–2033

SBIE = (payroll × carve-out%) + (tangible assets NBV × carve-out%). Percentages
dalen: 2023: 10%/8%, 2024: 9.6%/7.6%, ..., 2033: 5%/5%. Schema fields:
`payrollCarveOut`, `tangibleAssetCarveOut`, `substanceBasedIncomeExclusion`
(computed). Top-up tax = rate × max(0, GloBE − SBIE).

**Alternatief beschouwd**: Vaste carve-out %. Verworpen — overgangsregime
is wettelijk; schema moet planning steunen.

### D7 — Safe harbour tests: transitional CbCR (2024–2026)

Drie testen; pass op één = geen volledige Pillar 2 calculation:
1. **De minimis**: omzet < EUR 10M AND winst < EUR 1M
2. **Simplified ETR**: ETR ≥ (15% in 2024, 16% in 2025, 17% in 2026)
3. **Routine profits**: winst ≤ SBIE

Schema fields per `pillar2-safe-harbour`: `period`, `jurisdiction`, `testApplied`,
`testResult` (pass/fail), `dataSource` (qualified-cbcr / financial-statements),
`supportingCalculations` JSON.

**Alternatief beschouwd**: Alle jurisdicties via volledige Pillar 2. Verworpen —
safe harbour simplificatie is OESO-intended; spec moet compliance-friendly zijn.

### D8 — QDMTT prioriteit boven IIR

NL-resident entiteit met ETR < 15% → QDMTT-heffing in NL eerst, daarna
top-up tax credit op buitenlandse moeder IIR-claim. Schema enforcement op
`jurisdiction=NL` + prioriteit-vlag in lifecycle.

**Alternatief beschouwd**: Global top-up verdeling zonder QDMTT prioriteit.
Verworpen — Nederlands belastingrecht voorschrijft QDMTT priority.

### D9 — GIR + QDMTT XML export conform OESO schema

GIR (GloBE Information Return) als XML per OESO GIR schema met: groep-info,
alle per-jurisdictie computaties, top-up tax toewijzing (IIR/UTPR/QDMTT credit).
QDMTT-return als Nederlandse aangifte XML. Beide inzetbaar voor SBR/Digipoort
submission.

**Alternatief beschouwd**: Generieke JSON export. Verworpen — indiening
vereist OESO schema; hardwarning op formaat is nodig.

### D10 — Reconciliatie CbCR ↔ groepsjaarrekening

CbCR-totaal (omzet, winst) moet aansluiten op geconsolideerde jaarrekening.
Verschilrapport toont eliminations (JV pro-rata, consolidation adjustments),
IFRS-USGAAP verschillen, etc. Residueel > EUR 1M gemerkt ongereconcilieerd.
Verplicht sign-off controller voordat CbCR indiening.

**Alternatief beschouwd**: Geen reconciliatie. Verworpen — Belastingdienst
eist aansluiting; audit trail nodig.

## Reuse Analysis

| Capability | Bestaande bron | Reuse strategie |
|---|---|---|
| Per-jurisdictie aggregatie | OR `x-openregister-aggregations` | Query over `group-entity-registry` per jurisdictie; emit `cbcr-jurisdiction-summary` |
| GloBE-correctie applicatie | OR `x-openregister-calculations` + schema validator | Metadata per correctietype op `actuarial-valuation` + enforcer; 35 correcties als conditional fields |
| ETR berekening | OR `x-openregister-calculations` | Formula: adjusted covered taxes / GloBE income |
| SBIE carve-out | OR `x-openregister-calculations` | Afbouwing tabel per jaar; formula per planning-periode |
| Safe harbour test logic | OR `x-openregister-calculations` (if/then rules) | Drie parallelle tests; schema validator per regel |
| QDMTT prioriteit | T2 `bookkeeping-vpb-mkb` (NL Vpb aangifte) | Linking via entity FK; lifecycle gate op priority |
| XML export | T2 `bookkeeping-document-attachment-integration` (via docudesk) | OESO schema template; data-merge emit XML file |
| Reconciliatie report | T3 `bookkeeping-financial-statements` (jaarrekening data) | Query over CbCR + consolidated P&L; diff report |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic op alle schema writes + lifecycle transitions |

**Netto nieuw in implementatie**: 8 schema declarations + 4 lifecycle blocks +
3 aggregation queries (jurisdictie summaries, GloBE-correctie, safe harbour
evaluatie) + 2 XML template + 5 manifest entries + 0 PHP service. Geen
calculatieservice; alles declaratief.

## Declaratief vs. imperatief (per ADR-031)

| Gedrag | Besluit | Reden |
|---|---|---|
| EUR 750M drempel detectie | Declaratief (schema validator op `groupRevenue` field) | Scalar comparison |
| Per-jurisdictie CbCR aggregatie | Declaratief (x-openregister-aggregations query) | Pure data join + sum |
| GloBE-correctie applicatie | Declaratief (schema fields + conditional enforcement) | Metadata checklist |
| ETR berekening | Declaratief (formula field) | Scalar arithmetic |
| SBIE carve-out | Declaratief (afbouwing tabel + formula) | Lookup + arithmetic |
| Safe harbour tests | Declaratief (three if/then rules) | Conditional logic |
| QDMTT prioriteit enforcement | Declaratief (lifecycle gate) | State-machine transition |
| XML export | Declaratief (OESO schema template + data-merge) | Template rendering |

Geen calculatieservice in deze envelope.

## Seed Data

Vier seed records ter illustratie:

1. **group-entity-registry** — "NL Moedermaatschappij"
   - legalName: "Shillinq Group Holding BV"
   - jurisdiction: NL
   - consolidationPercentage: 100
   - consolidationMethod: full
   - mainBusinessActivity: holding
   - cbcrIncluded: true
   - pillar2Included: true

2. **group-entity-registry** — "Duitse dochter"
   - legalName: "Shillinq GmbH"
   - jurisdiction: DE
   - consolidationPercentage: 100
   - consolidationMethod: full
   - mainBusinessActivity: manufacturing
   - cbcrIncluded: true
   - pillar2Included: true

3. **cbcr-jurisdiction-summary** — "CbCR voor DE 2026"
   - period: 2026
   - jurisdiction: DE
   - unrelatedPartyRevenue: 173000000
   - relatedPartyRevenue: 42000000
   - totalRevenue: 215000000
   - profitBeforeTax: 24500000
   - incomeTaxPaidCash: 6370000
   - incomeTaxAccrued: 6500000
   - statedCapital: 5000000
   - accumulatedEarnings: 18750000
   - numberOfEmployees: 85
   - tangibleAssetsOtherThanCash: 45000000

4. **pillar2-jurisdiction-computation** — "Pillar 2 voor DE 2026"
   - period: 2026
   - jurisdiction: DE
   - globeIncome: 24500000
   - adjustedCoveredTaxes: 5880000
   - etrJurisdiction: 0.24 (24%)
   - minimumRate: 0.15
   - topUpTaxRate: 0.0 (ETR > 15%)
   - topUpTaxAmount: 0
   - safeHarbourApplied: false

Operators passen aan per groep bij eerste use.

## Risico's & Trade-offs

| Risico | Mitigatie |
|---|---|
| Consolidatiedata van schakels incomplete; CbCR-totaal stemt niet af | Reconciliatierapport verplicht; residueel > EUR 1M gemarkeerd; controller attestatie |
| Payroll & tangible assets per jurisdictie ontbreken (HRMQ/Fixed Assets lag) | Fallback op prior-year; waarschuwing controller; manual override mogelijk |
| GloBE-correcties divergeert van Big-4 interpretatie | Gedetailleerde audit trail; sensitivity analyse; connector API (T4) |
| QDMTT vs IIR coördinatie complex; UPE-jurisdictie kan conflicteren | Separaat QDMTT-return; automatic credit in GIR; audit trail op toewijzing |
| Safe harbour overgangspercentages (2024–2026) verwarring | Schema enforcement per planningperiode; deadline-waarschuwing 2026-einde |
| XML export niet conform OESO schema; indiening afgewezen | Strict schema validation voordat export; test tegen OESO validator (third-party tool) |

## Migratie Plan

Geen legacy data migratie. CbCR / Pillar 2 zijn nieuwe module; bestaande
klanten zonder multinationale scope niet beïnvloed. Multinationals kunnen
opt-in en seed data per groep importeren.

## Compliance & Standaarden

Spec implementeert:
- **OESO BEPS Action 13** — CbCR architecture
- **OESO Global Anti-Base Erosion (GloBE) Model Rules** (december 2021)
- **OESO GloBE Commentary** (maart 2022)
- **OESO Administrative Guidance** (februari 2023, juli 2023, december 2023+)
- **EU Richtlijn 2022/2523** (Pillar 2 implementatie)
- **Wet Vpb 1969 artikel 29b–29h** (NL CbCR)
- **Wet minimumbelasting 2024** (NL Pillar 2 + QDMTT)
- **MCAA** (Multilateral Competent Authority Agreement)
- **OESO CbC XML schema v2.0**
- **OESO GloBE Information Return XML schema**

## Documentatie & Audit Trail

Alle per-jurisdictie aggregaties, GloBE-correcties, ETR berekeningen,
QDMTT/IIR-toewijzingen zijn opgenomen met entry datum, entered-by, approval status.
Externe accountant kan audit trail volledig reviewen zonder Big-4 email-verspreiding.
