---
status: done
---

# Spec: bookkeeping-deferred-tax

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (financial reporting)
**Depends on:** bookkeeping-general-ledger (T1), bookkeeping-vpb-mkb (T3)

## Purpose

This specification defines the requirements for bookkeeping deferred tax in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude pure backend/compliance: deferred tax logic — not browser-testable


### REQ-DT-001: Detect temporary differences per account on balansdatum

The system MUST automatically calculate, per balance-sheet account on the fiscal-period end date, the difference between the commercial (IFRS/RJ 272) carrying amount and the tax basis, capturing both the difference value and the expected reversal pattern.

#### Scenario: Depreciation difference on fixed asset

- **GIVEN** a building with commercial net book value EUR 2,400,000 (40-year straight line) and tax basis EUR 1,900,000 (minimum 10-year)
- **WHEN** the period closes on 2026-12-31
- **THEN** the system MUST create a `temporary-difference` record: `type=taxable`, `temporaryDifference=500,000`, `category=depreciation`, `reversalPattern=long-term`, `expectedReversalYear=2035` (or later depending on actual remaining useful life)
- **AND** the record MUST be linked to `Account.accountNumber` (e.g., 1200 Fixed Assets) and to `FiscalPeriod`

### REQ-DT-002: Distinguish permanent from temporary differences

The system MUST correctly classify each difference as either **temporary** (creating deferred tax) or **permanent** (affecting ETR only, not creating DTA/DTL).

#### Scenario: Dividend under deelnemingsvrijstelling is permanent

- **GIVEN** a receipt of EUR 480,000 dividend from a 10%-owned company eligible for deelnemingsvrijstelling (dividend exemption)
- **WHEN** the deferred-tax detection runs
- **THEN** NO `temporary-difference` record MUST be created
- **AND** the amount MUST appear in the `tax-rate-reconciliation.reconciliationItems[]` array with `type=permanent`, `description="Dividend exemption"`, `taxEffect=-(480000 × 25.8%)` (reduction to tax expense)

#### Scenario: Guarantee provision is deductible temporary

- **GIVEN** a guarantee provision of EUR 200,000 accrued commercially, fiscally aftrekbaar (deductible) only on actual payout
- **WHEN** the detection runs
- **THEN** a `temporary-difference` record MUST be created: `type=deductible`, `temporaryDifference=-200,000`, `category=provision`, `reversalPattern=short-term`

### REQ-DT-003: Manage tax-loss carry-forward per jurisdiction per regime

The system MUST track compensable tax losses per `jurisdiction` and per the applicable compensation regime (pre-2019 6-year, 2019–2021 transitional, 2022+ unlimited with 50%-above-threshold cap), recording origination year, utilisée amount, remaining balance, and recoverability.

#### Scenario: Loss under 2022+ regime with 50%-cap

- **GIVEN** an open tax-loss balance of EUR 3,200,000 from 2024, and a 2026 fiscal profit of EUR 1,800,000
- **WHEN** loss compensation is calculated
- **THEN** the first EUR 1,000,000 MUST offset 100%; amounts above EUR 1,000,000 offset at 50% (EUR 400,000 out of EUR 800,000 available)
- **AND** total offset = EUR 1,400,000; taxable income 2026 = EUR 400,000
- **AND** the `tax-loss-carry-forward` record for 2024-origin MUST be updated: `utilisedAmount += 1,400,000`, `remainingAmount = 1,800,000`
- **AND** effective Vpb = EUR 76,000 (EUR 200,000 × 19% + EUR 200,000 × 25.8%)

#### Scenario: Transitional-regime loss from 2020

- **GIVEN** a loss from 2020 (overgangsregels periode)
- **WHEN** the regime is applied
- **THEN** the system MUST read the regime-specific rules (likely a hybrid of old 6-year and new 50%-cap) from `applicableRegime=2019-2021-transition`

### REQ-DT-004: Assess recoverability of DTA on loss carry-forwards

