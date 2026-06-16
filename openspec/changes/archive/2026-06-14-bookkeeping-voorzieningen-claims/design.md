# Design — IAS 37 / RJ 252 Provisions, Contingent Liabilities and Contingent Assets

## Context

IAS 37 Provisions, Contingent Liabilities and Contingent Assets (IASB)
and RJ 252 Voorzieningen, niet uit de balans blijkende verplichtingen en niet
uit de balans blijkende activa (Dutch GAAP) require identification, measurement,
and disclosure of uncertain future obligations. A **provision** exists when:
1. An entity has a present obligation (legal or constructive) as a result of a
   past event;
2. It is probable (> 50% chance) that an outflow of resources will be required
   to settle the obligation;
3. A reliable estimate can be made of the amount.

If criteria 1–2 are met but not criterion 3 (estimate unreliable), the
obligation is a **contingent liability** and disclosed in notes only (not on
balance sheet). If probability < 50% but ≥ 5%, it is classified as "possible"
and disclosed. If probability < 5%, it is "remote" and not disclosed.

For Dutch MKB+, the annual provision cycle is:
1. **Initial recognition**: Controller identifies obligations, documents three
   criteria, measures best-estimate + range, determines disconteringsvoet if
   timing > 1 year.
2. **Movement tracking**: Period roll-forward (opening, dotations, vrijvallen,
   discontering unwinding, estimate changes, closing).
3. **Herwaardering**: Each balansdatum, re-evaluate probability, estimate,
   and disconteringsvoet; record changes prospectively (IAS 8).
4. **Disclosure**: Aggregate table per provision type showing movements, plus
   contingent liabilities narrative in jaarrekening notes.

Per ADR-031, the entire measurement model is declarative: schema metadata +
aggregation queries that emit roll-forward records + lifecycle automation.
Per ADR-022, expert rapporten are archived via docudesk, not in app-local storage.

The change is **spec-only**. Implementation lands later through `opsx-apply` and
the standard Hydra pipeline.

## Goals

- Express the entire IAS 37 / RJ 252 accounting surface as **declarative metadata**
  — schemas + lifecycle + aggregation formulas — per ADR-031.
- Make the spec a **competent-CFO readable contract** — three-criteria recognition,
  best-estimate valuation, sensitivity analysis, discontering, period roll-forward,
  and disclosure all traceable in linked `provision` + `provision-movement` records.
- Enforce three-criteria gating (obligation, probability, estimate reliability)
  + peer-review audit trail without PHP service logic.
- Support all nine Dutch provision types (pensioen, jubileum, herstructurering,
  garantie, milieu, claims, onderhoud, dubieuze debiteuren, overig) with
  type-specific schema extensions.
- Keep the period roll-forward calculation and sensitivity analysis declarative
  so movement aggregations, unwinding of discount, and estimate-change prospectivity
  are computable formulae, not hand-coded services.

## Non-Goals

- No PHP provision valuation service (best-estimate calculation).
- No external expert-report connector (PDF scraping, structured API feed) — v1 manual,
  T4 connector.
- No herstructureringsvoorziening workflow automation (plan authoring, communication,
  consent) — decidesk integration T4.
- No multi-currency FX revaluation — single-currency scope.
- No governance approval workflow automation — decidesk integration T4.

## Decisions

### D1 — Three registers: core provision + movement + contingent liability

Provision accounting is decomposed into:

- **provision**: Core polymorphic record (id, provisionType, description,
  recognitionDate, recognitionRationale, legalOrConstructiveObligation,
  obligatingEvent, probabilityOfOutflow, bestEstimate, bestEstimateRationale,
  rangeLow / rangeHigh, expectedTiming [shortTerm / mediumTerm / longTerm],
  discountRateApplied, discountedValue, presentationOnBalanceSheet,
  linkedAccount FK, status, expert, peerReviewer FK, peerReviewDate).

