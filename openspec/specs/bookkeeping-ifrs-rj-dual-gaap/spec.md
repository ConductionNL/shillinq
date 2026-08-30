---
status: done
---

# Spec: bookkeeping-ifrs-rj-dual-gaap

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (financial reporting & consolidation)
**Depends on:** `../bookkeeping-general-ledger/spec.md` (T1 GL materialisation),
`../bookkeeping-financial-statements/spec.md` (RJ + IFRS statement generation),
`../bookkeeping-consolidation/spec.md` (multi-entity framework conversion)

## Purpose

This specification defines the requirements for bookkeeping ifrs rj dual gaap in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: IFRS/RJ dual GAAP pages not yet implemented


### REQ-DGAAP-001: Dual GAAP reporting SHALL be declared via six register schemas and parallel GL materialisation

Dual GAAP (IFRS and RJ simultaneously) MUST be expressed as six new registers in
`lib/Settings/shillinq_register.json` per ADR-024:

- `AccountingFramework` — standard identifier (IFRS-EU, NL-GAAP-RJ), version, effective date,
  regulator, base currency, statement templates.
- `ChartOfAccountsMapping` — source (RJ) account, target (IFRS) accounts, allocation rule
  (percentage, formula, ratio-driver), effective date range, audit trail.
- `DualTransaction` — base GL transaction, RJ journal entries, IFRS journal entries,
  divergence classification (permanent, temporary, reclassification), reason code
  (LEASE_IFRS16, PENSION_IAS19, ECL_IFRS9, REVENUE_IFRS15, IMPAIRMENT_IAS36, etc.).
- `ReconciliationBridge` — period, from_framework (RJ), to_framework (IFRS), opening balance,
  adjustments per standard, tax effect, approver signoff.
- `StandardSpecificCalculation` — standard code (IFRS-16, IAS-19, IFRS-9, etc.),
  calculation method, inputs, outputs, revaluation frequency, actuary signoff (IAS 19),
  audit evidence URI.
- `FrameworkElection` — per legal_entity, primary framework choice, comply-or-explain
  motivation, RJ variant (RJ-onverkort, RJk, IFRS-volledig), AVA-besluit reference.

Every GL posting MUST materialise to BOTH RJ-ledger and IFRS-ledger simultaneously via
T1's GL-materialisation extension. `GLLine.accountingFramework` enum (`rj` or `ifrs`)
and `GLLine.frameworkJournalEntry` FK link the dual entries.

#### Scenario: Reviewer confirms no parallel dual-ledger table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `dual_transaction`, `dual_ledger_*`,
  or `parallel_posting_*`
- **THEN** no such classes SHALL exist (dual posting is declarative per T1 extension).

#### Scenario: GL posting materialises to both ledgers

- **GIVEN** an invoice for a 5-year lease (€24,300 purchase, €450/month payment)
- **WHEN** the GL posting is saved
- **THEN** the RJ-ledger MUST show (Account "1530 Operationeel geleasede activa" €24,300 /
  Account "4120 Autokosten" €450 / Crediteuren €24,750 per month); **AND** the IFRS-ledger
  MUST show (Account "1531 Right-of-Use asset" €24,300 / Account "2531 Lease liability current"
  €9,744 / Account "2532 Lease liability non-current" €14,556) with `DualTransaction.divergence_reason_code`
  = "LEASE_IFRS16".

### REQ-DGAAP-002: ChartOfAccountsMapping SHALL support one-to-many cardinality, allocation rules, and validation

The `ChartOfAccountsMapping` register MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `sourceAccount` | string | Yes | RJ account number (e.g., "1530") |
| `targetAccounts` | array of string | Yes | IFRS account numbers (e.g., ["1531", "2531", "2532"]) |
| `mappingType` | enum | Yes | one-to-one, one-to-many, many-to-one, recharacterization |
| `allocationRule` | object | Yes | `{type: "percentage" \| "formula" \| "ratio_driver", definition: string}` |
| `effectiveFrom` | date | Yes | When mapping becomes active |
| `effectiveTo` | date | No | When mapping expires |
| `approver` | string | Yes | Group-accountant or controller role |
| `auditTrail` | object | Automatic | Change history per OR audit-trail extension |

