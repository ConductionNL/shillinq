# Design — ZZP Fiscaal Regime

## Context

Dutch freelancers (ZZP — *Zelfstandige Zonder Personeel*) file annual
income tax (IB — *Inkomstenbelasting*) with the Belastingdienst. Tax
filing requires income/expense summaries grouped by statutory categories
(wages, self-employment income, real estate, capital gains, deductible
expenses, statutory allowances).

Shillinq's T1 GL foundation provides transaction-level detail. This T3
capability surfaces that detail as tax-ready reports and forward-
looking annual tax liability estimates. Per ADR-031, tax summaries are
aggregation-driven (no PHP tax calculator service); per ADR-022,
category mappings are configuration-driven.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire ZZP tax regime surface as **declarative metadata** —
  schemas + tax category mappings + aggregations + manifest entries — per
  ADR-031.
- Make the spec a **competent-accountant readable contract** — Dutch SMB
  tax filing flow recognisable end-to-end (regime config, GL transaction
  categorization, income/expense summary by statutory bucket, annual
  liability estimate, filing-ready reports).
- Serve **forward-looking tax planning** — real-time annual tax liability
  estimate so operators project EOY liability early.
- Defer **jurisdiction-specific tax rules** to configuration — rate changes,
  allowance caps, category mappings live in `TaxRegimeConfiguration`, not
  hardcoded PHP.
- Declare the data shape so T4 can attach external e-filing (DigiPoort)
  additively.

## Non-Goals

- No PHP tax calculator service; no `TaxCalculationService.php`.
- No VAT/BTW posting — separate T3 spec (tax-levy-management).
- No withholding tax (loonheffing) — separate T3 spec.
- No multi-entity consolidated filing — T5 (corporations-enterprise).
- No export to DigiPoort / Belastingdienst in T3; T4 attaches that.

## Decisions

### D1 — Tax summaries are GL aggregations, not a parallel tax table

Symmetric to D1 of `add-shillinq-general-ledger` (all GL is single
source of truth): `TaxSummaryReport` aggregates `GLTransaction` rows
grouped by `(administrationId, fiscalYear, taxCategory)` where
`taxCategory` is computed from GL account mapping. No parallel
`tax_transactions` table in app-local PHP; no `TaxService::calculateTax()`.

### D2 — Tax category mapping is configuration, not hardcoded

GL account → statutory tax category mapping lives in
`TaxRegimeConfiguration.categoryMappingRules` (JSON schema allowing
rule overrides per account). Shillinq ships default mappings (RGS
account codes → statutory categories); administrators customize per
their firm's practice. Zero hardcoded "account 4000 = self-employment
income" PHP constants.

### D3 — Annual tax estimate is a materialized view, not a service

`TaxEstimate` is declared as an `x-openregister` materialized view
consuming GL YTD snapshot + configuration. On read, the view projects
remaining income at current average rate, applies statutory rates,
deducts allowances + withholding credits, yielding estimated annual
liability. Pure aggregation + formula; no PHP estimate service.

If GL-to-category mapping requires case-by-case logic (rare), ADR-031
exception path applies: a single-method `TaxCategoryResolver` PHP guard
ships, cited in the spec; removed when a future aggregation extension
supports complex rule evaluation.

### D4 — Tax filing dashboard is KPI-driven

Manifest entry `Tax Filing Prep` uses `CnDashboardPage` with
`CnStatsBlock` KPIs (estimated liability, filing deadline days-left,
documents ready count) + a linked `TaxSummaryReport` table widget.
Operator sees EOY estimate + document readiness at a glance; no bespoke
Vue component.

### D5 — Tax estimates record their calculation inputs

`TaxEstimate` stores `configurationVersionId`, `snapshotDate`,
`glTransactionCount`, `ytdIncome`, `ytdExpenses` so operators can trace
the estimate back to specific GL lines. Audit trail captures estimate
mutations (policy change, GL repost). Traceability enables root-cause
investigation if estimate diverges from actual.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| GL transaction source data | T1 `GLTransaction` (general ledger) | Aggregation consumes GL directly; no parallel tax table |
| Account categorization | T1 `Account` schema + RGS hierarchy | Tax category mapping via configuration (GL account → statutory category) per D2 |
| Tax summary aggregation | OR `x-openregister-aggregations` | GROUP BY `(administrationId, fiscalYear, taxCategory)` with SUM(amount) |
| Annual liability estimate | OR materialized-view / aggregation | `TaxEstimate` computed on read from GL YTD + config rules per D3 |
| Tax filing dashboard | `CnDashboardPage` + `CnStatsBlock` | KPI cards (liability, deadline, ready-docs) + linked summary table |
| Configuration / versioning | OR `IAppConfig` + OR schema versioning | `TaxRegimeConfiguration` register with `versionId` per D5 |
| Audit trail (policy changes, estimate updates) | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions + mutations |
| Manifest navigation | T1 manifest pattern | 3 entries (Tax Filing Prep, Tax Estimates, Tax Config) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 2–3
aggregation blocks + 1 materialized-view definition + 3 manifest entry
pairs. At most 1 single-method PHP guard (`TaxCategoryResolver`) gated
by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Tax category mapping (GL account → statutory category) | Configuration-driven + optional PHP guard per ADR-031 exception | Pure configuration; case-by-case rules rare |
| Income/expense aggregation | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM; no logic |
| Annual tax estimate | Materialized view (aggregation + formula) | Deterministic formula (income × rate - allowances - credits) |
| Estimate audit trail (inputs + policy version) | Automatic via lifecycle + object mutations | Traceability built-in |
| Tax filing deadline calculation | Declarative (`TaxRegimeConfiguration.filingDeadline` field) | Static statutory date; no logic |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `TaxCategoryResolver` if
configuration-only mapping proves insufficient).

