# Design — Deferred Tax Assets, Liabilities & Provisions

## Decisions

### D1 — Temporary differences are detected per-account at balansdatum, not accumulated per period

Per IAS 12 §5–10, a temporary difference is the difference between the carrying amount of an asset/liability and its tax basis at a point in time. The system detects by reading `Account` balances on balansdatum (end of fiscal period) and comparing to the `taxBasisDifferenceCategory` hint + linked fiscal valuation rules.

**Alternative considered**: Record temporary differences incrementally throughout the year (e.g., each time an asset is revalued or a provision is accrued). Rejected — introduces stale records if a difference reverses mid-year; easier to detect once at balansdatum from the GL than to maintain a daily ledger of diffs.

### D2 — Deferred-tax reconciliation is schema-declared, not a PHP calculator service

Per ADR-031, the tax-rate-reconciliation calculation (permanent differences, temporary differences, rate changes, prior-year adjustments → effective tax expense) is declared as `x-openregister-calculations` on the `tax-rate-reconciliation` schema, not authored as a `TaxCalculationService.calculateETR()` method.

**Alternative considered**: Write a service class with method `calculateAndStoreETR()`. Rejected per ADR-031 — calculations belong in schema metadata; service orchestration is the exception, not the rule.

### D3 — Loss-compensation regime is jurisdiction-scoped and regime-indexed

Each `tax-loss-carry-forward` record carries `jurisdiction` (NL, DE, BE, etc.) and `applicableRegime` (pre-2019-6year, 2019-2021-transition, 2022-onwards-50pct-cap) because the Wet Vpb changed the rules effective 2022 and there are overgangsregels. The system does not auto-guess a regime; the GL close process reads the `linkedVpbReturn` (which the tax specialist has marked with the regime) and applies the correct compensation logic.

**Alternative considered**: Store a single `expirationYear` and a single `utilisableAmount` on each loss record. Rejected — the 50% above-threshold cap (2022+) and the overgangsregels (2019–2021 transitional path) require separate metadata; oversimplifying leads to wrong calculations for transitional years.

### D4 — Recoverability of DTA on losses is documented, not auto-approved

When activating a DTA from a loss carry-forward (REQ-DT-004), the controller provides a `dtaRecoverabilityRationale` text field that cites the projection (linked via `linkedProjections` FK to a forecast from `bookkeeping-budget-multi-year`). The system does not auto-activate; the controller reviews the loss balance, the projection, and chooses a percentage. This preserves audit evidence and forces judgment to be explicit.

**Alternative considered**: Auto-calculate DTA as 100% of losses, flagging only if a projection is absent. Rejected — auditors require evidence of positive intent to recover; activating 100% without a projection is indefensible.

### D5 — Rate changes are per-jurisdiction and apply to expected-reversal-year, not all differences

When `FiscalPeriod.enactedTaxRates` marks a new rate (e.g., parliament enacts 27% effective 2028-01-01), the balansdatum-year differences that are expected to reverse after 2028 are re-measured at 27%, creating a `rateChangeAdjustment` in the `deferred-tax-movement` roll-forward.

**Alternative considered**: Apply the new rate to all deferred-tax balances indiscriminately. Rejected — IFRS/IAS 12 §47–48 require rate adjustment only for items expected to reverse on or after the effective date; adjusting 10-year-horizon items prematurely overstates the effect.

### D6 — Permanent differences appear in ETR reconciliation, not in DTA/DTL

A permanent difference (e.g., deelnemingsvrijstelling dividend, non-deductible gifts) affects the effective tax rate (statutory rate × profit ± permanent differences) but creates no deferred-tax asset/liability. The `tax-rate-reconciliation` schema includes a `reconciliationItems[]` array that lists each permanent and temporary adjustment.

**Alternative considered**: Create a `deferred-tax-permanent-difference` schema. Rejected — a permanent difference is not deferred; it is a one-time adjustment to the ETR calculation, not a balance-sheet item.

### D7 — Saldering of DTA and DTL is per-jurisdiction and gated by legal right