A group-accountant configuring COA-mapping MUST upload test data (existing RJ transactions),
run a test-bridge calculation, and verify that ≥95% of historical transactions can be
re-categorised to IFRS accounts OR provide explicit exception documentation. Activation
MUST be blocked until the test passes or exceptions are approved.

#### Scenario: One-to-many mapping with allocation formula

- **GIVEN** a group-accountant configuring lease mapping (RJ "1530" → IFRS "1531" + "2531" + "2532")
- **WHEN** they define `allocationRule: {type: "formula", definition: "ROU_ASSET = PV(future_payments @ IBR); LIABILITY = same"}`
- **THEN** the system MUST accept the mapping; **AND** test-data validation MUST apply
  the formula to historical lease invoices; **AND** the mapping MUST activate only if
  ≥95% of test transactions produce valid IFRS splits.

#### Scenario: Test-data validation blocks unsafe mapping

- **GIVEN** a COA mapping that would split 20% of historical RJ transactions into unmapped IFRS accounts
- **WHEN** the group-accountant runs validation
- **THEN** the system MUST reject activation with a list of unmatched transactions and their
  account numbers; **AND** require either (a) exception documentation or (b) mapping refinement.

### REQ-DGAAP-003: DualTransaction SHALL classify divergence reason and permanence

Every GL transaction booked under RJ MUST automatically spawn a `DualTransaction` record
capturing divergence classification. The `divergence_reason_code` enum MUST include:

- `LEASE_IFRS16` — IFRS 16 ROU asset vs. RJ 292 operating-lease treatment
- `PENSION_IAS19` — IAS 19 projected-unit-credit obligation vs. RJ 271 defined-contribution
- `ECL_IFRS9` — IFRS 9 expected-credit-loss vs. RJ 290 incurred-loss model
- `REVENUE_IFRS15` — IFRS 15 five-step model vs. RJ 270 point-of-delivery
- `IMPAIRMENT_IAS36` — IAS 36 impairment trigger vs. RJ 121 impairment recognition
- `BORROWING_COST_IAS23` — IAS 23 capitalisation of borrowing costs
- `DEFERRED_TAX_IAS12` — IAS 12 deferred tax on temporary differences
- `BUSINESS_COMBINATION_IFRS3` — IFRS 3 goodwill and acquisition accounting
- `OTHER` — other standard-specific divergence

The `divergence_classification` enum MUST be one of:

- `temporary` — creates a deferred-tax item; reverses over time
- `permanent` — no tax effect; RJ basis retained
- `reclassification` — same amount, different GL account; P&L-neutral

On every GL posting, the materialisation engine (T1 extension) MUST examine the accounts
and posting date; if a standard-specific rule fires (e.g., lease-account posting on lease-effective-date),
auto-populate `DualTransaction.divergence_reason_code`. Group-accountant MAY override with
audit-trail reason.

#### Scenario: Auto-classification of lease divergence

- **GIVEN** a GL posting to RJ account "1530 Operationeel geleasede activa" on
  `2026-04-15` (lease commencement date)
- **WHEN** materialisation fires
- **THEN** `DualTransaction.divergence_reason_code` MUST auto-populate as "LEASE_IFRS16";
  `divergence_classification` MUST be "temporary"; divergence amount MUST be the ROU-asset value.

#### Scenario: Manual override with audit trail

- **GIVEN** a `DualTransaction` with auto-classified "OTHER"
- **WHEN** a group-accountant overrides to "REVENUE_IFRS15" with reason "contract contains
  variable consideration"
- **THEN** the override MUST be saved; **AND** the audit trail MUST record the change with
  timestamp and actor.

### REQ-DGAAP-004: IAS 19 pension calculations SHALL import actuarial reports and materialise adjustments

An underneming with a defined-benefit-regeling MUST support import of actuarial rapportages
(XBRL-NT or PDF with OCR extraction) to populate IAS 19 calculations. On jaarafsluiting:

1. The system MUST import the actuariële rapportage with inputs (discount rate, demographic
   assumptions, plan-asset returns, service cost, remeasurement gains/losses).
2. Under RJ 271, the system MUST book the agreed-upon pension-provision (toegezegde-bijdrageregeling
   if applicable) as a liability/expense.