- **provision-movement**: Per-period roll-forward (provision FK, period,
  openingBalance, additions, additionsAcquired, usedDuringPeriod,
  releasedUnused, unwindingOfDiscount, effectOfChangeInDiscountRate,
  effectOfChangeInEstimate, translationDifferences, closingBalance,
  linkedJournalEntries array).

- **contingent-liability**: Disclosure-only record (description, obligationType,
  nature, estimatedAmount, probabilityCategory, expectedTiming,
  disclosureNarrative, relatedParty FK).

Plus 6 type-specific detail registers, each FK to `provision`:
  - `pensioenvoorziening-detail`: pensionScheme, actuarialMethod, discountRate, salaryGrowthAssumption, mortalityTable, participantCount, linkedActuaryReport FK.
  - `jubileumvoorziening-detail`: caoReference, eligibleEmployees, averageServiceYears, probabilityOfReachingMilestone, actuarialModel.
  - `herstructureringsvoorziening-detail`: detailedPlanDate, planCommunicatedTo array, affectedEmployees, expectedRedundancyPayments, expectedLeaseExitCosts, expectedOnerousContractCosts.
  - `garantievoorziening-detail`: productCategories array, historicalClaimRate, averageClaimAmount, warrantyPeriodMonths, revenueBaseInPeriod.
  - `milieuvoorziening-detail`: contaminationLocation, regulatoryFramework, cleanupEstimate, expertConsultant, legallyRequiredCompletionDate, phasedExecutionPlan, ontmantelingsVerplichting boolean.
  - `claims-voorziening-detail`: caseReference, court, legalCounsel, claimType, plaintiffOrClaimant, amountClaimed, bestEstimateSettlement, legalAdviceMemo FK.

**Alternative considered**: Monolithic provision register with all fields
embedded. Rejected — type-specific fields vary too widely; separate detail
registers allow clean schema + audit trail per subtype + easier amendment tracking.

### D2 — Three-criteria recognition gate at schema level

All `provision` records MUST satisfy three criteria per IAS 37 §35–37 / RJ 252
§301–305:
1. **legalOrConstructiveObligation**: Enum (legal | constructive); obligatingEvent
   (text) describing past event that created obligation.
2. **probabilityOfOutflow**: Decimal 0–1; system enforces ≥ 0.5 for balance-sheet
   recognition; 0.05–0.5 routes to contingent-liability; < 0.05 blocks disclosure.
3. **bestEstimate + bestEstimateRationale**: Decimal + text evidence; system blocks
   recognition if estimate unreliable or missing.

Schema validator enforces all three before status=active.

**Alternative considered**: Allow provisioning with probability < 0.5 (put on balance
sheet anyway). Rejected — IAS 37 explicit; probability ≤ 0.5 is contingent liability.

### D3 — Best-estimate with sensitivity range (low / high)

Every `provision` has rangeLow / rangeHigh in addition to bestEstimate per IAS 37
§39 / RJ 252 §306. Sensitivity calculation captures:
- Uncertainty in fact (e.g., expert report cites range EUR 600K–1.4M).
- Discount-rate sensitivity (if disconteringsvoet applied, ±0.5pp delta).
- Assumption sensitivity (e.g., claim settlement ±30% on legal assessment).

Aggregation query auto-generates sensitivity disclosure for jaarrekening.

**Alternative considered**: Single point estimate only. Rejected — regulators +
auditors expect range; hiding uncertainty creates audit findings.

### D4 — Disconteringsvoet application when timing material (> 1 year)

When expectedTiming.longTerm > 0 (material outflow > 1 year future) per IAS 37
§45 / RJ 252 §310, discountRateApplied MUST be filled with a risk-adjusted rate:
- Default: AA-rated corporate bond yield + entity-specific risk premium (0.5–2%).
- Enforcement: Schema warns if government-bond rate used (lower-biased).
- Formula: `discountedValue = bestEstimate / (1 + discountRateApplied) ^ years`.
- Annual **unwinding of discount**: Each period's unwindingOfDiscount = prior
  discountedValue × discountRateApplied (flows to financiële lasten GL).

