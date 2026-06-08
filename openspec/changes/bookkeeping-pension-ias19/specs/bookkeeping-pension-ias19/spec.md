# Spec: IAS 19 Employee Benefit Pension Accounting (RJ 271)

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T3 (regulatory + compliance)  
**Primary spec:** bookkeeping-pension-ias19  

**Depends on:**
- bookkeeping-voorzieningen-claims (pension provision; DBO is a provision detail)
- bookkeeping-general-ledger (service cost, net interest, OCI posting)
- bookkeeping-deferred-tax (timing differences on pension)
- bookkeeping-financial-statements (jaarrekening disclosure table)

---

## Overview

This spec introduces complete IAS 19 / RJ 271 defined-benefit (DB) and
defined-contribution (DC) pension accounting for Shillinq. The system
measures, rolls forward, and discloses pension obligations and assets per
Projected Unit Credit (PUC) method for DB plans, or simplified disclosure
for DC plans.

Six new registers are declared:
1. **pension-plan** — regeling description (planType, accrual, eligibility, governance, HRMQ link)
2. **actuarial-valuation** — per-balansdatum measurement (DBO, plan assets, assumptions, actuary sign-off)
3. **pension-movement** — per-period roll-forward (service cost, net interest, remeasurements)
4. **pension-assumption-sensitivity** — DBO sensitivity on discount rate, salary growth, mortality, inflation
5. **pension-asset-detail** — plan-asset category breakdown (IFRS 13 fair-value levels)
6. **pension-disclosure-tabel** — jaarrekening disclosure table (auto-generated)

The accounting flow is declarative: schema metadata + aggregation queries
(roll-forward, sensitivity) + lifecycle automation. No PHP actuarial service.

---

## ADDED Requirements

### Requirement: REQ-PEN-001 PUC method enforced for DB plans

The system MUST enforce Projected Unit Credit (PUC) method for all
`pension-plan` records with `planType=DB`, per IAS 19 §67 + RJ 271 §6.

#### Scenario: DB regeling actuariële waardering 2026

- **GIVEN** a defined-benefit pension plan (`planType=DB`) with 1.875%
  accrual rate, pensioengrondslag EUR 80,000, 20 years to retirement age
- **WHEN** an `actuarial-valuation` record is created
- **THEN** the system requires `methodology=PUC`; any other methodology
  (e.g., average salary, entry age) is rejected
- **AND** the system verifies that DBO is calculated per medewerker per
  dienstjaar:
  - For each active medewerker: 1 eenheid pensioen per year × salarisgroei
    aanname × disconteringsvoet
  - For each deferred medewerker: 1 eenheid opgebouwd per historical year
  - For each retiree: actueel pensioen (no further accrual)
- **AND** the DBO is broken down by category (actieve / slapers /
  gepensioneerden) in the disclosure table

### Requirement: REQ-PEN-002 Discount rate market-referenced per IAS 19 §83

The `discountRate` in each `actuarial-valuation` MUST reflect the current
market yield of high-grade (AA-rating) corporate bonds in the relevant
currency and matching the DBO duration per IAS 19 §83 + RJ 271 guidance.

#### Scenario: Discount rate source validation 2026

- **GIVEN** a DBO with average duration 18 years in EUR
- **WHEN** an `actuarial-valuation` is recorded with `discountRate=2.0%` for 31-12-2026
- **THEN** the system checks that the rate is sourced from iBoxx € Corporates AA
  index (or equivalent quoted market index for EUR, 15–20 year maturity)
- **AND** a `discountRateSource` field (text) is populated to provide audit trail
  (e.g., "iBoxx EUR AA 15–20Y on 2026-12-31: 2.03%")
- **AND** if a government-bond rate (lower) is used, the system logs a warning
  (not an error, but flagged for accountant review)
- **AND** the accountant must approve the discount-rate assumption before
  the valuation is locked

### Requirement: REQ-PEN-003 Three-bucket movement: P&L (service/interest) vs OCI (remeasurement)

The system MUST split the annual pension movement into three buckets:
1. **Service cost** + **past service cost** + **settlement** → P&L (personeelslasten)
2. **Net interest cost** → P&L (financiële lasten)
3. **Actuarieel gain/loss** (DBO + assets) → OCI (non-recycling, per IAS 19 §122)

#### Scenario: Jaarbeweging 2026 met EUR 8M DBO, EUR 6.5M assets

- **GIVEN** opening DBO EUR 8,000,000, plan assets EUR 6,500,000 (netto
  verplichting EUR 1,500,000), disconteringsvoet 2.0%