The system MUST require, for any deferred-tax asset recognized on a loss carry-forward, an explicit recoverability assessment: a documented projection of future taxable profit and a stated percentage of the loss-generated DTA that is recognized.

#### Scenario: DTA recognized at 60% based on projection

- **GIVEN** an open loss of EUR 5,000,000 and a multi-year budget forecast predicting EUR 3,000,000 cumulative taxable profit over 5 years
- **WHEN** the recoverability assessment is performed
- **THEN** the system MUST permit recognition of DTA at maximum 60% (EUR 3,000,000 / EUR 5,000,000)
- **AND** DTA recognized = EUR 3,000,000 × 25.8% = EUR 774,000
- **AND** a `dtaRecoverabilityRationale` text field MUST be populated (e.g., "Supported by 5-year projection; EUR 3M cumulative profit assumed")
- **AND** the `linkedProjections[]` array MUST reference the forecast records (from `bookkeeping-budget-multi-year`)
- **AND** the 40% unrecognized portion MUST appear in financial-statement notes as "Unrecognised deferred tax asset on losses"

### REQ-DT-005: Apply enacted tax-rate changes to expected-reversal-year differences

The system MUST, when a new tax rate is enacted by parliament (even with a future effective date), re-measure all deferred-tax positions expected to reverse on or after the effective date at the new rate, recognising the adjustment in the current-period `deferred-tax-movement`.

#### Scenario: Announced tariff increase to 27% effective 2028

- **GIVEN** a total DTL of EUR 850,000 measured at current rate 25.8%
- **AND** a Belastingplan enacted in 2026 announcing 27% effective 2028-01-01
- **WHEN** balansdatum 2026 closes and `FiscalPeriod.enactedTaxRates` is updated with `{jurisdiction: "NL", rate: 0.27, effectiveDate: "2028-01-01"}`
- **THEN** all temporary differences with `expectedReversalYear >= 2028` MUST be re-measured at 27%
- **AND** the change MUST be recorded as `rateChangeAdjustment` in the `deferred-tax-movement` record for the affected categories
- **AND** the P&L effect MUST be separately disclosed in the ETR reconciliation

### REQ-DT-006: Calculate and disclose effective tax rate reconciliation

The system MUST produce a complete tax-rate reconciliation (`tax-rate-reconciliation` record) per period per jurisdiction, starting from statutory rate × profit-before-tax, adjusting for permanent differences, temporary differences (opening and closing), rate changes, and prior-year items, arriving at effective tax expense.

#### Scenario: ETR reconciliation for 2026 annual report

- **GIVEN** profit before tax EUR 4,200,000 and effective Vpb expense EUR 950,000
- **WHEN** the balansdatum close completes
- **THEN** the system MUST produce a `tax-rate-reconciliation` record:
  - `profitBeforeTax = 4200000`
  - `statutoryRate = 25.8%` (blended 19% + 25.8% per bracket)
  - `statutoryTaxExpense = 1,083,600`
  - `reconciliationItems[] = [`
    - `{ description: "Dividend exemption", type: "permanent", taxEffect: -124,000 }`
    - `{ description: "Non-deductible gifts", type: "permanent", taxEffect: +12,000 }`
    - `{ description: "Opening deferred tax (origination reversal)", type: "temporary", taxEffect: +75,000 }`
    - `{ description: "Rate change on deferred liabilities", type: "rate-change", taxEffect: +8,000 }`
    - `]`
  - `effectiveTaxExpense = 950,000`
  - `effectiveTaxRate = 22.6%`
  - `disclosureNarrative = "Free-form or structured text for jaarrekening disclosure"`
- **AND** this record MUST be consumable by `bookkeeping-financial-statements` for automated disclosure in the notes to the balance sheet per RJ 272 / IFRS

### REQ-DT-007: Maintain separate per-jurisdiction tracking with optional consolidation

The system MUST track all deferred-tax positions (temporary differences, loss carry-forwards, ETR reconciliations) per jurisdiction (NL, DE, BE, etc.), and MUST NOT automatically salde positions across jurisdictions unless the entity is a fiscal unity or has explicit consolidation instructions.