3. Under IAS 19, the system MUST book the projected-unit-credit obligation (actuariële verplichting)
   with service cost in P&L and remeasurement gains/losses in OCI.
4. The `ReconciliationBridge` MUST show the delta: RJ provision vs. IAS 19 obligation, with
   explicit reconciliation of discount-rate impact, demographic assumptions, and plan-asset returns.

#### Scenario: Actuarial report import and IAS 19 booking

- **GIVEN** an underneming with 234 actieve deelnemers and a defined-benefit-regeling
- **WHEN** the jaarafsluiting process imports the actuariële rapportage
- **THEN** the system MUST extract: discount rate (e.g., 2.5%), demographic tables, plan-asset
  return, current service cost (€180k), remeasurement gain on actuarial assumption changes (€50k);
  **AND** materialise RJ booking (liability €500k) **AND** IAS 19 booking (obligation €650k, service
  cost €180k in P&L, remeasurement €50k in OCI); **AND** generate reconciliation line with
  €150k delta explained as discount-rate impact €80k + demographic assumption revision €70k.

### REQ-DGAAP-005: IFRS 9 expected credit loss vs. RJ 290 incurred loss SHALL be calculated separately

On monthly maandafsluiting, the system MUST calculate IFRS 9 ECL and RJ 290 incurred-loss
provisioning in parallel:

**RJ 290 (incurred loss)**:
- Base on actual aging of debtors (0–30, 30–60, 60–90, 90+ days overdue)
- Apply historical loss ratios per aging bucket (e.g., 0–30 days: 0.5%, 30–60 days: 2%, 60–90: 10%, 90+: 50%)

**IFRS 9 (expected credit loss)**:
- Stage 1 (0–30 days): 12-month ECL using historical default rates
- Stage 2 (30–90 days): Lifetime ECL; apply credit-rating downgrade overlay
- Stage 3 (90+): Lifetime ECL; assume default within 12 months
- Forward-looking macro-overlay (e.g., sector GDP growth, unemployment trend)

The `ReconciliationBridge` MUST show the delta (RJ 290 provision vs. IFRS 9 ECL) per
debtor segment, with underlying standard-specific calculations stored in `StandardSpecificCalculation`.

#### Scenario: ECL staging and macro-overlay calculation

- **GIVEN** a debiteurenportefeuille of €4.2M with diverse aging buckets
- **WHEN** the monthly maandafsluiting runs
- **THEN** RJ 290 MUST calculate (0–30 days €0.5M @ 0.5% = €2.5k; 30–60 days €1.2M @ 2% = €24k;
  60–90 days €1.0M @ 10% = €100k; 90+ days €1.5M @ 50% = €750k; total €876.5k); **AND**
  IFRS 9 MUST calculate (Stage 1 €1.5M @ 1% 12-month ECL = €15k; Stage 2 €1.5M @ 8% lifetime ECL = €120k;
  Stage 3 €1.2M @ 100% = €1.2M; total €1.335M) with macro-overlay (±5% GDP growth adjustment);
  **AND** bridge MUST show €459k delta with explanation per segment.

### REQ-DGAAP-006: Temporary vs. permanent divergences SHALL be classified and tracked for IAS 12 deferred tax

On every GL posting creating a divergence, the system MUST classify whether the divergence
is temporary (deferred-tax item under IAS 12) or permanent (no tax effect). The `DualTransaction.divergence_classification`
enum MUST support:

- `temporary` — generates deferred-tax asset/liability; reverses over time (e.g., lease ROU
  depreciation differences, pension obligation discount-rate changes)
- `permanent` — no IAS 12 impact (e.g., goodwill impairment on acquisition, intangible exempt items)
- `reclassification` — same GL amount, different account; no P&L impact; no tax effect

For each temporary divergence, the system MUST calculate deferred-tax impact: amount ×
(statutory tax rate per jurisdiction). Tax rates MAY be per-entity (consolidated parent
25%, Dutch subsidiary 19%, EU subsidiary 21%). The `ReconciliationBridge` MUST roll up
total deferred-tax impact.

#### Scenario: Temporary divergence with deferred-tax calculation

