# Design — Vpb-aangifte Vennootschapsbelasting voor BV/NV (Regulier)

## Context

Vennootschapsbelasting (corporate income tax) in the Netherlands is mandatory for all
BV/NV entities. The annual aangifte (tax return) must be filed within 5 months of
fiscal year-end via Digipoort in SBR-XBRL format per the Nederlandse Taxonomie (NT).
The process involves:

1. Measuring commercial profit from the jaarrekening (vastgesteld by AvA)
2. Applying fiscal corrections (commercial → fiscal winst per Wet Vpb Articles 3, 8)
3. Claiming applicable facilities (innovatiebox, deelnemingsvrijstelling, investeringsaftrek)
4. Calculating tax due using schijftarieven (19% / 25.8% for 2026)
5. Submitting the XBRL instance via Digipoort with eHerkenning EH3 signature
6. Receiving definitieve aanslag from inspecteur
7. If disputed, following bezwaar → hoger beroep → cassatie workflow with
   statutory termijnen

Per ADR-031, the entire measurement model is declarative: schema metadata + validation
rules + aggregation queries. Per ADR-022, actuarial/actueel reports are archived via
docudesk, not in app-local storage.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the
standard Hydra pipeline.

## Goals

- Express the entire Vpb-aangifte workflow as **declarative metadata** — schemas +
  state machines + validation rules — per ADR-031.
- Make the spec a **competent-fiscalist readable contract** — Dutch Wet Vpb annual
  cycle recognisable end-to-end (belastingplichtige registration, commercial↔fiscal
  reconciliation, facility claims, tariff application, SBR submission, aanslag,
  bezwaar).
- Enforce one-aangifte-per-jaar constraint, jaarrekening-binding, schijftarief
  parameterization, facility-eligibility tests, voorvoegingsverlies-expiration tracking,
  bezwaar-termijn monitoring without PHP tax-calculation service.
- Support both underfunded and prefunded loss-carryforward scenarios (voorvoegingsverlies
  per-year regime changes 2019 & 2022).
- Keep fiscal-correction rules + facility claims + tariff application + bezwaar-
  termijn calculation declarative so they are auditable + versionable by belastingjaar.

## Non-Goals

- No PHP tax-calculation service (TaxCalculator.php, VpbCalc.php).
- No real-time inspecteur-aanslag data-feed (Digipoort push notification handling) —
  T4 via Logius connector.
- No transfer-pricing orchestration (Article 8b Wet Vpb documentation + filing) —
  T4 scope.
- No thin-capitalization / interest-deduction-limitation rules (Article 3 IDB) — T4.
- No decidesk governance workflows for bezwaar/beroep decision approval — T4.
- No real-time currency-revaluation for foreign-branch profit allocation — T4.

## Decisions

### D1 — 13 registers: belastingplichtige header + aangifte + corrections + facilities + loss tracking + assessments + disputes

Vpb accounting is decomposed into:
- **Belastingplichtige**: entity metadata (KvK, RSIN, rechtsvorm, boekjaar, eHerkenning cert ref)
- **VpbAangifte**: annual tax return (concept → ingediend → aanslag → bezwaar → onherroepelijk)
- **FiscaleCorrectie**: line-by-line fiscal adjustment (NTP-classified, with commercial↔fiscal mapping)
- **Innovatiebox**: R&D facility claim (forfaitair or werkelijke-winst, S&O-verklaring, nexus-factor)
- **Deelneming**: shareholding detail (>=5% test, deelnemingsvrijstelling eligibility)
- **FiscaleEenheid**: consolidated tax group (voegingen, ontvoegingen, per-dochter loss tracking)
- **Voorvoegingsverlies**: loss-carryforward (verliesjaar, oorspronkelijkBedrag, reedsVerrekend, verjaartIn per regime)
- **InvesteringsAftrek**: investment credit claim (KIA/EIA/MIA/Vamil, cumulation validation)
- **VoorlopigeAanslag**: provisional assessment (inspecteur-opgelegd, herzieningsverzoek workflow)
- **DefinitieveAanslag**: final assessment (vastgesteld, bezwaartermijn-tracking)
- **BezwaarBeroep**: dispute record (bezwaar/beroep/hoger-beroep/cassatie, termijnen, uitspraak)

**Alternative considered**: Monolithic VpbAangifte register with all fields embedded.
Rejected — multi-facility claims + complex voorvoegingsverlies + loss-carryforward
regime tracking + multi-year bezwaar/beroep require first-class records for audit
trail + drill-down + lifecycle automation.