**Alternative considered**: No discontering (simpler, but IAS 37 §45 mandatory for
material timing). Rejected.

### D5 — Period roll-forward immutability + annual herwaardering

Each `provision-movement` record represents a closed period (month / quarter / year).
Once period closes, the record is immutable (status=closed). If error discovered,
correction is recorded as prospective schattingswijziging via
effectOfChangeInEstimate in next open period (IAS 8 prospective treatment, not
retroactive restatement).

Active `provision` records remain open for annual balansdatum herwaardering:
1. Controller re-evaluates legalOrConstructiveObligation, probabilityOfOutflow,
   bestEstimate, disconteringsvoet.
2. Any delta is recorded as new effectOfChangeInEstimate or effectOfChangeInDiscountRate
   in the herwaardering period (usually period 12 / full year).
3. Prior-period movements remain locked; audit trail shows who changed what when.

**Alternative considered**: Allow retroactive adjustments to closed periods.
Rejected — jaarrekening reproducibility + audit trail integrity.

### D6 — Peer-review approval for materiality > EUR 100K or > 1% balance

Every `provision` with bestEstimate > EUR 100K OR > 1% of prior-year total assets
MUST be approved by:
- A **peer reviewer** (different person than the recognition author).
- **CFO or audit-committee member** (for largest items).

Approval recorded in peerReviewer FK + peerReviewDate; status remains draft until
approval complete. Rejected or recalled approvals documented in auditTrail.

**Alternative considered**: No peer review. Rejected — IAS 37 is inherently
subjective; management incentive to bias; peer review reduces audit risk.

### D7 — Automatic probability classification (REQ-PROV-007)

Schema enforcement:
- probability > 0.5 → `provision` on balance sheet.
- 0.05 < probability ≤ 0.5 → `contingent-liability` record with
  probabilityCategory=possible.
- probability ≤ 0.05 → Not recorded (remote).

System prompts: "Probability 30%, which suggests contingent-liability entry
instead." Helps operator avoid misclassification.

**Alternative considered**: Manual selection. Rejected — too many entities
misclassify; auto-detection enforces standard.

### D8 — Obligating-event documentation (legal vs constructive)

Every `provision` MUST explicitly state legalOrConstructiveObligation (legal |
constructive) + obligatingEvent (text describing the past event per IAS 37 §35).

Legal obligation examples: court judgment, signed contract, statute.
Constructive obligation examples: press announcement of restructuring plan
prior to balansdatum per IAS 37 BC §82–89; entity's established informal policy
(rare).

Schema enforces at validation time.

### D9 — Type-specific detail extension registers

Six detail registers provide type-specific fields:

**pensioenvoorziening-detail**: Actuarial method (PUC per IAS 19), discount rate,
salary-growth assumption, mortality table (AG-tabel year), participant count,
linked actuary report (FK docudesk). Flows from external actuariële bureau or
`bookkeeping-pension-ias19` spec.

**jubileumvoorziening-detail**: CAO reference (text), eligible employees (count),
average service years, probability of reaching milestone (turnover adjustment),
actuarial model (simplified or full).

**herstructureringsvoorziening-detail**: Detailed plan date (MUST be ≥ balance
date per IAS 37 §72), planCommunicatedTo (array: HR, unions, affected teams),
affected employees (count), expected severance, expected lease-exit costs,
expected onerous-contract costs. System blocks creation if detailedPlanDate >
balance date (mandatory criterion IAS 37 §72).

**garantievoorziening-detail**: Product categories (text array), historical
claim rate (decimal %), average claim amount, warranty period (months), revenue
base in period (decimal). Used for sensitivity: new revenue × expected claim
rate → expected outflow.

