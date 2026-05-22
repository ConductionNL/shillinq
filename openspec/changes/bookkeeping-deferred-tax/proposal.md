# Proposal: bookkeeping-deferred-tax

`kind: spec` — declares five new tax register schemas plus extensions on Account and Period.

## Summary

Introduce the **deferred tax assets, deferred tax liabilities, and tax provisions** capability for Shillinq, serving MKB+ and large enterprises reporting under **IAS 12 Income Taxes** or Dutch equivalent **RJ 272 Belastingen naar de winst**. This change declares five new registers (`temporary-difference`, `tax-loss-carry-forward`, `tax-rate-reconciliation`, `deferred-tax-movement`, `tax-provision`), extends T1 `Account` with `taxBasisDifferenceCategory` and `FiscalPeriod` with `enactedTaxRates` metadata, and defines the complete workflow for:

- Automatic detection of temporary differences per balance-sheet account
- Compensation regime and recoverability assessment for tax-loss carry-forwards
- ETR (effective tax rate) reconciliation for financial-statement disclosure
- Per-jurisdiction tracking (NL, DE, BE, etc.) with consolidation support
- Salary/DTL presentation and netting per IAS 12 §71–78

Deferred tax is one of the highest-risk and highest-effort audit topics in annual reporting; automating the detection, calculation, and reconciliation from declarative GL patterns reduces error and professional-service cost while improving auditability.

## Motivation

### Why this, why now

For a Dutch midmarket enterprise with material fixed assets, provisions, fiscal unity, or loss-carryforward, the commercial tax expense (shown in P&L) differs materially from current tax payable (from the Vpb return) due to:

1. **Timing differences** — depreciation, provisions, guarantees, lease accounting — where the fiscal and commercial booked amounts diverge
2. **Loss-carryforward** — pre-2022 regime (6-year), 2019–2021 transition, 2022+ (unlimited with 50% above-threshold cap) — each with different recoverability rules
3. **Permanent differences** — deelnemingsvrijstelling (dividend exemption), non-deductible gifts, foreign-tax-credit — affecting ETR but not creating DTA/DTL

Without automation, a mid-market controller or fiscal specialist spends 10–20 hours per year reconciling these manually in Excel, rebuilding the same logic each year, and exposing the jaarrekening to auditor adjustment. This spec eliminates that.

### Market opportunity

Shillinq's TAM in NL mid-market includes ~2000 enterprises with 50–5000 FTE that are either obligated to file RJ 272 or IFRS. Of these, ~1400 entities have material exposure (fixed assets, provisions, fiscal unity, or losses). Each is a 15–30-hour annual advisory engagement at EUR 250–400/hour (EUR 3,750–12,000 per client per year). By baking deferred tax into Shillinq, we capture 30–40% of that effort, freeing advisory hours for higher-value work and improving client SaaS stickiness.

## Affected Projects

- [x] **shillinq** — adds 5 new schemas to `lib/Settings/shillinq_register.json`, additive extensions to T1 `Account` and `FiscalPeriod`, declarative `x-openregister-calculations` output for tax-rate-reconciliation per REQ-DT-006.
- [ ] **openregister** — no changes; this change consumes existing `x-openregister-relations`, `x-openregister-calculations`, and relation validation on custom dimension maps.
- [ ] **bookkeeping-financial-statements** — downstream consumer; will ingest the `tax-rate-reconciliation` and `tax-provision` records for balance-sheet presentation and ETR disclosure.
- [ ] **bookkeeping-vpb-mkb** — supplies current-tax amounts and loss-compensation regime metadata; linked via `linkedVpbReturn` FK.

## Scope

### In Scope

- **Five new register schemas** in the `tax` register: `temporary-difference`, `tax-loss-carry-forward`, `tax-rate-reconciliation`, `deferred-tax-movement`, `tax-provision`.
- **Additive extensions** on T1 `Account` (`taxBasisDifferenceCategory` enum for quick marking) and `FiscalPeriod` (`enactedTaxRates` object for rate-change handling).
- **REQ-DT-001 through REQ-DT-010** — detecting temporary differences, distinguishing permanent vs. temporary, compensating losses under regime rules, recoverability assessment, rate-change adjustment, ETR reconciliation, per-jurisdiction separation, DTA/DTL netting per IAS 12, roll-forward detail, and Vpb-return reconciliation.
- **Declarative calculations** — `tax-rate-reconciliation` produced as `x-openregister-calculations` output (per ADR-031), not a PHP report service.
- **Audit trail** — all deferred-tax records inherit OR's audit-trail-immutable by schema.
- **Seed data** — none required; temporary-difference records accumulate from GL on balansdatum via calculator service.

### Out of Scope