### D2 — Fiscal corrections: NTP-classified, mapped to GL, tracked per Article

FiscaleCorrectie records are encoded with:
- `code`: NTP-element (e.g., `afschrijvingsbeperking-art-3-30a`, `niet-aftrekbare-kosten-art-3-14`)
- `commercieelBedrag`: commercial value (from GL)
- `fiscaalBedrag`: fiscal value (after Article XX adjustment)
- `correctieBedrag`: delta (fiscaalBedrag - commercieelBedrag)
- `toelichting`: motivation per Wet Vpb / RJ documentation standard

This enforces traceability: each fiscal adjustment is pinned to a GL line + a Wet Vpb
Article + a motivation. Audit trail preserves entry timestamp + who entered it.

**Alternative considered**: Aggregate adjustment categories (e.g., "depreciation",
"non-deductible"), then apply blanket percentages. Rejected — Belastingdienst
navordering requires granular Article-level justification; pre-aggregated categories
hide required detail.

### D3 — One-aangifte-per-jaar: blocking constraint enforced at schema level

VpbAangifte schema forbids duplicate `(belastingplichtige, belastingjaar)` pairs unless
prior aangifte is `onherroepelijk` or formally heropened per Article 53 Wet Vpb
(5-year reopening window). This prevents accidental duplicate filings.

**Alternative considered**: Allow multiple drafts per year, block submission only.
Rejected — Dutch tax law treats "one-aangifte-per-jaar" as a wettelijke verplichting;
drafts count; creating multiple drafts in error increases reconciliation burden.

### D4 — Jaarrekening binding: commerciële winst FK to specific vastgestelde version

VpbAangifte.commerciëleWinst is a foreign key to a specific, vastgestelde
JaarrekeningRecord (not just the year). The system prevents transition to
`ingediend` state unless the linked jaarrekening is AvA-approved. This ensures
the filing uses the definitive (not provisional) profit figure.

**Alternative considered**: Auto-link to latest jaarrekening version at submission
time. Rejected — entity may update jaarrekening post-approval (correction); auto-link
risks silent profit divergence. Explicit FK forces intentional decision.

### D5 — Schijftarieven parameterized per belastingjaar (VpbTariefcatalogus)

Annual schijftarieven (2026: 19% on €0–245k, 25.8% on excess), facility percentages
(innovatiebox 9%, etc.), and drempelbedragen (investeringsaftrek minima) are stored in
a VpbTariefcatalogus table keyed by belastingjaar. This allows:
- Retroactive recomputation of prior-year aangifte (navordering scenario)
- Automatic tariff update when Belastingplan changes (September each year)
- Auditability of tariff application per belastingjaar

**Alternative considered**: Hard-code tariffs in schema / UI. Rejected — tax rules
change annually; hard-coding creates maintenance burden + regression risk when
recomputing old years.

### D6 — Innovatiebox: two methods (forfaitair + werkelijke-winst) with S&O-verklaring binding

The Innovatiebox schema supports:
- **Forfaitair**: max €25k voordeel per jaar, limited to first 3 years after
  S&O-verklaring issue date
- **Werkelijke-winst**: uncapped, subject to nexus-factor (R&D-eigen / R&D-totaal,
  capped at 100%)

Both methods require `soVerklaringReferentie` (RVO-issued S&O-certificate reference);
claim rejected at submission if missing.

**Alternative considered**: Separate registers for forfaitair vs werkelijke-winst.
Rejected — both are variants of the same facility; unified schema with discriminator
(methodType enum) simplifies operator workflow.

### D7 — Deelnemingsvrijstelling: three cumulative tests with audit trail

Deelneming.deelnemingsvrijstellingVanToepassing is conditional on three cumulative
tests per Article 13 Wet Vpb:
1. **Oogmerktoets** (substantive business purpose — not mere tax avoidance)
2. **Onderworpenheidstoets** (subsidiary subject to normal tax rate in home country)
3. **Bezittingentoets** (subsidiary holds substantive business assets)

The schema stores motivation/evidence for each test. The system flags potential
low-tax-portfolio-investment deelnemingen but defers final judgment to fiscalist
(jurisprudence too casuïstisch for automation).

**Alternative considered**: Auto-reject low-tax shareholdings. Rejected — Dutch case
law is fact-intensive (Argenta, Bricolage, Saladin); disqualification requires
expert judgment, not algorithmic classification.