#### Scenario: German subsidiary with separate Vpb tariff

- **GIVEN** a NL parent and a German Betriebsstätte (fixed establishment) with 30% Körperschaftsteuer
- **WHEN** deferred-tax calculations run
- **THEN** NL temporary differences MUST be measured at 25.8%
- **AND** DE temporary differences MUST be measured at 30%
- **AND** separate `temporary-difference` sets MUST exist for each jurisdiction
- **AND** separate `tax-loss-carry-forward` records MUST exist with `jurisdiction=NL` and `jurisdiction=DE`
- **AND** separate `tax-rate-reconciliation` records MUST exist per jurisdiction
- **AND** the consolidated group balance sheet (from `bookkeeping-consolidation-commercial`) MUST sum the DTA/DTL per jurisdiction without cross-jurisdiction netting

### REQ-DT-008: Salde DTA and DTL on balance sheet per IAS 12 §71–78

The system MUST, on the presented balance sheet within each jurisdiction, salde (net) deferred-tax assets and liabilities only where (a) the entity has a current legal right to offset, and (b) the entity intends to settle net. The netting decision MUST be recorded as `presentationOnBalanceSheet: gross | net`.

#### Scenario: Netting DTA and DTL within NL fiscal unity

- **GIVEN** a fiscal unity for Vpb with DTA EUR 320,000 and DTL EUR 480,000 (both NL jurisdiction)
- **WHEN** balance-sheet presentation is prepared
- **THEN** the system MUST permit a choice: gross presentation (DTA EUR 320K asset + DTL EUR 480K liability) or net (EUR 160K liability)
- **AND** the choice MUST be recorded in the `tax-provision.presentationOnBalanceSheet` field
- **AND** the gross components MUST always be visible in financial-statement notes (per RJ 272 / IFRS disclosure requirements)

### REQ-DT-009: Maintain deferred-tax roll-forward with complete movement detail

The system MUST, per fiscal-period end, produce a `deferred-tax-movement` record per jurisdiction per temporary-difference category, showing opening balance, originations, reversals, rate effects, business-combination gains, FX adjustments, and closing balance.

#### Scenario: MVA depreciation roll-forward

- **GIVEN** opening DTL on MVA category EUR 380,000 per 2026-01-01
- **WHEN** the year close runs
- **THEN** a `deferred-tax-movement` record MUST be created:
  - `category = depreciation`
  - `openingBalance = 380,000`
  - `originatedInPeriod = 95,000` (new difference 2026)
  - `reversedInPeriod = -42,000` (old differences reversing)
  - `rateChangeAdjustment = 8,000` (rate change effect)
  - `closingBalance = 441,000`
  - `recognisedInPL = 95,000 - 42,000 + 8,000 = 61,000` (income-statement effect)
- **AND** the P&L impact EUR 61,000 MUST be included in total `tax-provision` expense (current + deferred)
- **AND** `linkedJournalEntries[]` MUST reference the actual GL journal entries that posted the deferred tax expense

### REQ-DT-010: Reconcile deferred-tax positions with Vpb return

The system MUST produce a reconciliation between the total tax expense reported in the financial statements (current + deferred) and the amount declared in the Vpb-aangifte, accounting for any differences in timing or adjustments that explain the gap.

#### Scenario: Vpb return vs. jaarrekening 2026

- **GIVEN** a Vpb-aangifte declaring Vpb payable EUR 540,000 (current tax)
- **AND** a deferred-tax movement schedule showing EUR +410,000 (DTL increase in the period)
- **WHEN** the reconciliation is performed
- **THEN** total P&L tax expense MUST be: EUR 540,000 + EUR 410,000 = EUR 950,000
- **AND** this MUST match the `effectiveTaxExpense` from `tax-rate-reconciliation` (REQ-DT-006)
- **AND** the EUR 410,000 deferred-tax movement MUST be the mutation on the balance-sheet `tax-provision.netDtaDtlPosition`
- **AND** `tax-provision.linkedVpbReturn` MUST reference the Vpb record for audit-trail linkage