**milieuvoorziening-detail**: Contamination location (text), regulatory framework
(Wbb / Wm / EU-IED enum), cleanup estimate (decimal), expert consultant (FK docudesk),
legally required completion date, phased execution plan (text), ontmanteling
verplichting (boolean for IFRS 16 / IAS 16 §16(c) component activation).

**claims-voorziening-detail**: Case reference (court docket number), court (text),
legal counsel (name), claim type (contractbreuk / productaansprakelijkheid /
arbeidsrecht / IE-inbreuk / belasting / overig), plaintiff/claimant name,
amount claimed (decimal), best estimate settlement (decimal, from legal memo),
legalAdviceMemo FK (docudesk, restricted access to CFO / audit committee).

**Alternative considered**: Single flat `provision` table. Rejected — type-specific
fields create too much schema bloat; separate detail registers cleaner.

### D10 — Jaarrekening disclosure table (REQ-PROV-008)

Aggregation query generates standardised disclosure table per IAS 37 §85 / RJ 252
§408:

**Header:** Provision type, count, total openingBalance.

**Movement table:** For each provision, opening → additions → usedDuringPeriod →
releasedUnused → unwindingOfDiscount → effectOfChangeInEstimate → closing.

**Contingent liabilities section:** Narrative on contingent liabilities (nature,
probability, estimated amount, expected timing, related parties).

**Materiality narrative:** For each provision > EUR 100K, explain nature of
obligation, key assumptions (discount rate, claim-rate assumptions, etc.),
basis of best estimate.

**Sensitivity narrative:** If rangeLow / rangeHigh material, cite and explain.

Output is a structured record (`provision-disclosure-tabel`) suitable for
copy/paste into jaarrekening notes.

**Alternative considered**: Manual disclosure drafting. Rejected — transcription
error, incompleteness; automated generation ensures consistency + audit trail.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Three-criteria recognition validation | OR `x-openregister-calculations` (if extension supports IAS 37 logic) | Schema validator fields: legalOrConstructiveObligation (enum), obligatingEvent (text), probabilityOfOutflow (≥0.5 for balance-sheet), bestEstimate (required). Enum+constraint enforcement. |
| Best-estimate + sensitivity range | OR `x-openregister-calculations` (delta-recomputation) | rangeLow / rangeHigh stored as plain fields; aggregation query auto-generates sensitivity narrative. |
| Disconteringsvoet + unwinding | OR `x-openregister-calculations` (formula-based) | discountRateApplied + discountedValue = bestEstimate / (1 + rate)^years; unwindingOfDiscount = prior × rate per period. Formulaic, not service-driven. |
| Period roll-forward movement | OR `x-openregister-aggregations` (period-driven query) | Aggregation emits `provision-movement` records from `provision` opening balance + prior period closing + current-period changes. |
| Peer-review approval gate | T3 decidesk `DecisionApprovalService` (optional T4) or manual approvalChain field | peerReviewer FK + peerReviewDate tracked; optional decidesk integration for large materiality items (> EUR 100K or > 1% balance). |
| Contingent-liability classification | OR `x-openregister-calculations` (probability-branch rule) | Schema rule: if 0.05 < probability ≤ 0.5, prompt user to create `contingent-liability` instead of `provision`. |
| Type-specific detail | T3 registers: pensioenvoorziening-detail, jubileumvoorziening-detail, etc. | FK array from `provision` to type-specific detail register; schema discriminator (provisionType enum) enforces presence of matching detail. |
| GL posting (dotatie, vrijval, discontering) | T2 `bookkeeping-general-ledger` | linkedAccount FK on `provision` + implicit GL posting rules triggered by lifecycle transitions (status changes from draft → active, or provision-movement changes to used/released). Posting logic in GL module, not here. |
| Document archival (expert rapporten, legal memos) | T2 `bookkeeping-document-attachment-integration` (via docudesk) | File FK fields: linkedActuaryReport, legalAdviceMemo, reorganisatieplan. Files stored in docudesk under restricted access (CFO, audit committee, accountant). |
| Jaarrekening disclosure table | T3 `bookkeeping-financial-statements` | `provision-disclosure-tabel` record consumed as data-source for jaarrekening notes renderer. |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all schema writes + lifecycle transitions. |

