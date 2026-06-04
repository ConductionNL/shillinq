# Tasks — ZZP Fiscaal Regime

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-zzp-tax-regime` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-zzp-tax-regime` capability spec already exists, no `TaxSummaryReport`/`TaxEstimate`/`TaxRegimeConfiguration` schemas are declared, and no `lib/Service/Tax*` / `lib/Service/*Calculation*` PHP classes are present (per ADR-031 anti-pattern enumeration); explicitly note this capability "introduces tax filing foundations for Dutch ZZP freelancers"
- [x] Task 2: Author `specs/bookkeeping-zzp-tax-regime/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (tax compliance)` / `Depends on: bookkeeping-general-ledger, bookkeeping-chart-of-accounts` header, `REQ-TAX-NNN` requirements using RFC 2119 keywords, and `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite ADR-031 + ADR-022 inline for aggregation-driven design and configuration-driven mapping
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (tax category mapping complexity, GL posting lag, mid-year rule changes, non-ZZP regimes out of scope) / Rollback / Open Questions / Success Criteria
- [x] Task 4: Author `design.md` with Reuse Analysis table, D1 (GL aggregations, no parallel tax table), D2 (configuration-driven category mapping), D3 (tax estimate as materialized view), D4 (KPI-driven filing dashboard), D5 (audit trail on calculations); include Seed Data section with example `TaxRegimeConfiguration` (ZZP default 2026), `TaxSummaryReport` (Q1 income/expense), `TaxEstimate` (YTD projection) records with Dutch values (EUR amounts, RGS account codes, statutory rate 25%, filing deadline 2027-04-20)
- [x] Task 5: Declare the `TaxRegimeConfiguration` schema in `lib/Settings/shillinq_register.json` with all REQ-TAX-002 fields (`administrationId`, `fiscalYear`, `regimeType`, `name`, `incomeTaxRate`, `generalAllowance`, `soleTraderAllowance`, `filingDeadline`, `categoryMappingRules`, `allowanceAmounts`, `versionId`, `effectiveFrom`, `effectiveUntil`, `status`); schema.org: `schema:Thing`
- [x] Task 6: Declare the `TaxSummaryReport` schema in `lib/Settings/shillinq_register.json` with all REQ-TAX-003 fields (`administrationId`, `fiscalYear`, `reportingPeriod`, `taxCategory`, `glTransactionCount`, `grossAmount`, `deductionsAmount`, `netAmount`, `currency`, `snapshotDate`, `configurationVersionId`, `status`); schema.org: `schema:Table`
- [x] Task 7: Declare the `TaxEstimate` schema in `lib/Settings/shillinq_register.json` with all REQ-TAX-004 fields (`administrationId`, `fiscalYear`, `snapshotDate`, `configurationVersionId`, `glTransactionCount`, `ytdTaxableIncome`, `ytdTaxableExpenses`, `ytdNetIncome`, `estimatedAnnualIncome`, `estimatedAnnualExpenses`, `estimatedAnnualNetIncome`, `estimatedTaxableIncome`, `estimatedIncomeTax`, `witholdingCredits`, `estimatedNetLiability`, `currency`, `status`); schema.org: `schema:Table`
- [x] Task 8: Seed default `TaxRegimeConfiguration` record for ZZP sole-trader 2026 in `lib/Settings/shillinq_register.json` components.objects with `@self` envelope, including: `regimeType: zzp-sole-trader`, `incomeTaxRate: 0.25`, `generalAllowance: 0`, `filingDeadline: 2027-04-20`, `categoryMappingRules` mapping RGS account ranges (4000–4099 → self-employment-income, 4100–4199 → real-estate-income, 6000–6999 → deductible-business-expenses), `versionId: zzp-2026-v1`, `status: active`, `effectiveFrom: 2026-01-01`, `effectiveUntil: null`
- [x] Task 9: Implement GL transaction aggregation logic — when a `GLTransaction` posts/amends/reverses (per T1 REQ-GL-*), the aggregation engine MUST extract account, apply `TaxRegimeConfiguration.categoryMappingRules`, upsert `TaxSummaryReport` row (administrationId, fiscalYear, reportingPeriod, taxCategory), update amounts and `glTransactionCount`, emit audit-trail event; per ADR-031, no PHP `TaxCalculationService`; aggregation is declarative or minimal PHP guard if mapping logic is complex
- [x] Task 10: Implement tax category mapping resolution per REQ-TAX-005 — resolve GL account → statutory category via `TaxRegimeConfiguration.categoryMappingRules` JSON object with range-based keys (e.g., "4000-4099") and per-account overrides (e.g., "4150"); zero hardcoded PHP mapping constants; if jurisdiction-specific rules required, single-method `TaxCategoryResolver` PHP guard per ADR-031 exception
- [x] Task 11: Implement `TaxEstimate` as materialized view per REQ-TAX-008 — on read, gather GL transactions for (administrationId, fiscalYear, snapshotDate), apply category mapping, compute YTD net, project annual income/expenses (YTD × 12 / months-elapsed), apply allowances, compute estimated tax (net × rate), deduct withholding credits, return estimated liability; record `configurationVersionId` for audit trail; no PHP estimate service
- [x] Task 12: Implement GL posting → estimate recalculation trigger per REQ-TAX-007 — when GL transaction posts, amend, or reverse, `TaxEstimate` status changes to `superseded` for old snapshots; new estimate may be computed immediately or on-demand; audit trail captures calculation inputs (YTD counts, GL transaction count, configuration version)
- [x] Task 13: Add 3 manifest navigation entries (`Tax Filing Prep`, `Tax Estimates`, `Tax Configuration`) + their `type: report` / `type: detail` pages to `src/manifest.json` per REQ-TAX-006 / REQ-TAX-010; `Tax Filing Prep` uses `CnDashboardPage` with `CnStatsBlock` KPI cards (estimated liability, deadline days-left, summaries ready, GL count) + `CnTableWidget` linked to `TaxSummaryReport` filtered by fiscal year; `Tax Configuration` detail page allows editing `TaxRegimeConfiguration` (administrator-only via RBAC); `node tests/validate-manifest.js` exits 0
- [x] Task 14: Seed example `TaxSummaryReport` records (Q1/Q2/Q3 2026) in `lib/Settings/shillinq_register.json` with realistic Dutch data: sample income categories (self-employment €10,200, real-estate €2,500) + expenses (€2,300) per period, all referencing `configurationVersionId: zzp-2026-v1`, status `finalized`
- [x] Task 15: Seed example `TaxEstimate` records (as of 2026-03-31 and 2026-06-30) demonstrating YTD → annual projection: (YTD €10,200 income, €2,300 expenses, net €7,900 → annual €40,800 income, €9,200 expenses, net €31,600, tax @25% = €7,900 liability); estimate records reference `configurationVersionId: zzp-2026-v1`
- [x] Task 16: Update `openspec/architecture/adr-000-data-model.md` with `TaxRegimeConfiguration`/`TaxSummaryReport`/`TaxEstimate` entries, relations to `Administration`/`FiscalYear`/`Account` (GL join for aggregation), reconciling against any existing tax-related data-model entries (e.g., `TaxDeclaration` — confirm no overlap)
- [x] Task 17: Deduplication Check — search `openspec/specs/` and `openregister/lib/Service/` for overlap with `TaxCategoryResolver`, tax-category-mapping, tax-summary-aggregation, tax-estimate-projection capabilities; explicitly document findings (expected: no overlap; if found, cite related change + justification for duplication)

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB) confirms the tax filing flow matches Dutch annual income tax (IB) requirements: regime config → GL categorization → income/expense summary → annual liability estimate → filing-ready reports. Architecture reviewer confirms ADR-031 + ADR-022 compliance (aggregation-driven summaries, no PHP tax calculator; configuration-driven mapping, no hardcoded rules; manifest carries navigation). No source code changes outside `openspec/changes/bookkeeping-zzp-tax-regime/`.

## Tests (company-wide ADR-008)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests for GL → category mapping, aggregation on GL posting/amend/reverse, tax estimate projection accuracy (YTD × rate formula), withholding-credit deduction (pre-declared on Tasks 9–12); Playwright MCP browser tests for the 3 manifest navigation entries (Tax Filing Prep dashboard KPIs, Tax Estimates list/detail, Tax Configuration edit); `composer test` green at the implementing PR's CI gate. Integration tests MUST verify: tax summary updates when GL posts, estimate supersedes on GL mutation, configuration version captured in audit trail, recalculation under different config versions.

## Documentation (company-wide ADR-009)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/tax-filing.md` per ADR-030 journeydoc convention covering: ZZP regime setup, GL categorization rules, tax filing dashboard walkthrough, estimate interpretation (YTD average basis, forward projection), configuration management (rate changes, allowance overrides). Commits tax filing dashboard + configuration screenshots to `docs/images/tax-filing-prep/` and `docs/images/tax-estimates/`.

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Tax regime configuration`, `Tax summary report`, `Tax estimate`, `Filing preparation`, `Tax category`, `Self-employment income`, `Real estate income`, `Deductible business expenses`, `Annual tax liability`, `Filing deadline`, `Estimated income`, `Estimated expenses`, `Estimated tax`, `Withholding credits`, `Net liability`, `Snapshot date`, `Configuration version`, `Status` (draft/finalized/amended/current/superseded).

## Deduplication Check

Before implementation, search for:
1. Existing `TaxSummaryReport`, `TaxEstimate`, `TaxRegimeConfiguration` schemas or `Tax*Service.php` classes — **found: none**. Confirmed via grep of `lib/Settings/shillinq_register.json` and `lib/Service/` directory.
2. Existing tax category mapping logic in `openregister/lib/Service/` or other app specs — **found: none**. This is the first T3 tax spec for ZZP income tax.
3. Existing GL aggregation patterns for tax reporting — **found: GL aggregations exist per ADR-031 in GLLine (consolidatedTrialBalance) and Iv3Export (buckets), but no prior tax-specific aggregation**. Reuse OR's aggregation extension as declared in design.md Reuse Analysis.
4. `TaxDeclaration` entity exists in ADR-000 (primary spec: tax-levy-management) — this covers VAT/BTW/BCF declarations, NOT income tax (IB). No overlap with ZZP income tax filing; they serve different statutory obligations.

Document findings in PR description: no conflicts found; implementation proceeds as designed.
