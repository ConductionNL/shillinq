# Design — IAS 19 Employee Benefit Pension Accounting

## Context

IAS 19 Employee Benefits (revised 2011) and RJ 271 Personeelsbeloningen
require complete measurement and disclosure of defined-benefit (DB) and
defined-contribution (DC) pension obligations. For DB plans, the Projected
Unit Credit (PUC) method calculates the Defined Benefit Obligation (DBO)
per medewerker per dienstjaar; plan assets (if any) are measured at fair
value; the net pension position (DBO − assets) is split into P&L buckets
(service cost, past service cost, net interest) and OCI (non-recycling
remeasurements on DBO and asset returns).

For Dutch MKB+, the annual pension accounting cycle involves:
1. External actuaris delivers DBO + plan-asset valuation + assumptions
2. Entity roll-forwards movement in service cost + net interest + remeasurements
3. Entity books P&L entries (personeelslasten, financiële lasten) + OCI (equity)
4. Entity discloses full tables in jaarrekening notes per IAS 19 §135–149

Per ADR-031, the entire measurement model is declarative: schema metadata
+ aggregation queries that emit roll-forward records + lifecycle automation.
Per ADR-022, actuarial reports are archived via docudesk, not in app-local
storage.

The change is **spec-only**. Implementation lands later through `opsx-apply`
and the standard Hydra pipeline.

## Goals

- Express the entire IAS 19 accounting surface as **declarative metadata** —
  schemas + lifecycle + aggregation formulas — per ADR-031.
- Make the spec a **competent-CFO readable contract** — Dutch RJ-271 audit
  annual cycle recognisable end-to-end (plan registration, actuarial input,
  roll-forward movement, P&L/OCI split, disclosure).
- Enforce PUC method + discount-rate market-reference + non-recycling OCI
  per IAS 19R without PHP actuarial service logic.
- Support both DB (full measurement) and DC (light disclosure) workflows per
  IAS 19 §39–84 split.
- Keep the roll-forward calculation declarative so PUC, service cost, net
  interest, and remeasurements are computable formulae, not hand-coded
  services.

## Non-Goals

- No PHP actuarial calculation service (PUCCalculator.php, DBO.php).
- No real-time asset-management connectors (Bloomberg, FactSet).
- No multi-currency FX revaluation within IAS 19 (single-currency scope).
- No governance workflows (PEC approval, regeling-wijziging flowes) — owned
  by decidesk integration (T4).
- No mortality/longevity improvement modelling — inputs only.

## Decisions

### D1 — Six registers: plan header + valuation + movement + sensitivity + asset detail + disclosure

Pension accounting is decomposed into:
- **pension-plan**: regeling metadata (planType, accrualRate, eligibility,
  governance, linked HRMQ group)
- **actuarial-valuation**: per-balansdatum measurement (DBO gross, plan assets,
  assumptions, actuary sign-off)
- **pension-movement**: per-period roll-forward (opening DBO / service cost /
  past service / net interest / actuariaal G/L DBO / actuariaal G/L assets /
  closing DBO, same for plan assets)
- **pension-assumption-sensitivity**: DBO sensitivity on each balansdatum
  (discount rate ±0.5pp, salary growth ±0.5pp, mortality ±1yr, inflation ±0.5pp)
- **pension-asset-detail**: plan-asset breakdown by category (cash, equities,
  bonds-gov, bonds-corp, real-estate, alternative, derivatives) + IFRS 13
  fair-value levels (1/2/3)
- **pension-disclosure-tabel**: generated jaarrekening disclosure table

**Alternative considered**: Monolithic pension-valuation register with all
fields embedded. Rejected — multi-period roll-forward + per-assumption
sensitivity + asset breakdown require first-class records for drill-down
and audit trail.

### D2 — DBO measurement: PUC method mandatory for DB, disclosure-only for DC

All DB plans (`planType=DB`) MUST use Projected Unit Credit (PUC) per
IAS 19 §67 + RJ 271. The valuation enforces `methodology=PUC` at schema level.

DC plans (`planType=DC`) skip DBO measurement; only contribution + brief
regeling description required per IAS 19 §53.

**Alternative considered**: Auto-detect method from actuaries' report.
Rejected — PUC is the IFRS standard; any other method requires explicit
exception and auditor waiver.

### D3 — Disconteringsvoet: market-referenced (AA-rated corporates)

The discount rate MUST be derived from quoted market prices of high-grade
(AA-rating) corporate bonds in the relevant currency and duration per IAS 19
§83 + RJ 271. Default source: iBoxx € Corporates AA for EUR DBO. Enforcement:
spec warns if government-bond rate used (understandable choice but lower-
biased).

**Alternative considered**: DN-curve (Dutch risk-free rate). Rejected — not
a competent market proxy per IAS 19 BC §141; AA corporates recommended for
Dutch SMB context.

### D4 — Jaarlijkse mutatie: three buckets (P&L, P&L, OCI)

Service cost + past service cost + settlement → P&L (personeelslasten /
financiële lasten categories).
Net interest = disconteringsvoet × netto pensioenpositie opening → P&L
(financiële lasten).
Actuariële verschillen DBO (demographic, financial, experience) +
actuarieel rendement verschil assets → OCI (non-recycling per IAS 19 §122).