**Net new code in implementation cycle**: 9 schema declarations + 2 lifecycle
blocks (recognition, herwaardering) + 2 aggregation queries (roll-forward,
disclosure-table) + 3 manifest entry pairs + 0 PHP service. At most 1 small
`ThreeCriteriaValidator` helper if schema-level enforcement insufficient.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Three-criteria recognition validation | Declarative (schema validator enforcing legalOrConstructiveObligation enum, obligatingEvent text, probabilityOfOutflow ≥ 0.5) | Scalar fields + enum constraints, no service logic. |
| Best-estimate + sensitivity range | Declarative (rangeLow / rangeHigh stored fields; aggregation query emits narrative) | Pure data storage + reporting, no calculation service. |
| Disconteringsvoet + unwinding | Declarative (formula: discountedValue = bestEstimate / (1 + rate)^years; unwindingOfDiscount = prior × rate) | Deterministic arithmetic. |
| Period roll-forward movement | Declarative (`x-openregister-aggregations` query emitting `provision-movement` from valuation + prior period) | Data join + aggregation formula. |
| Contingent-liability classification | Declarative (schema rule: if 0.05 < prob ≤ 0.5, suggest contingent-liability) | Branching logic, no service. |
| Prospective schattingswijziging | Declarative (effectOfChangeInEstimate field on `provision-movement`; lifecycle rule: no retroactive changes to closed periods) | Immutability constraint + prospective-only rule. |
| Provision-type discrimination | Declarative (provisionType enum on `provision` + FK to type-specific detail register) | Discriminated-union pattern. |

No service class authored in this envelope (subject to ADR-031 exception: at most
one small validator if needed).

## Seed Data

Nine seed records:

1. **provision**: "Garantievoorziening standaard 2026"
   - provisionType: garantie
   - description: "Waarborg op verkochte goederen 2026"
   - recognitionDate: 2026-01-01
   - legalOrConstructiveObligation: constructive
   - obligatingEvent: "Verkoop goederen met waarborg 12 maanden per contractvoorwaarden"
   - probabilityOfOutflow: 0.80
   - bestEstimate: 120000
   - rangeLow: 80000
   - rangeHigh: 150000
   - expectedTiming: {shortTerm: 120000, mediumTerm: 0, longTerm: 0}
   - discountRateApplied: null
   - presentationOnBalanceSheet: current
   - linkedAccount: "1900" (passiva — korte termijn voorzieningen)
   - status: active
   - expert: null

2. **provision**: "Milieuvoorziening bodemsaneringslocatie"
   - provisionType: milieu
   - description: "Bodemsanering verontreinigde locatie Rijnmond per Wbb"
   - recognitionDate: 2025-01-01
   - legalOrConstructiveObligation: legal
   - obligatingEvent: "Bodemsaneringsrapport d.d. 2024-09-15 door Env.Bureau stelt verplichting vast"
   - probabilityOfOutflow: 0.95
   - bestEstimate: 800000
   - rangeLow: 600000
   - rangeHigh: 1400000
   - expectedTiming: {shortTerm: 150000, mediumTerm: 350000, longTerm: 300000}
   - discountRateApplied: 0.03 (2.5% risk-free + 0.5% risico-opslag)
   - discountedValue: 731000 (simplified approx)
   - presentationOnBalanceSheet: split
   - linkedAccount: "1901"
   - status: active
   - expert: "Bureau Milieutechniek B.V."