- **AND** service cost EUR 320,000, no past service cost, no settlements
- **WHEN** the `pension-movement` record for 2026 is completed
- **THEN** the system records:
  - `serviceCostCurrent: 320000` (P&L)
  - `pastServiceCost: 0` (P&L)
  - `gainOnSettlement: 0` (P&L)
  - `netInterestCost: 30000` (EUR 1,500,000 × 2.0%, P&L)
  - `actuarialLossGainDBO: -180000` (OCI, due to e.g. rate fall)
  - `actuarialGainLossAssets: +90000` (OCI, e.g. return > expected)
  - Total P&L movement: service + interest = EUR 350,000
  - Total OCI movement: (-180,000) + 90,000 = EUR -90,000 (OCI loss)
- **AND** the three buckets NEVER overlap: service/interest/settlement are
  disjoint from remeasurement; no dual-posting
- **AND** GL posting rules (separate T2 GL spec) post service → personeelslasten
  account, net interest → financiële lasten, OCI movements → OCI account (not P&L)

### Requirement: REQ-PEN-004 OCI remeasurements are non-recycling

Actuarieel gain/loss (remeasurement differences) MUST be recorded in OCI
and MUST NEVER be reclassified to P&L in future periods per IAS 19 §122.

#### Scenario: Actuarieel verlies EUR 250K in 2026, omkeert in 2027

- **GIVEN** actuarieel verlies EUR 250,000 recorded in OCI in 2026 (e.g.,
  due to rate fall)
- **WHEN** in 2027 the actuarieel gain of EUR 250,000 occurs (e.g., due to
  rate rise), reversing the loss
- **THEN** the gain is also recorded in OCI in 2027, NOT in P&L
- **AND** the system blocks any manual journal that reclassifies OCI pension
  items to P&L (e.g., rule in `bookkeeping-general-ledger` that prevents
  "OCI recycle" transaction type for pension accounts)

### Requirement: REQ-PEN-005 Asset ceiling per IFRIC 14 on net pension asset

The system MUST apply the asset ceiling per IFRIC 14 §5–7 whenever the
actuarial valuation results in a net pension **asset** (plan assets
exceeding the defined-benefit obligation): the net asset is limited to the
present value of future contribution reductions or repayments.

#### Scenario: Overfunded plan with asset ceiling EUR 800K

- **GIVEN** plan assets EUR 9,000,000, DBO EUR 7,500,000 (raw overfunding
  EUR 1,500,000)
- **AND** the pension regeling permits only EUR 800,000 in future contribution
  reduction (documented in regeling terms or entity board decision)
- **WHEN** the `actuarial-valuation` is recorded for 31-12-2026
- **THEN** the system calculates:
  - `netPensionLiability = DBO − assets + assetCeilingAdjustment`
  - `assetCeilingApplied = -700000` (the excess overfunding beyond the
    EUR 800K limit; negative = reduction)
  - `netPensionLiability = 7,500,000 − 9,000,000 − 700,000 = EUR -1,200,000`
    (i.e., a net pension asset of EUR 1,200,000, not EUR 1,500,000)
  - Net pension **asset** (not liability) = EUR 800,000 (the IFRIC 14 cap)