## Seed Data

**Three example `TaxRegimeConfiguration` records:**

```json
{
  "@self": { "register": "TaxRegimeConfiguration", "schema": "TaxRegimeConfiguration", "slug": "zzp-default-2026" },
  "administrationId": "adm-1",
  "name": "ZZP Default 2026",
  "fiscalYear": 2026,
  "regimeType": "zzp-sole-trader",
  "incomeTaxRate": 0.25,
  "generalAllowance": 0,
  "filingDeadline": "2027-04-20",
  "categoryMappingRules": {
    "4000-4099": "self-employment-income",
    "4100-4199": "real-estate-income",
    "6000-6199": "deductible-business-expenses",
    "6200-6299": "deductible-professional-fees"
  }
}
```

**Three example `TaxSummaryReport` records (seed data is computed from GL;
shown for reference):**

```json
{
  "@self": { "register": "TaxSummaryReport", "schema": "TaxSummaryReport", "slug": "tax-summary-2026-q1" },
  "administrationId": "adm-1",
  "fiscalYear": 2026,
  "reportingPeriod": "quarter-1",
  "taxCategory": "self-employment-income",
  "grossAmount": 12500.00,
  "deductionsAmount": 2300.00,
  "netAmount": 10200.00,
  "currency": "EUR",
  "snapshotDate": "2026-03-31",
  "status": "finalized"
}
```

**Three example `TaxEstimate` records (YTD through specific snapshot):**

```json
{
  "@self": { "register": "TaxEstimate", "schema": "TaxEstimate", "slug": "tax-estimate-2026-03" },
  "administrationId": "adm-1",
  "fiscalYear": 2026,
  "snapshotDate": "2026-03-31",
  "configurationVersionId": "zzp-default-2026",
  "ytdTaxableIncome": 10200.00,
  "estimatedAnnualIncome": 40800.00,
  "estimatedAnnualExpenses": 9200.00,
  "estimatedTaxableIncome": 31600.00,
  "estimatedIncomeTax": 7900.00,
  "witholdingCredits": 0.00,
  "estimatedNetLiability": 7900.00,
  "currency": "EUR",
  "status": "current"
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Tax category mapping too simplistic for edge cases | Configuration-driven rules; PHP guard fallback per ADR-031 exception; document JSON schema constraints |
| Real-time estimate diverges from actual liability | Spec explicitly states estimate is forward-projection; estimate inputs (YTD, config version) recorded for audit; operator sees "as of [date]" clearly |
| Tax rules change mid-year (rate, allowance caps, deduction limits) | `TaxRegimeConfiguration` versioning; `TaxEstimate` records which version was used; retroactive recalculation possible if rules shift |
| GL has posting gaps or errors | Estimate accuracy depends on GL completeness; audit trail links estimates to GL transactions; operator can spot divergence and investigate |
| Non-ZZP regime (BV, partnership) falls outside scope | Spec states ZZP sole-trader focus; multi-entity handling deferred to T5; non-applicability is explicit, not silent failure |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).
3. Default `TaxRegimeConfiguration` record is seeded via
   `ImportHandler::importFromApp()` for ZZP sole-trader (2026 rules).
4. If tax category mapping requires case-by-case logic,
   `lib/Service/TaxCategoryResolver.php` ships (single method, ~40 LOC,
   ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes the
manifest entries; tax reports remain queryable but unreferenced.

## Open Questions

1. **Tax category mapping complexity** — resolved in `opsx-ff`
   discovery; document findings (edge cases, sector-specific rules).
2. **Quarterly vs. annual reporting cadence** — both supported; default
   chosen during implementing cycle's configuration review.
3. **Family member / dependent allowances** — statutory caps vary;
   `TaxRegimeConfiguration.allowances` object schema supports
   per-category caps; defaults resolved in implementing cycle.
4. **GL posting lag impact on estimates** — documented in spec; end-user
   sees "estimate as of [date]"; gap investigation via audit trail.
5. **Multi-year carryforward (loss-carryback)** — deferred to future;
   spec shape allows extension.