3. **provision**: "Claims-voorziening productaansprakelijkheid zaak X"
   - provisionType: claims
   - description: "Rechtszaak: klant vs ons n.a.v. productschade"
   - recognitionDate: 2025-06-01
   - legalOrConstructiveObligation: legal
   - obligatingEvent: "Rechtsvordering ingesteld 2025-05-15, rolnummer 2025/12345"
   - probabilityOfOutflow: 0.60
   - bestEstimate: 500000
   - rangeLow: 300000
   - rangeHigh: 700000
   - expectedTiming: {shortTerm: 0, mediumTerm: 500000, longTerm: 0}
   - discountRateApplied: null (< 1 jaar)
   - presentationOnBalanceSheet: current
   - linkedAccount: "1902"
   - status: active
   - expert: "Advocatenkantoor De Vrieze" (peerReviewDate: 2025-07-15)

4. **provision**: "Herstructureringsvoorziening vestiging sluiting"
   - provisionType: herstructurering
   - description: "Geplande sluiting distributiecentrum Eindhoven medio 2026"
   - recognitionDate: 2025-10-01
   - legalOrConstructiveObligation: constructive
   - obligatingEvent: "Bestuursbesluit 2025-09-15, personeelsmemo gepubliceerd 2025-09-20"
   - probabilityOfOutflow: 0.90
   - bestEstimate: 850000
   - rangeLow: 700000
   - rangeHigh: 1050000
   - expectedTiming: {shortTerm: 500000, mediumTerm: 350000, longTerm: 0}
   - discountRateApplied: null
   - presentationOnBalanceSheet: split
   - linkedAccount: "1903"
   - status: active

5. **jubileumvoorziening-detail**: Linked to pensioenvoorziening seed
   - description: "Jubileum 25/40 jaar per CAO Metaal & Techniek"
   - caoReference: "CAO Metaal & Techniek art. 8.3 jubileumuitkering"
   - eligibleEmployees: 145
   - averageServiceYears: 18
   - probabilityOfReachingMilestone: 0.75
   - actuarialModel: "Simplified: active × accrual rate × avg-salary"

6. **contingent-liability**: "Fiscaal geschil inkomstenbelasting"
   - description: "Geschil belastingdienst n.a.v. aftrekpostbepaling jaren 2022–2023"
   - obligationType: legal
   - nature: "Fiscaal geschil belastingdienst"
   - estimatedAmount: 400000
   - probabilityCategory: possible (30% kans handhaving beroep)
   - expectedTiming: "Uitspraak hoger beroep verwacht Q4 2026"
   - disclosureNarrative: "Het belastingdienst heeft een voorstellingscorrectie vóór belasting opgelegd. Naar schatting van onze belastingadviseur is er 30% kans dat deze in hoger beroep zal worden gehandhaafd. Geen reliable estimate beschikbaar."