- **Tax return filing** — this spec feeds into jaarrekening (financial statements) disclosure; actual Vpb/Box-2 filing remains in `bookkeeping-vpb-mkb`.
- **Real-time tax-loss projection** — the `linkedProjections` FK on `tax-loss-carry-forward` pre-positions for future link to `bookkeeping-budget-multi-year`, but projection logic itself is out of scope.
- **Bespoke tax-advisor workflows** — scenario-play, what-if modeling, or multi-variant reconciliation are future enhancements; this spec is single-fact-set.
- **Transfer pricing** — inter-company deferred-tax adjustments per OECD guidelines are not in scope; captured by `IntercompanyTransaction` links only.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-deferred-tax`** — declares the five `tax` register schemas with required fields per IAS 12 and RJ 272, defines nine REQ-DT-* requirements using RFC 2119, and specifies scenarios (GIVEN/WHEN/THEN) for each requirement covering normal paths and edge cases (rate changes, loss regimes, recoverability, multi-jurisdiction).

The spec follows the conduction-schema format. Each requirement is prefixed `REQ-DT-*` for traceability.

## New Dependencies

None. This change consumes:

- T1 `bookkeeping-general-ledger` (`Account`, `FiscalPeriod`, `GLLine` balances)
- T3 `bookkeeping-vpb-mkb` (current tax, loss-compensation regime)
- Optional: `bookkeeping-budget-multi-year` (for recoverability projections via `linkedProjections`)

All are existing shillinq capabilities.

## Impact

- **`lib/Settings/shillinq_register.json`** — adds 5 schemas (`temporary-difference`, `tax-loss-carry-forward`, `tax-rate-reconciliation`, `deferred-tax-movement`, `tax-provision`); additive patches on T1 `Account` and `FiscalPeriod`.
- **`lib/Services/TaxCalculationService.php`** — service wrapping declarative deferred-tax calculation (detection, loss-compensation logic, rate-change adjustment, recoverability check) invoked from GL close process.
- **`src/manifest.json`** — adds navigation entry `Accounting > Taxes > Deferred Taxes` surfacing a detail page for `tax-provision` and linked index of `temporary-difference` records.
- **`openspec/architecture/adr-000-data-model.md`** — adds entity reconciliation notes for the five new `tax` register schemas and extensions on `Account` / `FiscalPeriod`.

## Cross-Project Dependencies

- **T1 `bookkeeping-general-ledger`** — reads `Account` balances, `Account.taxBasisDifferenceCategory` hints, `FiscalPeriod.enactedTaxRates` for rate changes.
- **T3 `bookkeeping-vpb-mkb`** — reads current-tax payable/prepaid, loss-compensation regime metadata (pre-2019, 2019–2021 transition, 2022+).
- **T3 `bookkeeping-financial-statements`** — consumes `tax-rate-reconciliation` (ETR disclosure) and `tax-provision` (balance-sheet presentation).
- **T4 `bookkeeping-consolidation-commercial`** — reads per-entity deferred-tax positions (jurisdiction-segregated) and applies per-consolidation rules (no inter-company saldering).
- **Optional T4 `bookkeeping-budget-multi-year`** — linked by `linkedProjections` for recoverability projections.

## Risks

### Risk 1: Fiscal-regime complexity (pre-2019 / transition / 2022+) leads to implementation defect

**Severity**: High

**Mitigation**: REQ-DT-003 exhaustively defines the three regimes (6-year expire, overgangsregime rules, unlimited 50%-cap). Spec-review gate includes a fiscal-technical peer (belastingadviseur) who audits the scenario logic against Wet Vpb articles 20, 20a. Implementation cycle includes 100% scenario coverage in PHPUnit (test each regime path, each loss-origin-year scenario).

### Risk 2: Recoverability assessment is subjective; two controllers may disagree on 60% vs. 70% DTA recognition

**Severity**: Medium

**Mitigation**: REQ-DT-004 requires an explicit `dtaRecoverabilityRationale` (required text field) linking to `linkedProjections` when DTA is recognized. This forces documentation of the judgment and audit trail. The implementation service does not auto-guess; the controller manually confirms or adjusts the percentage, and the rationale becomes an audit-trail artifact. No judgment call is hidden.

### Risk 3: Rate-change handling across multiple jurisdictions may miss edge cases (e.g., German rate change before NL rate change)

**Severity**: Low

**Mitigation**: REQ-DT-005 names `FiscalPeriod.enactedTaxRates` as the single source of truth (per jurisdiction, per enacted date). Scenarios in the spec cover single-jurisdiction and multi-jurisdiction rate changes. Implementation adds a calc-order guarantee: for each jurisdiction, apply rate changes in chronological order of enactment-to-effective date.

### Risk 4: Permanent-vs.-temporary distinction is conceptually easy but practically error-prone (e.g., is a deelnemingsvrijstelling dividend permanent or temporary?)

**Severity**: Medium

**Mitigation**: REQ-DT-002 gives three concrete scenarios: dividend under deelnemingsvrijstelling (permanent), provision (temporary deductible), MVA depreciation (temporary taxable). The `taxBasisDifferenceCategory` enum on `Account` (optional) lets operators pre-tag common types (e.g., "provision" → deductible, "MVA depreciation" → taxable). The GL detection logic reads this hint. No operator choice is required; the tag is advisory. Manual override is always possible.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR. The additive fields on `Account` and `FiscalPeriod` are optional, so existing T1/T2 callers stay correct.

## Open Questions

1. **Pillar 2 ETR alignment** — REQ-DT-006 produces an ETR-reconciliation per jurisdiction. For a Pillar-2 group, the 15% global minimum applies at group level; per-entity ETR alone does not determine Pillar-2 exposure. Should this spec pre-position aggregation logic for group ETR, or defer that to `bookkeeping-cbcr-pillar2`? → Defer to Pillar-2 spec; this spec produces entity-level reconciliation.
2. **Deferred tax on intra-group intercompany transactions** — when a subsidiary has a deferred-tax asset and the parent has a DTL on the same underlying item (push-down), IAS 12 allows no saldering across entities. Does the consolidation layer (T4) handle this, or should this spec add a `isCrossEntityItem` flag? → Track as future feature; document the consolidation hook in design.md.
3. **Historical-year adjustments (prior-year catch-up)** — a restatement of 2024 deferred-tax due to a 2025 Vpb audit finding is a `rateChangeAdjustment` or a new `prior-year-adjustment` enum value in `deferred-tax-movement`? → Use separate `reconciliationItems[]` array in `tax-rate-reconciliation` per REQ-DT-006 (matches IFRS/RJ 272 ETR table practice).
