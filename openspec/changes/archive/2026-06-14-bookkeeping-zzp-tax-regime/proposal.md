# Proposal: bookkeeping-zzp-tax-regime

`kind: spec` per ADR-032 — the centre of mass is declarative
schemas (`TaxSummaryReport`, `TaxEstimate`, `TaxRegimeConfiguration`)
+ aggregations for income/expense classification + manifest entries
for tax filing preparation and estimate dashboard. No bespoke PHP
tax calculation service; aggregations and lifecycle materialise
GL-derived tax summaries per ADR-031.

## Summary

Introduce the **ZZP Fiscaal Regime** capability for Shillinq as one
of the T3 tax compliance capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability serves Dutch
freelancers and self-employed professionals (ZZP — *Zelfstandige
Zonder Personeel*) filing annual income tax (IB — *Inkomstenbelasting*)
and preparing filing documentation. The change declares the
`TaxSummaryReport`, `TaxEstimate`, and `TaxRegimeConfiguration`
registers; tax summary aggregations grouping GL transactions by income/
expense category; real-time annual tax liability estimation consuming
GL materialised `GLTransaction` data; and manifest entries for tax
filing dashboard and estimate reporting.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md)
(GL transaction source data),
[`add-shillinq-chart-of-accounts`](../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md)
(account categorization for tax mapping).

## Motivation

ZZP tax filing is a core operational requirement for Dutch freelancers.
The Dutch tax authority (Belastingdienst) requires annual income/expense
summaries classified by statutory tax categories. Shillinq's GL
foundation (T1) provides transaction-level detail; this T3 capability
surfaces that detail as tax-ready reports and forward-looking estimates.

The context-brief intelligence data (`competitor_features` with
`app_slug=shillinq`) calls out tax summary reporting and real-time
liability estimation as top-tier feature requests alongside accounts
payable/receivable.

This is one of five T3 capability changes; this proposal scopes
the ZZP tax regime slice (applicability: freelancers, sole traders,
unincorporated businesses filing individual income tax).

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-zzp-tax-regime`); declares 3 new registers
  (`TaxSummaryReport`, `TaxEstimate`, `TaxRegimeConfiguration`)
  with aggregations and lifecycle; adds 3 manifest navigation entries
  (Tax Filing Prep, Tax Estimates, Tax Configuration).
- [ ] Project: openregister — no source changes; consumes existing
  aggregation extension + GL transaction indexing per ADR-031.

## Scope

### In Scope

- One new capability spec (`bookkeeping-zzp-tax-regime`) — see the
  `specs/` folder.
- The `TaxRegimeConfiguration` register with tax regime selection
  (ZZP sole trader eligibility check), filing frequency (annual,
  quarterly), tax year assignment, withholding rates, family-member
  expense caps per statutory rules.
- The `TaxSummaryReport` register aggregating GL transactions by
  statutory income/expense categories (wages, self-employment income,
  real estate, capital gains, deductible expenses, allowances) per
  fiscal year and filing period. No PHP tax calculator; aggregations
  materialize the summaries from GL data.
- The `TaxEstimate` register providing real-time annual income tax
  (IB) liability projection consuming GL year-to-date transactions,
  estimated remaining income, withholding credits. Declared as
  materialized view consuming GL transaction snapshot.
- Tax category mapping: GL account → statutory income/expense category
  (via `TaxRegimeConfiguration` rules) for aggregation-driven report
  generation.
- Manifest entries for tax filing dashboard (KPI: estimated liability,
  filing deadline, documents ready) and tax estimate detail page.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Withholding tax (loonheffing) / VAT payables** — T3 separate
  (tax-levy-management).
- **Multi-entity consolidated tax filing** — T5. T3 scope is single-
  entity ZZP only.
- **Export to DigiPoort / e-filing** — T4 external submission.
- **Depreciation schedules** — separate T3 spec (fixed-asset-accounting).

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-zzp-tax-regime`** — declares the three registers, the
tax category mapping, aggregations for income/expense summary, real-
time liability estimation, and manifest entries for tax filing
preparation and estimates dashboard.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-TAX-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister aggregations + GL transaction
indexing and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`TaxRegimeConfiguration`, `TaxSummaryReport`, `TaxEstimate`);
  declares tax category mappings and GL-derived aggregations.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: report` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: if GL-to-category
  mapping requires case-by-case jurisdiction rules, one
  single-method `TaxCategoryResolver` PHP guard is permitted).
- No new bespoke Vue components; uses `CnDashboardPage` + `CnStatsBlock`
  for KPI dashboard.

## Cross-Project Dependencies

- **GL Foundation (T1)** — depends on `add-shillinq-general-ledger`
  for materialized `GLTransaction` source data and account classification.
- **Chart of Accounts (T1)** — depends on `add-shillinq-chart-of-accounts`
  for RGS account codes and account-type classification.

## Risks

### Risk 1: Tax category mapping requires jurisdiction-specific rules

**Severity**: Medium
**Mitigation**: If tax category mapping (GL account → statutory
category) requires case-by-case logic for different industry sectors
(real estate, capital gains, etc.), the spec captures the gap via
`TaxRegimeConfiguration.categoryMappingRules` (JSON schema). A
single-method `TaxCategoryResolver` PHP guard per ADR-031 may ship if
the mapping cannot be declared purely in the register schema. The spec
is shape-neutral; the guard is removed if a future aggregation
extension supports complex mapping logic.

### Risk 2: Real-time estimate accuracy depends on GL completeness

**Severity**: Low-Medium
**Mitigation**: Tax estimates are forward-projections. REQ-TAX-008
explicitly states the estimate assumes GL transactions through the
query snapshot date and projects remaining income at current YTD
average rate. If GL has posting gaps (unrecorded invoices, batched
transactions), the estimate diverges from actual liability. Spec
includes audit-trail linkage so operators can trace estimate inputs
to specific GL lines. Accuracy improves as GL completeness improves.

### Risk 3: Annual income tax calculation rules change mid-year

**Severity**: Low
**Mitigation**: Tax rules are statutory. `TaxRegimeConfiguration`
versioning allows administration-level rule updates (tax year
parameter, statutory rates, allowance amounts). Each `TaxEstimate`
records which `TaxRegimeConfiguration` version was used, enabling
retroactive estimate recalculation if rules shift mid-filing-period.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — tax reports remain queryable.

## Open Questions

1. **Tax category mapping complexity** — scope resolved in `opsx-ff`
   discovery; if complex, PHP guard filed as ADR-031 exception.
2. **Quarterly vs. annual filing cadence** — `TaxRegimeConfiguration`
   supports both; default determined during implementing cycle's UX
   review based on SMB use cases.
3. **Multi-currency tax basis** — ZZP sole-trader typically single
   currency (EUR). Multi-currency tax bases deferred to T5.
4. **Family member / dependent allowances** — statutory caps vary by
   configuration; resolved during implementing cycle's settings
   review.

## Success Criteria

- Spec validation passes per `openspec validate`.
- Bookkeeper-persona review (via `/test-persona-janwillem` SMB)
  confirms tax summary structure matches Dutch annual income tax
  filing layout (Form IB + Annex C / Bijlage C).
- Architecture review confirms ADR-031 compliance (aggregation-
  driven reports, no PHP tax calculator service, config-driven
  category mapping).
- Tax estimates align with statutory formula (taxable income × rate
  - allowances - withholding credits ≥ 0).