Each period rolls forward opening balance + all movements → closing balance
per bucket. Aggregation query emits roll-forward records from valuation +
assumption changes.

**Alternative considered**: Corridor method (pre-2013 IAS 19 option).
Rejected — IAS 19R (post-2013) mandatory; corridor discontinued.

### D5 — Asset ceiling (IFRIC 14) on netto vordering

When plan assets > DBO (overfunded), the net-pension asset is capped by the
lower of: (1) DBO − plan assets, or (2) present value of future contribution
reduction or repayment per IFRIC 14 §5. Schema field `assetCeilingApplied`
records the adjustment; disclosure highlights the limit.

**Alternative considered**: Assume no asset ceiling (underfunded plans
dominate Dutch DB). Rejected — large pension fondsen (collective DB
arrangements) are often overfunded; spec must handle both.

### D6 — Sensitivity analysis: four assumptions, ±standard deltas

Per IAS 19 §145, the entity discloses sensitivity on:
- Discount rate: ±0.5pp (typical market range)
- Salary growth: ±0.5pp (typical CLA adjustments)
- Mortality: ±1 year life expectancy (typical longevity drift)
- Inflation: ±0.5pp (typical RPI range)

Sensitivity query (x-openregister-aggregations) recomputes DBO + service
cost for each delta; results stored in `pension-assumption-sensitivity`
records.

**Alternative considered**: Manual sensitivity entry. Rejected — automated
computation ensures consistency and reduces data-entry error.

### D7 — Actuarial input: external actuaries via manual copy/paste (v1)

V1: Entity copy/pastes actuarial report data into `actuarial-valuation`
schema fields. Field-level audit trail tracks entry + approvals.

V2 (T4 connector): Direct API feed from major Dutch actuarial bureaus
(Mercer, WTW, Sprenkels, Aon, DeAvoort, etc.) for automated input.

**Alternative considered**: No external input, internal PUC calculator.
Rejected — PUC implementation is complex (mortality tables, salarisgroei,
arbeidsontslag, etc.); outsource to specialist bureaus standard practice.

### D8 — HRMQ integration: annual validation of deelnemersbestand

Per REQ-PEN-010, at the start of the annual pension cycle, the system
validates the active employee roster (geboortedatum, salaris, dienstjaren)
against HRMQ `pension-administration` module. Differences (new hires,
departures, salary changes) flagged for HR-controller review before
actuarial valuation locked.

**Alternative considered**: No HRMQ sync, rely on actuaries to source
deelnemersbestand. Rejected — in-house HRMQ data is authoritative; manual
sync introduces reconciliation risk.

### D9 — Disclosure tabel: auto-generated per IAS 19 §135–149

A `pension-disclosure-tabel` record is generated from the completed
`pension-movement` + `pension-asset-detail` + `pension-assumption-sensitivity`
records. Format is a standardised table suitable for jaarrekening notes:
- Header: plan name, plan type, regeling summary, governance
- Main assumptions: discount rate, salary growth, mortality table, inflation,
  retirement age
- DBO movement: opening → service → past service → net interest → actuariaal
  G/L → closing (separate demographic / financial / experience G/L)
- Asset movement: opening → expected return → actual return → G/L →
  contributions → benefit paid → closing
- P&L summary: service cost, net interest, total P&L
- OCI summary: total actuariaal G/L (remeasurement)
- Asset breakdown by category + fair-value level
- Duration of DBO + weighted average life
- Expected employer contribution next year

**Alternative considered**: Manual disclosure authoring. Rejected — generated
disclosure ensures consistency with underlying GL posting + reduces
transcription error.

### D10 — Non-DB plan handling: DC plans skip DBO workflow

DC plans are identified by `planType=DC`. The valuation records only
contribution amount + regeling metadata. No DBO, no PUC, no sensitivity.
Disclosure-tabel shows only "Pensioenlast DC: EUR XXX" + regeling brief.

**Alternative considered**: Force DC plans through same detailed workflow as
DB. Rejected — IAS 19 §53 explicitly simplifies DC; mandating DB workflow
creates operator confusion.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| PUC method for DB plans | OR `x-openregister-calculations` (if extension supports pension arithmetic) + schema validator | Formula metadata on `pension-plan` + `actuarial-valuation`; schema enforcement on `methodology=PUC` for DB |
| DBO roll-forward movement | OR `x-openregister-aggregations` (period-driven movement query) | Aggregation emits `pension-movement` records from `actuarial-valuation` + prior-period closing balance |
| Sensitivity calculation | OR `x-openregister-calculations` (delta-recomputation) | Formula on discount rate / salary growth / mortality / inflation deltas; query emits `pension-assumption-sensitivity` records |
| Fair-value categorisation | T1 `Account` register reference (asset accounts in GL) | Asset-category metadata on `pension-asset-detail` |
| P&L / OCI posting | T2 `bookkeeping-general-ledger` | Service / net interest → GL (personeelslasten / financiële lasten); remeasurements → OCI account via GL |
| Actuarial document archival | T2 `bookkeeping-document-attachment-integration` (via docudesk) | `actuarial-valuation.valuationReport` FK to docudesk file URI |
| Disclosure table generation | T3 `bookkeeping-financial-statements` (jaarrekening renderer) | `pension-disclosure-tabel` consumed as data-source for notes |
| Deelnemersbestand validation | T2 hrmq `pension-administration` module | `pension-plan.linkedHrmqGroup` reference; sync query validates roster before actuarial-valuation lock |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on all schema writes + lifecycle transitions |
| Governance approval | T3 decidesk `DecisionApprovalService` (future T4) | Material plan amendments (accrual rate change, plan termination) routed via decidesk approval |