- **GIVEN** a lease ROU-asset divergence of €24,300 booked in April 2026
- **WHEN** classified as `temporary`
- **THEN** the system MUST calculate deferred-tax asset (€24,300 × 25% Dutch tax rate =
  €6,075) **AND** record in `StandardSpecificCalculation` with method "IAS-12-deferred-tax";
  **AND** include in the reconciliation bridge as a separate line "Deferred tax on lease ROU divergence:
  €6,075 asset".

### REQ-DGAAP-007: Version management and effective dates SHALL support retrospective and modified-retrospective application

The system SHALL satisfy this requirement: Version management and effective dates SHALL support retrospective and modified-retrospective application.

When an accounting standard changes (e.g., RJ 271 revised effective 2027-01-01 with new
VPL-regeling treatment), the group-accountant MUST be able to:

1. Create a new `AccountingFramework` version with `effectiveTo` date on old version.
2. Configure the new standard rules (effective 2027-01-01).
3. Choose retrospective application (recalculate all prior-year balances and adjust
   opening retained earnings) OR modified retrospective (apply new rules prospectively,
   record cumulative adjustment in 2026 earnings).

The system MUST auto-generate the required toelichting (footnote) explaining the impact
of the stelselwijziging.

#### Scenario: RJ 271 revision with modified-retrospective application

- **GIVEN** RJ 271 pension guidance revised effective 2027-01-01
- **WHEN** the group-accountant configures new rules and selects "modified retrospective"
- **THEN** the system MUST apply the new rules to 2027-01-01 forward; **AND** calculate
  cumulative impact on 2026 pension-provision balance (e.g., €50k increase); **AND** auto-generate
  toelichting paragraph: "On 1 January 2027, RJ 271 was revised to clarify VPL-regeling
  treatment, resulting in a €50k adjustment to opening retained earnings."

### REQ-DGAAP-008: Drill-down from consolidated IFRS number to source transaction SHALL be one-click navigable

Every line in the `ReconciliationBridge` (e.g., "IAS 19 service cost €234k") MUST be
clickable and drill down to:

1. **Reconciliation Bridge detail** — showing the standard code, period, amount, description.
2. **StandardSpecificCalculation** — exposing inputs (actuariële rapportage parameters),
   outputs (PUC liability, service cost), and audit evidence (linked PDF via docudesk FK).
3. **Underlying GL transactions** — both RJ and IFRS journal entries materialised.
4. **Audit trail** — creation date, actor, approver signoff (actuary for IAS 19).

All navigation MUST occur within OR's relation-engine UI; no export to Excel required
for audit evidence review.

#### Scenario: External auditor drill-down on IAS 19 service cost

- **GIVEN** a consolidated IFRS P&L with "IAS 19 service cost €234k"
- **WHEN** the external auditor clicks the link in the reconciliation toelichting
- **THEN** the system MUST display (1) bridge-line detail, (2) `StandardSpecificCalculation`
  with PUC inputs, (3) GL entries (RJ vs. IFRS), (4) audit trail with actuary sign-off link;
  **AND** all documents MUST be available for download (actuariële rapportage, calculation
  supporting sheets).

### REQ-DGAAP-009: Multi-entity consolidation with mixed frameworks SHALL automatically convert RJ subsidiaries to IFRS

The system SHALL satisfy this requirement: Multi-entity consolidation with mixed frameworks SHALL automatically convert RJ subsidiaries to IFRS.

When a Nederlandse holding consolideert 7 dochters (3 IFRS-rapporterend, 4 RJ-rapporterend),
the system MUST:

1. Each RJ-reporting subsidiary applies its own `FrameworkElection` (primary framework RJ).
2. Consolidation process FIRST converts each RJ subsidiary's ledger to IFRS via the
   subsidiary's `ReconciliationBridge` (RJ → IFRS) **OR** the subsidiary's parallel-ledger
   IFRS entries (if dual-posted).
3. Parent consolidation logic then eliminates intercompany transactions using IFRS numbers.
4. Consolidation ledger MUST trace per-dochter the RJ-to-IFRS conversion step + consolidation
   elimination step, so auditor can inspect both.

#### Scenario: Multi-entity consolidation with RJ-to-IFRS conversion