7. **contingent-liability**: "Borgstelling dochtermaatschappij krediet"
   - description: "Onherroepelijke borgtocht voor bankkrediet dochtermaatschappij NV B.V."
   - obligationType: legal
   - nature: "Borgstelling"
   - estimatedAmount: null (uncertain, depends on dochter's default scenario)
   - probabilityCategory: remote
   - expectedTiming: "Lening vervaldag 2027"
   - disclosureNarrative: "Wij hebben als borgtocht opgefungeerd voor een bankkrediet van EUR 2.5M van dochtermaatschappij. Op balansdatum geen aanwijzingen voor default; waarschijnlijkheid remote."

8. **provision-movement (seed template)**: 
   - provision: FK to guarantee provision
   - period: "2026-12"
   - openingBalance: 0 (new provision)
   - additions: 120000 (dotatie naar resultaat)
   - usedDuringPeriod: 45000 (uitgevoerde garantie-reparaties)
   - releasedUnused: 0
   - unwindingOfDiscount: 0
   - closingBalance: 75000
   - linkedJournalEntries: [FK JE-001, FK JE-002]

9. **provision-disclosure-tabel (seed template)**:
   - period: "2026-12"
   - provisionType: "garantie"
   - count: 1
   - openingBalance: 0
   - additions: 120000
   - used: 45000
   - released: 0
   - unwinding: 0
   - estimates_change: 0
   - closingBalance: 75000
   - narrative: "Garantievoorziening EUR 75K per 31 december 2026, waarvan EUR 45K in 2026 is gebruikt voor reparaties. Verdere mutatie in het komend jaar verwacht op basis van 1.5% van geschatte omzet goederen."

Operators customise these per entity on first use.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Best-estimate inherently subjective; management incentive to bias high (earnings protection) or low (P&L boost) | Spec-level audit trail on entry source + peer-review approval gate + CFO sign-off for materiality + external accountant challenge in audit cycle. Sensitivity range disclosure makes range transparent. |
| Three-criteria judgment may be unclear (e.g., moral obligation vs legal obligation; "probable" vs "possible") | Comprehensive guidance in each requirement scenario per IAS 37 BC §82–89; schema prompts per obligatingEvent text validation; legal counsel mandatory for claims-voorziening. |
| Disconteringsvoet selection (risk-free vs risk-adjusted) complex; wrong rate materially misstates value | Spec warns on government-bond rate; recommends market proxy (AA corporates) per IAS 37 BC §141; schema constraint enforces only market-based rates. |
| Herstructureringsvoorziening detailed-plan requirement (IAS 37 §72) may be interpreted loosely; entity recognizes provision without true communication to affected parties | Schema enforces detailedPlanDate ≤ balance date; planCommunicatedTo array required (HR, unions, employee reps, etc.); audit trail on communication evidence (docudesk archive). |
| Claims-voorziening legal-advice memo sensitive (attorney-client privilege); accidental disclosure creates legal/reputational risk | Memo stored under restricted access (CFO, audit committee, accountant only) via docudesk role-based access control. |
| Provision lifecycle: retroactive changes to closed periods create audit-trail corruption | Once period closes, movements immutable; corrections recorded as prospective schattingswijziging in open period (IAS 8 compliance). |
| Contingent-liability disclosure incomplete (entity mentions liability informally, not in contingent-liability register) | Jaarrekening disclosure module checks for unrecorded contingencies; auditor queries prompt registration in contingent-liability. |

## Migration Plan

No legacy data migration required. Provision accounting is introduced as a new
module; existing customers on Shillinq without provision obligations are not
affected. Customers with existing provision data (via spreadsheets or external
tools) can opt-in and import seed data per-entity, then manually register each
provision via three-criteria workflow.

## Compliance & Standards

Spec implements:
- **IAS 37 Provisions, Contingent Liabilities and Contingent Assets** (IASB)
- **RJ 252 Voorzieningen, niet uit de balans blijkende verplichtingen en niet
  uit de balans blijkende activa** (Raad voor de Jaarverslaggeving, Dutch GAAP)
- **IFRS for SMEs Section 21 Provisions and Contingent Liabilities**
- **IAS 19 Employee Benefits** (for pensioenvoorziening, separate spec)
- **IAS 36 Impairment of Assets** (for herstructurering write-downs)
- **IFRS 16 Leases** (for onerous lease-contract provisions)
- **IAS 16 Property, Plant and Equipment §16(c)** (for ontmantelings obligations)
- **Dutch Titel 9 Boek 2 BW art. 374–376** (voorzieningen in Dutch civil law)
- **Wet Bodembescherming (Wbb)** (for environmental remediation)
- **CAO-bepalingen** per sector (for jubilee provisions)
- **Wet Melding Collectief Ontslag (WMCO)** (for restructuring communication)

## Documentation & Audit Trail

All obligation recognitions, best estimates, disconteringsvoet assumptions,
amendments, and peer-review approvals are recorded with entry date, entered-by
person, and approval status. External accountants can review complete audit trail
in the jaarrekening cycle without requesting spreadsheets.