- **AND** the disclosure tabel highlights the asset ceiling adjustment with
  explanation (e.g., "Asset ceiling per IFRIC 14: EUR 700,000 reduction
  applied; capped net asset = EUR 800,000")

### Requirement: REQ-PEN-006 Sensitivity analysis on four main assumptions

For each DB `actuarial-valuation` on a valuation date, the system MUST
generate sensitivity analysis on at least four assumptions per IAS 19 §145.

#### Scenario: Sensitivity on discount rate ±0.5pp

- **GIVEN** DBO EUR 8,000,000 at disconteringsvoet 2.0%
- **WHEN** the sensitivity analysis is triggered for the valuation
- **THEN** the system calculates and records four `pension-assumption-
  sensitivity` records:
  1. Discount rate +0.5pp (2.5%): DBO EUR 7,300,000 (effect −EUR 700,000)
  2. Discount rate −0.5pp (1.5%): DBO EUR 8,800,000 (effect +EUR 800,000)
  3. Salary growth +0.5pp: Service cost EUR 345,000 (effect +EUR 25,000)
  4. Salary growth −0.5pp: Service cost EUR 295,000 (effect −EUR 25,000)
  5. Mortality −1 year (longer life): DBO EUR 8,250,000 (effect +EUR 250,000)
  6. Mortality +1 year (shorter life): DBO EUR 7,750,000 (effect −EUR 250,000)
  7. Inflation +0.5pp: DBO EUR 8,150,000 (effect +EUR 150,000)
  8. Inflation −0.5pp: DBO EUR 7,850,000 (effect −EUR 150,000)
- **AND** each record stores the assumption, direction, and effect on both
  DBO and service cost
- **AND** the disclosure tabel includes a sensitivity table (IAS 19 §145
  format) showing all eight lines above

### Requirement: REQ-PEN-007 Disclosure table per IAS 19 §135–149

The system MUST auto-generate a complete jaarrekening disclosure table per
IAS 19 §135–149 containing: assumptions, DBO movement, asset movement,
P&L + OCI posting summary, asset category breakdown, duration, and expected
future contributions.

#### Scenario: Jaarrekening disclosure tabel 2026

- **GIVEN** completed `actuarial-valuation`, `pension-movement`, and
  `pension-asset-detail` records for 2026
- **WHEN** the disclosure tabel is generated
- **THEN** a `pension-disclosure-tabel` record is auto-created containing:
  - **Plan description**: plan name, type (DB/DC), regeling summary,
    governance (number of participants: active/deferred/retiree)
  - **Main assumptions**: discount rate 2.0%, salary growth 2.5%, mortality
    AG-2026, inflation 2.0%, retirement age 67
  - **DBO movement table**:
    - Opening DBO: EUR 8,000,000
    - Service cost: EUR 320,000
    - Past service: EUR 0
    - Net interest: EUR 30,000
    - Demographic loss: EUR (30,000)
    - Financial loss: EUR (150,000)
    - Experience gain: EUR 0
    - Closing DBO: EUR 8,170,000
  - **Asset movement table**:
    - Opening assets: EUR 6,500,000
    - Expected return: EUR 130,000 (at 2.0% rate)
    - Actual return: EUR 165,000
    - Actuariaal gain on assets: EUR 35,000
    - Employer contributions: EUR 400,000
    - Employee contributions (if any): EUR 0
    - Benefit paid: EUR (265,000)
    - Closing assets: EUR 6,835,000
  - **P&L summary**:
    - Service cost: EUR 320,000
    - Net interest cost: EUR 30,000
    - Settlements: EUR 0
    - **Total P&L**: EUR 350,000
  - **OCI summary**:
    - Demographic gain/loss: EUR (30,000)
    - Financial gain/loss: EUR (150,000)
    - Experience gain/loss: EUR 0
    - Asset return difference: EUR 35,000
    - **Total OCI**: EUR (145,000)
  - **Asset breakdown by category** (IFRS 13 levels):
    - Cash: EUR 200,000 (level 1)
    - Equities quoted: EUR 2,000,000 (level 1)
    - Bonds government: EUR 1,800,000 (level 1)
    - Bonds corporate: EUR 1,500,000 (level 2)
    - Real estate: EUR 800,000 (level 3)
    - Alternatives: EUR 300,000 (level 3)
    - Derivatives (net): EUR (765,000) (level 2)
    - **Total**: EUR 6,835,000
  - **Duration of DBO**: 15.3 years (weighted average)
  - **Expected employer contribution 2027**: EUR 380,000 (guidance based on
    actuarial assumption)
- **AND** the table is formatted as Markdown or HTML suitable for
  jaarrekening notes (callable by T2 `bookkeeping-financial-statements`
  spec)
- **AND** all numeric values are cross-checked against GL postings (service +
  interest into P&L accounts; remeasurement into OCI account) and must
  reconcile exactly

### Requirement: REQ-PEN-008 DC regeling lichte disclosure

For `planType=DC` (defined contribution) plans, the system MUST limit
disclosure to contribution amount + brief plan description per IAS 19 §53,
WITHOUT any DBO measurement, PUC calculation, or sensitivity analysis.

#### Scenario: DC-regeling met EUR 480K werkgeversbijdrage 2026

- **GIVEN** a DC-regeling with 50 employees, 8% employer contribution rate
  (standard Dutch market rate), total gross payroll EUR 6,000,000
- **AND** `planType=DC` set on the `pension-plan`
- **WHEN** the 2026 actuarial input is recorded
- **THEN** the system records only:
  - `pension-plan.planType = DC`
  - `pension-movement.employerContributions = 480000` (EUR 6M × 8%)
  - No DBO, no service cost, no net interest
- **AND** the disclosure tabel for 2026 shows only:
  - "Pensioenlasten DC-regelingen: EUR 480,000"
  - Regeling name + participant count
  - Brief description (e.g., "Collective DC arrangement with ABP,
    8% employer contribution")
  - No PUC, no sensitivity, no asset breakdown
- **AND** the system BLOCKS any attempt to enter DBO or methodology fields for DC plans (enum enforcement on `planType`)

### Requirement: REQ-PEN-009 Past service cost direct P&L on plan amendment date

The system MUST post past service cost directly to P&L on the amendment
date per IAS 19 §103, NOT spread over multiple periods, whenever a plan
amendment (regelingwijziging) occurs with material effect on the
defined-benefit obligation (e.g., raising retirement age).

#### Scenario: Pensioenleeftijd verhoogd van 67 naar 68 in 2026

- **GIVEN** a DB-regeling with retirement age 67; on 1-7-2026 the entity
  amends the regeling to raise retirement age to 68 (benefit reduction
  effective immediately for all participants, active and deferred)
- **AND** the actuaris calculates the DBO reduction: EUR 240,000 (negative
  past service cost, because shortening accrual obligation)
- **WHEN** the amendment is recorded on 1-7-2026
- **THEN** the system records a `pension-movement` partial-period record for
  the amendment:
  - `pastServiceCost: -240000` (benefit reduction = negative cost)
  - Posted immediately to personeelslasten account on 1-7-2026
  - NOT spread over 2026 H2 or future periods
- **AND** the narrative field documents: "Plan amendment: retirement age
  67→68 effective 2026-07-01; DBO reduction EUR 240K; past service benefit
  credited directly to P&L"
- **AND** the amendment is flagged in the disclosure tabel notes:
  - "Plan amendment 2026-07-01: Retirement age increased from 67 to 68,
    reducing DBO by EUR 240,000; treated as negative past service cost per
    IAS 19 §103"

### Requirement: REQ-PEN-010 HRMQ deelnemersbestand validation

The system MUST verify annually (or upon significant roster changes) that
the active-employee roster (ages, salaries, service years) in HRMQ aligns
with the `pension-plan` participant counts and assumptions used in the
actuarial valuation per REQ-PEN-010.

#### Scenario: Jaarlijkse synchronisatie deelnemersbestand 2026

- **GIVEN** a DB-plan (`pension-plan.linkedHrmqGroup = "vaste-staf-NL"`)
  with 150 active medewerkers recorded in 2025 valuation
- **WHEN** the 2026 pension cycle initiates (e.g., 1-11-2026 reminder task)
- **THEN** the system queries HRMQ `pension-administration` module for all
  active medewerkers in group "vaste-staf-NL" and extracts:
  - Count: 155 (vs. 150 in prior valuation)
  - Birth dates, salaries, service-start dates
  - In/out moves: 8 new hires, 3 departures
- **AND** the system generates a reconciliation report showing:
  - New actives: 8 medewerkers (names, birth dates, salaries)
  - Departures: 3 medewerkers
  - Salary changes: medewerkers X, Y, Z (prior salary → new salary)
- **AND** the HR-controller / finance manager approves the roster before
  the actuarial valuation is locked
- **AND** differences (new starters, departures) are communicated to the
  external actuaris to ensure the DBO recalculation is based on current
  roster
- **AND** if the roster divergence is >5%, the system logs a warning and
  requires explicit sign-off (to catch data quality issues early)

---

## Data Model

### pension-plan

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string (UUID) | Yes | Unique pension plan identifier |
| planName | string | Yes | Human-readable plan name (e.g., "DB Regeling Industrie BV") |
| planType | enum | Yes | DB / DC / CDC / hybrid |
| country | string | Yes | ISO country code (NL default for this spec) |
| regulatoryFramework | enum | Yes | Pensioenwet / BPW / vrijgesteld / IORP-II buitenland |
| funded | boolean | Yes | Whether plan has segregated assets (DB=yes, DC=typically yes) |
| provider | string | Yes | Provider name (e.g., "Pensioenfonds Industrie", "ABP", "Aegon", "eigen beheer") |
| providerLEI | string | No | Legal Entity Identifier for institutional provider |
| inceptionDate | date | Yes | Plan effective start date |
| terminationDate | date | No | Plan end date (if terminated) |
| eligibilityRules | text | No | Eligibility criteria (e.g., "All employees age 21+ with >1 year service") |
| accrualRate | decimal | No | Annual accrual rate for DB (e.g., 1.875% of pensioengrondslag) |
| pensionableSalaryDefinition | text | Yes | Definition of salary used for accrual (e.g., "gross annual salary incl. bonus") |
| retirementAge | integer | Yes | Statutory retirement age (currently 67 in NL) |
| participantCountActive | integer | Yes | Number of active employees with accruing benefits |
| participantCountDeferred | integer | Yes | Number of deferred (left service, benefits frozen) |
| participantCountRetirees | integer | Yes | Number of pensioners receiving benefits |
| linkedHrmqGroup | string | No | FK to HRMQ `pension-administration` group (optional) |
| governanceDocument | file | No | File URI to regeling, governance charter (archived in docudesk) |
| status | enum | Yes | active / paused / terminated |
| notes | text | No | Free-text notes on plan history, amendments, governance |

### actuarial-valuation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string (UUID) | Yes | Unique valuation identifier |
| plan | FK | Yes | Reference to `pension-plan` |
| valuationDate | date | Yes | As-of date (typically 31-12-yyyy) |
| actuary | string | Yes | Actuaris / firm name (e.g., "Mercer Netherlands") |
| actuaryCertificationNumber | string | Yes | Registration / certification identifier |
| methodology | enum | Yes | PUC (mandatory for DB) / DC (contribution-only) |
| dboGross | decimal | Yes | Total defined benefit obligation (EUR) |
| dboPastService | decimal | Yes | DBO for service already rendered (€) |
| dboFutureService | decimal | Yes | DBO for expected future accrual (€) |
| discountRate | decimal | Yes | Discount rate (% per annum, e.g., 2.00) |
| discountRateSource | text | Yes | Market source (e.g., "iBoxx EUR AA 15-20Y, 2026-12-31") |
| salaryGrowthAssumption | decimal | Yes | Expected annual salary increase (%, e.g., 2.5) |
| pensionGrowthAssumption | decimal | Yes | Expected annual pension increase (%, e.g., 2.0) |
| inflationAssumption | decimal | Yes | General inflation assumption (%, e.g., 2.0) |
| mortalityTable | string | Yes | Mortality table used (e.g., "AG-Prognosetafel 2026") |
| mortalityCorrection | text | No | Plan-specific adjustment (e.g., "−1 year collar per experience") |
| disabilityRate | decimal | No | Expected disability / TTD rate (%) |
| withdrawalRate | decimal | No | Expected employee withdrawal / resignation rate (%) |
| retirementAgeAssumption | integer | Yes | Assumed retirement age (e.g., 67) |
| planAssetsFairValue | decimal | Yes | Fair value of plan assets (EUR) |
| assetCeilingApplied | decimal | No | Asset ceiling adjustment (IFRIC 14) if overfunded (EUR) |
| netPensionLiability | decimal | Yes | Computed: dboGross − planAssetsFairValue + assetCeilingApplied |
| valuationReport | file | No | File URI to full actuarial report (archived in docudesk) |
| approvalStatus | enum | Yes | draft / approved / locked (locked after jaarrekening finalized) |
| approvedBy | string | No | Name/email of approver |
| approvedAt | datetime | No | Timestamp of approval |

### pension-movement

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string (UUID) | Yes | Unique movement record |
| plan | FK | Yes | Reference to `pension-plan` |
| period | string | Yes | Period identifier (e.g., "2026", "2026-H1", "2026-07") |
| dboOpening | decimal | Yes | Opening DBO (EUR) |
| serviceCostCurrent | decimal | Yes | Service cost current period (P&L) |
| pastServiceCost | decimal | Yes | Past service cost (plan amendment) (P&L) |
| gainOnSettlement | decimal | Yes | Settlement gain (lump-sum departures) (P&L) |
| netInterestCost | decimal | Yes | Net interest = discount rate × opening net pension liability (P&L) |
| actuarialLossGainDBO | decimal | Yes | Total actuarial change on DBO (OCI) |
| dueToDemographicChanges | decimal | No | Portion due to mortality/disability/withdrawal (OCI) |
| dueToFinancialChanges | decimal | No | Portion due to discount-rate / inflation change (OCI) |
| dueToExperienceAdjustments | decimal | No | Portion due to actual vs assumed experience (OCI) |
| benefitsPaid | decimal | Yes | Benefit payments (reduction to DBO & assets) (€) |
| dboClosing | decimal | Yes | Computed: dboOpening + service + pastService − benefits + interest + actuarial |
| planAssetsOpening | decimal | Yes | Opening plan assets fair value (EUR) |
| expectedReturnOnAssets | decimal | Yes | Expected return (implicit: discount rate × assets) (€) |
| actualReturnOnAssets | decimal | Yes | Actual investment return on assets (EUR) |
| actuarialGainLossAssets | decimal | Yes | Actual return − expected return (OCI) |
| employerContributions | decimal | Yes | Contributions paid by employer (EUR) |
| employeeContributions | decimal | No | Contributions paid by employees (EUR) |
| benefitsPaidFromAssets | decimal | Yes | Benefits paid from plan assets (EUR) |
| planAssetsClosing | decimal | Yes | Computed: opening assets + expected return + contributions − benefits |
| netPensionMovementPL | decimal | Yes | Computed: serviceCostCurrent + pastServiceCost + netInterestCost − settlements (total P&L) |
| netPensionMovementOCI | decimal | Yes | Computed: actuarialLossGainDBO − actuarialGainLossAssets (total OCI) |
| linkedJournalEntries | array FK | No | References to GL journal entries created from this movement |
| notes | text | No | Free-text narrative on the period (e.g., "Plan amendment 1-Jul, rate change") |

### pension-assumption-sensitivity

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string (UUID) | Yes | Unique sensitivity record |
| valuation | FK | Yes | Reference to `actuarial-valuation` |
| assumption | enum | Yes | discount-rate / salary-growth / mortality / inflation |
| direction | string | Yes | "+0.5pp" / "-0.5pp" / "+1yr" / "-1yr" (depends on assumption) |
| effectOnDBO | decimal | Yes | Impact on DBO (EUR) |
| effectOnServiceCost | decimal | Yes | Impact on service cost (EUR) |
| effectOnNetInterest | decimal | No | Impact on net interest cost (EUR) |
| notes | text | No | Free-text explanation (e.g., "Discount rate rise → lower DBO") |

### pension-asset-detail

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string (UUID) | Yes | Unique asset-detail record |
| valuation | FK | Yes | Reference to `actuarial-valuation` |
| assetCategory | enum | Yes | cash / equities-quoted / bonds-government / bonds-corporate / real-estate / alternative / derivatives |
| fairValue | decimal | Yes | Fair value of assets in category (EUR) |
| level | integer | Yes | IFRS 13 fair-value level: 1 (quoted) / 2 (observable) / 3 (unobservable) |
| notes | text | No | Free-text notes (e.g., "Real estate at indexed cost; revaluation pending") |

### pension-disclosure-tabel

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| id | string (UUID) | Yes | Unique disclosure-table record |
| plan | FK | Yes | Reference to `pension-plan` |
| valuationDate | date | Yes | Balansdatum (e.g., 31-12-2026) |
| tableContent | JSON | Yes | Full disclosure table as structured JSON / Markdown (auto-generated from movement + sensitivity + asset-detail) |
| status | enum | Yes | draft / approved / published (published when jaarrekening finalized) |
| approvedBy | string | No | Approver name/email |
| approvedAt | datetime | No | Approval timestamp |

---

## GL Account Mapping (T2 Integration)

Service cost → Personeelslasten (account 4100–4199 typical)  
Net interest → Financiële lasten (account 6600–6699 typical)  
Remeasurements → OCI account (account 8000–8999 typical; never recycled to P&L)

Details delegated to T2 GL spec.

---

## HRMQ Integration

Linked `pension-plan.linkedHrmqGroup` to HRMQ `pension-administration` group.
Annual roster validation queries active medewerkers (birth date, salary, service
start). Differences reported to HR controller before actuarial-valuation lock.

---

## References & Standards

- **IAS 19 Employee Benefits** (IASB 2011 revision)
- **RJ 271 Personeelsbeloningen** (Raad voor de Jaarverslaggeving)
- **IFRS for SMEs Section 28**
- **IFRIC 14 — IAS 19: The Limit on a Defined Benefit Asset**
- **IFRS 13 Fair Value Measurement**
- **Dutch Pensioenwet 2007**
- **Wet Toekomst Pensioenen 2023**
- **AG-Prognosetafel 2026** (Actuarieel Genootschap)
- **iBoxx € Corporates AA Index** (discount-rate proxy)

---

## Status

- Status: proposed
- Tier: T3
- Dependencies: voorzieningen-claims, general-ledger, deferred-tax, financial-statements
- Next: Design review, proof-of-concept actuarial-valuation import, HRMQ pilot