### D8 — Voorvoegingsverlies per-year regime: 9yr (pre-2019) / 6yr (2019–2021) / unlimited-50% (post-2022)

The Voorvoegingsverlies table stores:
- `verliesjaar`: year in which loss occurred
- `oorspronkelijkBedrag`: loss amount recorded
- `reedsVerrekend`: cumulative amount used in prior years
- `restant`: unused balance
- `verjaartIn`: computed expiration date per regime (verjart = expires)

Expiration date is computed as:
- Pre-2019 losses: verliesjaar + 9 years
- 2019–2021 losses: verliesjaar + 6 years
- Post-2022 losses: unlimited carry-forward, but subject to 50%-limitation on
  winsten exceeding €1M per belastingjaar (per Wet 2021-07 amendment)

UI shows expiration warning 12 months before verjaring.

**Alternative considered**: Automatic expiration (delete record on verjaring date).
Rejected — audit trail requires preserving expired losses for navordering scenarios;
soft expiration (flag + UI warning) enables later retrieval if navordering reaches back.

### D9 — Fiscale eenheid: per-dochter voorvoegingsverlies tracking on voeging/ontvoeging

FiscaleEenheid.voegingen record each voeging event with:
- Voeging requirements enforced: >=95% bezit, gelijke boekjaren, NL vestiging
- Per-dochter voorvoegingsverlies records linked to voeging
- Ontvoeging allows loss carry-out per Article 15ai conditions

This prevents accidental loss of dormant voorvoegingsverliezen when dochter is
unvoiced.