- **GIVEN** parent (IFRS-reporting) + dochter A (RJ-reporting, RJ equity €500k) + dochter B (IFRS-reporting, equity €300k)
- **WHEN** consolidation is run
- **THEN** system MUST convert dochter A's €500k RJ equity to IFRS via its ReconciliationBridge
  (e.g., +€50k lease ROU, -€30k pension, +€20k ECL = €540k IFRS equity); **AND** consolidate
  parent + dochter A (IFRS €540k) + dochter B (IFRS €300k) = €840k group equity (before eliminations);
  **AND** create consolidation blad showing per-dochter RJ-IFRS conversion + final eliminations.

### REQ-DGAAP-010: Comply-or-explain and framework-election documentation SHALL enforce scope and auto-warn on criteria overflow

A legal entity administrator setting up framework choice for a new BV MUST:

1. Declare primary framework (RJ-onverkort, RJk, IFRS-EU).
2. Provide comply-or-explain motivation (e.g., "kleine-rechtspersoon per BW2 art 2:396").
3. Record balanstotaal, netto-omzet, and gemiddeld aantal werknemers (size criteria).
4. Reference AVA-besluit (AVA board decision) if applicable.

The system MUST auto-warn (annually on year-end) if size criteria breach the threshold
for two consecutive boekjaren (e.g., balanstotaal > €24M while claiming small-entity status);
warn but allow (controller MAY opt to continue on smaller framework per strategy). Warn before
publishing jaarrekening if declared framework does not match measured criteria.

#### Scenario: Framework-election criteria tracking

- **GIVEN** a BV declared as RJk (small entity) based on balanstotaal €8M in 2025
- **WHEN** 2026 fiscal year closes with balanstotaal €26M (breaching the €24M small-entity threshold)
- **THEN** system MUST warn: "This entity exceeds small-entity criteria (€26M > €24M). Consider
  reclassifying to RJ-onverkort or provide comply-or-explain documentation"; **AND** block
  publication of jaarrekening unless administrator acknowledges the warning.

#### Scenario: Comply-or-explain documentation on record

- **GIVEN** an entity electing RJk despite balanstotaal €25M (just above threshold)
- **WHEN** administrator provides comply-or-explain motivation (e.g., "Board decision to
  maintain single SME framework for operational simplicity; audit committee approved")
- **THEN** the system MUST accept the override; **AND** store the motivation in `FrameworkElection`
  with timestamp and actor; **AND** allow publication with a note in audit trail that the
  override was documented.

## Standards & References

- **IFRS-EU**: IFRS 9 (Financial Instruments), IFRS 15 (Revenue from Contracts with Customers),
  IFRS 16 (Leases), IAS 12 (Income Taxes), IAS 19 (Employee Benefits), IAS 36 (Impairment of Assets)
- **Nederlandse Richtlijnen voor de Jaarverslaggeving (RJ)**:
  RJ 270 (Revenue recognition), RJ 271 (Employee benefits / pensions),
  RJ 290 (Financial instruments), RJ 292 (Lease agreements), RJk (Small entities)
- **BW2 Titel 9** — art 2:362 (true and fair view), art 2:384 (valuation),
  art 2:396 (small entity), art 2:397 (medium entity)
- **EU-Verordening 1606/2002** — IAS Regulation mandating IFRS for listed groups
- **Dutch Domain Standards**: RJ 100 series (general), RJ 121 (impairment), RJ 277 (deferred tax)

## Cross-app dependencies

- **shillinq:bookkeeping-general-ledger** — extends T1 GL materialisation to support
  dual-posting per framework; requires multi-ledger support per transaction.
- **shillinq:bookkeeping-financial-statements** — consumes both RJ and IFRS ledgers
  to generate side-by-side jaarrekening (model A/B/C per BW2) + IFRS statements (IAS 1 presentation)
  with reconciliation-bridge toelichting.
- **shillinq:bookkeeping-consolidation** — uses framework-election and ReconciliationBridge
  for multi-entity RJ-to-IFRS conversion + elimination logic.
- **shillinq:bookkeeping-tax-deferred** — consumes temporary-divergence classification
  for IAS 12 deferred-tax calculation (separate capability spec).
- **docudesk** — stores actuariële rapportages, lease contracts, ECL models, impairment
  analyses as audit evidence with retention per NV COS standard.