**Net new code in implementation cycle**: 6 schema declarations + 3 lifecycle
blocks + 2 aggregation queries (roll-forward, sensitivity) + 3 manifest entry
pairs + 0 PHP service (all calculation declarative). At most 1 small
`PUCValidator` helper if schema-level enforcement insufficient.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| PUC method for DB plans | Declarative enforcement (`methodology=PUC` schema validator) | Scalar parameter, no service logic |
| DBO roll-forward movement | Declarative (`x-openregister-aggregations` query driven by prior-period + current-period valuation) | Pure data join + arithmetic |
| Sensitivity calculation | Declarative (`x-openregister-calculations` formula per assumption; aggregation recomputes) | Scalar adjustments applied to base valuation |
| Service cost + net interest + remeasurement bucketing | Declarative (schema fields on `pension-movement`) | Operator enters from actuarial report; GL posting rules are T2 GL module |
| Discount-rate validation | Declarative (schema constraint + warning if government-bond rate) | Enum or comparison rule |
| Asset ceiling application | Declarative (formula on `assetCeilingApplied` field; disclosure highlights) | IFRIC 14 is a deterministic formula |
| Disclosure-table generation | Declarative (aggregation query emitting `pension-disclosure-tabel`) | Template + data-source merge |

No service class authored in this envelope (subject to ADR-031 exception:
at most one small `PUCValidator` if needed).

## Seed Data

Three seed records:

1. **pension-plan**: "NL Standard DB Regeling"
   - planType: DB
   - regulatoryFramework: Pensioenwet
   - accrualRate: 1.875% (standard Dutch MKB rate)
   - retirementAge: 67 (current Dutch statutory age)
   - participantCount: 10 (placeholder)

2. **pension-plan**: "NL Standard DC Regeling"
   - planType: DC
   - regulatoryFramework: Pensioenwet
   - participantCount: 5 (placeholder)

3. **pension-assumption-sensitivity** (template)
   - assumption: "discount-rate"
   - direction: "+0.5pp"
   - (operator fills in actual DBO effect from actuary report)

Operators customise per entity on first use.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Actuarial inputs (DBO, assumptions) quality depends on external actuaris; Shillinq cannot validate correctness | Spec-level audit trail on entry + mandatory actuaris sign-off + external accountant review in jaarrekening cycle |
| PUC calculation differs across actuarial firms (mortality tables, salary-growth methodology); Shillinq's roll-forward may diverge from actuary's prior valuation | Sensitivity disclosure (discount rate, salary, mortality, inflation) lets CFO detect divergence; connector API (T4) for direct structured feed |
| Asset ceiling (IFRIC 14) has multiple reduction paths (contribution reduction, overfunding benefit, repayment); entity choice affects disclosure | Disclosure tabel highlights asset-ceiling adjustment amount; formal governance (decidesk) supports reduction-plan approval (T4) |
| DC plans accidentally enter DB workflow if operator selects planType incorrectly | Schema-level enum check on `planType`; DC plans blocked from DBO workflows at aggregation level |
| Disclosure tabel generated format may not exactly match external accountant's preferred layout | Template-driven generation (jaarrekening notes template); accountant can customize per entity via docudesk attachment override |

## Migration Plan

No legacy data migration required. Pension accounting is introduced as a new
module; existing customers on Shillinq without pension provisions are not affected.
Customers with existing pension provisioning (via spreadsheets or external
tools) can opt-in and import seed data per-entity.

## Compliance & Standards

Spec implements:
- **IAS 19 Employee Benefits** (IASB revised 2011)
- **RJ 271 Personeelsbeloningen** (Raad voor de Jaarverslaggeving, Dutch GAAP)
- **IFRS for SMEs Section 28 Employee Benefits**
- **IFRIC 14 — IAS 19: The Limit on a Defined Benefit Asset, Minimum Funding
  Requirements and their Interaction**
- **IFRS 13 Fair Value Measurement** (asset categorisation)
- **Dutch Pensioenwet** (2007) + **Wet Toekomst Pensioenen (2023)**
- **AG-Prognosetafel 2026** (Actuarieel Genootschap mortality tables)
- **iBoxx € Corporates AA Index** (discount-rate market proxy)

## Documentation & Audit Trail

All assumptions, DBO figures, past service costs, and amendments are recorded
with entry date, entered-by person, and approval status. External accountants
can review complete audit trail in the jaarrekening cycle without asking for
spreadsheets.