**Alternative considered**: Consolidated loss pool (all losses merged on voeging).
Rejected — Dutch tax law requires per-dochter loss tracking (restriction on which
dochter's profits a loss can offset); pool approach loses required granularity.

### D10 — Bezwaar/beroep: state machine with statutory termijnen and escalation alerts

BezwaarBeroep uses a finite-state machine:
- `bezwaar` (filed within 6 weeks of aanslag)
- `uitspraak-inspecteur-pending` (inspecteur has up to 6 weeks, extendable to 12)
- `uitspraak-inspecteur-ontvangen` (inspector ruled)
- `beroep` (filed within 6 weeks of uitspraak, if dissatisfied)
- `hoger-beroep` (filed within 6 weeks of first beroep outcome, if dissatisfied)
- `cassatie` (Hoge Raad, if appealing point-of-law)
- `afgewezen` or `gegrond` (final ruling)

Termijnen are tracked; system generates calendar events at T-7d, T-3d, and on-day
for missed deadlines (if termijn passes, record flags red).

**Alternative considered**: Manual termijn tracking (spreadsheet). Rejected — missed
bezwaar termijn makes aanslag onherroepelijk + causes direct financial loss; system-
enforced tracking prevents this.

### D11 — SBR-XBRL: NT-taxonomie compliance + Digipoort signing + eHerkenning EH3

VpbAangifte submission triggers SBR-XBRL-instance generation (delegated to
bookkeeping-sbr-xbrl-reporting) and Digipoort signing with:
- `Belastingplichtige.digipoortCertificaat` (FK to credential vault; must be
  eHerkenning EH3 or higher)
- `Belastingplichtige.eHerkenningsNiveau` (validated EH3+)
- Instance is validated against NT-taxonomie XSD before transmission
- Digipoort receipt (receipt ID) is persisted in VpbAangifte.digipoortReceiptId

**Alternative considered**: Allow EH2 signatures (lower assurance). Rejected —
Belastingdienst mandates EH3 for corporate tax submission per Logius directive.

### D12 — VoorlopigeAanslag workflow: inspecteur-opgelegd + herzieningsverzoek support

VoorlopigeAanslag records provisional assessments issued by inspecteur (on estimated
belastbaar bedrag). Entity can file herzieningsverzoek (form "Verzoek wijziging
voorlopige aanslag Vpb") to request downward adjustment. Workflow supports:
- Entry of provisional assessment detail
- Filing herzieningsverzoek with justification
- Tracking inspecteur response (accepted / denied / partial)
- Cascading impact to cashflow forecast per bookkeeping-cashflow-forecast

**Alternative considered**: Ignore voorlopige aanslag (final aanslag is the real liability).
Rejected — voorlopige aanslag drives cash-flow planning; MKB must budget provisional
payments until final aanslag received.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Fiscal correction tracking | OR `x-openregister-lifecycle` (schema + audit trail) | FiscaleCorrectie records per aangifte; audit trail automatic on all writes |
| Schijftarief + facility % application | OR `x-openregister-calculations` (parameterized formulas) | Tariff lookup from VpbTariefcatalogus per belastingjaar; formula queries on Vpb-aangifte → tax-due |
| Voorvoegingsverlies expiration | OR `x-openregister-calculations` (date arithmetic) | Verjaaring formula per regime (9yr / 6yr / unlimited-50%); UI warning 12mo before verjaring |
| Facility eligibility (innovatiebox S&O, deelnemingsvrijstelling tests) | Schema validators + aggregation queries | S&O-reference mandatory FK on Innovatiebox; three-test motivation on Deelneming; queries enforce cumulation rules on InvesteringsAftrek |
| Bezwaar/beroep state machine | OR `x-openregister-lifecycle` (state transitions + termijn tracking) | BezwaarBeroep lifecycle with termijn calendar events; escalation alerts at T-7d, T-3d |
| Commercial↔fiscal reconciliation | T2 `bookkeeping-general-ledger` + `bookkeeping-financial-statements` | FiscaleCorrectie.commercieelBedrag mapped to GL account; commerciële winst from jaarrekening |
| SBR-XBRL generation + Digipoort signing | T3 `bookkeeping-sbr-xbrl-reporting` | Delegated; Vpb-aangifte triggers SBR instance generation + eHerkenning EH3 signature |
| Jaarrekening binding | T2 `bookkeeping-financial-statements` | FK to vastgestelde JaarrekeningRecord version; prevents submission if not AvA-approved |
| Tax-calendar integration | T2 `bookkeeping-tax-calendar` | Optional; Vpb-aangifte/aanslag/bezwaar dates published to tax calendar |
| Cashflow impact | T2 `bookkeeping-cashflow-forecast` | VpbAangifte.verschuldigdeVpb + VoorlopigeAanslag.voorlopigVerschuldigd feed forecast |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all schema writes + lifecycle transitions |
| OpenConnector events | Event bus | `vpb.aangifte.concept`, `vpb.aangifte.ingediend`, `vpb.aanslag.ontvangen`, `vpb.bezwaar.ingediend`, `vpb.termijn.verstrijkt-binnenkort` published |

**Net new code in implementation cycle**: 11 schema declarations + 4 lifecycle blocks
(VpbAangifte, DefinitieveAanslag, BezwaarBeroep, Innovatiebox) + 3 parameterization tables
(VpbTariefcatalogus, FacilityEligibility, BezwaarTermijnCatalogus) + 2 aggregation queries
(schijftarief application, voorvoegingsverlies expiration tracking) + 5 manifest entries + 0 PHP
tax-calculation service (all declarative).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| One-aangifte-per-jaar constraint | Declarative (`unique(belastingplichtige, belastingjaar)` schema validator) | Scalar uniqueness rule, no service logic |
| Jaarrekening binding | Declarative (FK + state-transition guard: prevent `ingediend` if jaarrekening not vastgesteld) | Data constraint + lifecycle guard |
| FiscaleCorrectie commercial↔fiscal mapping | Declarative (schema fields: commercieelBedrag, fiscaalBedrag, correctieBedrag) | Operator enters from GL mapping; computation is pure arithmetic |
| Schijftarief application | Declarative (`x-openregister-calculations` formula query; tariff rates from VpbTariefcatalogus) | Tax-rate lookup + bracket arithmetic |
| Voorvoegingsverlies expiration | Declarative (formula per regime: verliesjaar + 9/6/∞ − 50%; verjaartIn computed) | Date arithmetic + regime lookup |
| Facility eligibility (S&O, deelnemingsvrijstelling tests, KIA/EIA/MIA cumulation) | Declarative (schema validators + aggregation queries) | Mandatory fields + enum checks + combinatorial rules |
| Bezwaar/beroep state machine | Declarative (`x-openregister-lifecycle` states + termijn calculations) | Finite states + date arithmetic |
| Belastingdienst aanslag routing | Declarative (webhook listener mapping DefinitieveAanslag receipt to VpbAangifte state transition) — T4 | Event-driven state transition, no business logic |
| VoorlopigeAanslag herzieningsverzoek | Declarative (state machine + approval gates via ApprovalRequest) | Workflow automation, no financial calculation |

No tax-calculation service class authored in this envelope (per ADR-031 anti-pattern).
All fiscal logic is schema metadata + validation + aggregation queries.

## Seed Data

Three seed records:

1. **Belastingplichtige**: "ACME BV"
   - kvkNummer: "12345678"
   - rsin: "001234567"
   - rechtsvorm: "BV"
   - boekjaarStart: "2026-01-01"
   - boekjaarEind: "2026-12-31"

2. **VpbTariefcatalogus**: "2026"
   - belastingjaar: 2026
   - tarief1: 0.19 (€0–€245k)
   - tarief2: 0.258 (>€245k)
   - innovatieboxTarief: 0.09
   - faciliteitPercent: {deelnemingsvrijstelling: "25%", ...}

3. **Voorvoegingsverlies** (template):
   - verliesjaar: 2023
   - oorspronkelijkBedrag: 50000
   - reedsVerrekend: 10000
   - restant: 40000
   - verjaartIn: "2029-12-31" (6-year regime for 2023)

Operators customise per entity on first use.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Fiscal corrections entered manually; correctness depends on fiscalist expertise | Spec-level audit trail + NTP-code validation against NT-taxonomie; external accountant review in jaarrekening cycle |
| Schijftarieven + facility rules change annually; Shillinq tariff table must stay current | VpbTariefcatalogus maintenance integrated into annual Belastingplan cycle (post-September); tariff misalignment triggers audit flag |
| Voorvoegingsverlies verjaaring rule changed 2022 (9yr → 6yr → unlimited-50%); transition entities must apply dual regime | Formula per-verliesjaar regime lookup (not year-of-use); UI warning on hybrid year-end scenarios |
| Deelnemingsvrijstelling low-tax-portfolio-investment test is jurisprudence-casuïstisch; system cannot fully automate | System flags potential low-tax structures; fiscalist provides motivated override; audit trail preserves decision rationale |
| Bezwaar/beroep termijnen are hard statutory deadlines; missing termijn = aanslag onherroepelijk + direct loss | Termijn-bewaking calendar events + escalation alerts; red-flag display if termijn passed |
| SBR-XBRL instance rejected by Digipoort if non-compliant with NT-taxonomie | Instance validated against NT-taxonomie XSD pre-submission; Digipoort receipt confirms successful transmission post-submission |
| eHerkenning EH3 certificate required; MKB may lack in-house PKIO cert | Shillinq supports Servicegereerde Architectuur (SGA) intermediary certs (fiscalist signs for entity) |
| Fiscal-eenheid ontvoeging accidentally destroys per-dochter voorvoegingsverliezen | Pre-ontvoeging validation warns on loss impact; per-dochter loss tracking prevents loss of restricted carryforwards |

## Migration Plan

No legacy data migration required. Vpb-aangifte accounting is introduced as a new
module; existing customers on Shillinq without corporate tax obligations are not affected.
Customers with existing Vpb filings (on external tax software or via adviseur) can
opt-in and import belastingplichtige + prior-year aangifte detail per entity.

## Compliance & Standards

Spec implements:
- **Wet op de vennootschapsbelasting 1969** (Wet Vpb), Stb. 1969/445, with
  amendments through Belastingplan 2026 (Stb. 2025/512)
- **Uitvoeringsregeling vennootschapsbelasting 1971**
- **Algemene wet inzake rijksbelastingen (AWR)**, Stb. 1959/301 (procedural)
- **Invorderingswet 1990 (IW)** (enforcement, payment terms)
- **Algemene wet bestuursrecht (Awb)** §4:6 (bezwaar/beroep procedure)
- **Nederlandse Taxonomie (NT)**, jaarlijkse uitgave door SBR Programma (XBRL-taxonomie)
- **Digipoort-koppelvlakspecificatie** (Logius)
- **eHerkenning-niveau EH3** (Logius) — verplicht voor Vpb-aangifte signing
- **Wet bevordering speur- en ontwikkelingswerk (WBSO)** (S&O-verklaringen voor innovatiebox)
- **Pensioenwet 2007** (relevant for VPB facility interactions)
- **RVO.nl decisies** on facility percentages, cumulation rules, minima/maxima per
  belastingjaar

## Documentation & Audit Trail

All fiscal corrections, facility claims, tariff applications, voorvoegingsverlies
usage, bezwaar/beroep decisions, and amendments are recorded with entry date, entered-by
person, and approval status. External accountants can review complete audit trail in the
jaarrekening cycle without requesting spreadsheets or email chains.