Per IAS 12 §71–78, DTA and DTL saldering is permitted only within the same jurisdiction and when the entity has a current legal right to offset. The `tax-provision` schema carries `presentationOnBalanceSheet: gross / net`, allowing the balance sheet to show either:

- Gross: separate DTA (asset) and DTL (liability) lines
- Net: one combined line (net position)

The determination is made once per period per jurisdiction and recorded in `presentationOnBalanceSheet`.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Temporary-difference, loss, ETR record storage | New schemas (`temporary-difference`, `tax-loss-carry-forward`, `tax-rate-reconciliation`, `deferred-tax-movement`, `tax-provision`) | Declared per ADR-024 / ADR-031; inherit OR audit-trail-immutable and RBAC |
| GL balance reading | T1 `bookkeeping-general-ledger` | Service reads `Account` balances (debit/credit sum) on balansdatum |
| Account tagging for difference-type hints | T1 `Account.taxBasisDifferenceCategory` (optional enum) | Optional hint; if absent, operator manually specifies category in `temporary-difference` record |
| Tax-rate look-up per jurisdiction / period | `FiscalPeriod.enactedTaxRates` object | Reads enacted rates keyed by jurisdiction; looks up relevant rate by expected-reversal year |
| Current-tax payable link | T3 `bookkeeping-vpb-mkb` (via `linkedVpbReturn` FK) | `tax-provision.linkedVpbReturn` points to the Vpb-aangifte record |
| Loss-compensation regime metadata | T3 `bookkeeping-vpb-mkb` | Vpb record carries the regime flag (pre-2019, transition, 2022+); GL close reads it |
| Recoverability projection link | Optional T4 `bookkeeping-budget-multi-year` | `tax-loss-carry-forward.linkedProjections` array references forecast records |
| ETR calculation | `x-openregister-calculations` (ADR-031) | Single-schema calculation on `tax-rate-reconciliation` (no PHP service) |
| Roll-forward detail | `deferred-tax-movement` register | Captures each year's origination, reversal, rate effect, M&A, FX per category |
| Audit trail | OR audit-trail-immutable | Consumed automatically on all tax schemas |
| Consolidation separation | T4 `bookkeeping-consolidation-commercial` | Per-entity per-jurisdiction records; no inter-company saldering |

**Net new code in implementation cycle**: 5 schema declarations, 2 Account/FiscalPeriod additive patches, 1 service class (`TaxCalculationService`) containing the GL detection + loss-compensation + recoverability + rate-change logic.

## Seed Data

No seed data required. All `temporary-difference`, `tax-loss-carry-forward`, `tax-rate-reconciliation`, `deferred-tax-movement`, and `tax-provision` records accumulate from GL on balansdatum via `TaxCalculationService` invocation.

One preconfiguration per administration: the controller must ensure that any account carrying material temporary differences is tagged with `Account.taxBasisDifferenceCategory` (optional). Without tagging, the system treats the account as ordinary (no diff detection); with tagging, the detection logic applies the appropriate reversal pattern and category.

## Architectural Decisions vs. Standards Compliance

**IAS 12 §21–23 (recognition of deferred tax)**: This spec implements recognition of DTA/DTL on all temporary differences (IFRS full model). Probable-future-profit assessment for DTA on losses is deferred to recoverability logic (D4 above).

**RJ 272.301–310 (recognition, measurement, presentation)**: The spec closely mirrors RJ 272 paragraphing; measurement is at rate on expected-reversal year (D5); presentation (gross/net) is per-jurisdiction choice (D7).

**IAS 12 §71–78 (offsetting)**: Saldering is jurisdiction-scoped and gated by `presentationOnBalanceSheet` (D7).

**Wet Vpb articles 8, 20, 20a (loss compensation regimes)**: The three regimes (pre-2019 6-year, 2019–2021 transition, 2022+ 50%-cap) are named in `applicableRegime` enum (D3). Compensation logic reads the regime from the linked Vpb record.
