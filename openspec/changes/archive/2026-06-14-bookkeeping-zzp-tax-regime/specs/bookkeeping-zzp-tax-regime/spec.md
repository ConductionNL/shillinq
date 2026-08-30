# Spec: bookkeeping-zzp-tax-regime

**Status:** proposed
**Scope:** shillinq
**Tier:** T3 (tax compliance)
**Depends on:** `../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md` (T1 GL),
`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md` (account hierarchy)

## ADDED Requirements

### Requirement: REQ-TAX-001: ZZP tax regime SHALL be declared as configuration + aggregated reports, not a parallel tax table

ZZP (self-employed without employees) tax filing MUST be expressed
as a capability composed of three registers + aggregations:

- `TaxRegimeConfiguration` — ZZP regime parameters (fiscal year,
  tax rates, statutory allowances, filing deadline, GL account →
  statutory category mappings).
- `TaxSummaryReport` — GL-aggregated income/expense summaries by
  statutory category + fiscal period, computed from GL transaction
  grouping per `TaxRegimeConfiguration.categoryMappingRules`.
- `TaxEstimate` — real-time annual income tax (IB) liability projection
  consuming GL year-to-date snapshot + configuration.

This capability is the **tax filing foundation for Dutch freelancers**,
bridging T1 GL detail to statutory tax filing requirements. Tax
summaries MUST be materialized from GL (no parallel `tax_transactions`
table); per ADR-031, no `TaxCalculationService.php`. Posting a
`GLTransaction` with account in range 4000–4999 (income) or 6000–6999
(expenses) MUST flow through aggregation into the corresponding
`TaxSummaryReport` row.

#### Scenario: No parallel tax table exists

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `tax_*`, `calculation_*`, `summary_*` (non-register)
- **THEN** no such classes SHALL exist in T3 scope. Tax data flows
  from GL via aggregation only.

#### Scenario: GL income account aggregates into tax summary

- **GIVEN** T3 is live and a `GLTransaction` posts €500 to
  account 4120 (freelance services) on 2026-03-15
- **WHEN** `TaxSummaryReport` for (administrationId, 2026, Q1,
  self-employment-income) is queried
- **THEN** the report row MUST include the €500 in `grossAmount`.

### Requirement: REQ-TAX-002: The `TaxRegimeConfiguration` schema SHALL declare regime parameters and GL-to-category mappings

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `fiscalYear` | integer | Yes | Fiscal year (e.g., 2026) |
| `regimeType` | enum | Yes | One of `zzp-sole-trader`, `partnership`, `cv` (others deferred to future tiers) |
| `name` | string | Yes | Display name (e.g., "ZZP Default 2026") |
| `incomeTaxRate` | number 0–1 | Yes | Marginal income tax rate (statutory rate for given fiscal year; e.g., 0.25 for 25%) |
| `generalAllowance` | number ≥ 0 | No | General statutory allowance (€Amount) |
| `soleTraderAllowance` | number ≥ 0 | No | Sole trader deduction (if applicable) |
| `filingDeadline` | date | Yes | Statutory filing deadline (e.g., 2027-04-20) |
| `categoryMappingRules` | object | Yes | JSON object: `{ "account-range": "tax-category", ... }` e.g. `{ "4000-4099": "self-employment-income", "4100-4199": "real-estate-income" }` |
| `allowanceAmounts` | object | No | JSON object with per-category allowance overrides (e.g., `{ "business-expenses": 5000 }`) |
| `versionId` | string | Yes | Semantic version for configuration (enables retroactive recalculation) |
| `effectiveFrom` | date | Yes | Date this configuration becomes active |
| `effectiveUntil` | date | No | Date configuration expires (e.g., on rule change) |
| `status` | enum | Yes | One of `active`, `archived`, `superseded` |

Schema.org annotation: `schema:Thing` (configuration metadata).

#### Scenario: Default ZZP configuration initializes on install

- **GIVEN** Shillinq installs with T3 enabled
- **WHEN** first administration is created
- **THEN** a `TaxRegimeConfiguration` with `regimeType: zzp-sole-trader`
  and current fiscal year MUST be seeded with statutory rates + default
  mappings (account 4000–4999 → income categories, 6000–6999 → expense
  categories per RGS standard).

#### Scenario: Administrator customizes category mapping

- **GIVEN** a `TaxRegimeConfiguration` exists with default mappings
- **WHEN** the administrator updates `categoryMappingRules` to remap
  account 4150 from "self-employment-income" to "real-estate-income"
- **THEN** the configuration MUST accept the change; **AND** any
  subsequent `TaxSummaryReport` queries MUST use the new mapping;
  **AND** prior reports (with old mapping) remain unchanged per audit
  trail.

### Requirement: REQ-TAX-003: The `TaxSummaryReport` schema SHALL declare GL-aggregated income/expense summaries by statutory category and period

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `fiscalYear` | integer | Yes | Fiscal year (2026, 2027, etc.) |
| `reportingPeriod` | enum | Yes | One of `year`, `quarter-1`, `quarter-2`, `quarter-3`, `quarter-4`, `month-01`...`month-12` |
| `taxCategory` | string | Yes | Statutory income/expense category (e.g., "self-employment-income", "real-estate-income", "deductible-business-expenses") |
| `glTransactionCount` | integer | No | Count of GL transactions aggregated |
| `grossAmount` | number | Yes | Sum of GL transaction amounts in this category for the period (EUR) |
| `deductionsAmount` | number | No | Any allowances or statutory deductions specific to this category (EUR) |
| `netAmount` | number | Yes | `grossAmount - deductionsAmount` (EUR) |
| `currency` | string | Yes | ISO 4217 code (EUR) |
| `snapshotDate` | date | Yes | Date the aggregation was computed (for audit trail) |
| `configurationVersionId` | string | Yes | FK to `TaxRegimeConfiguration.versionId` used for category mapping |
| `status` | enum | Yes | One of `draft`, `finalized`, `amended` |

Schema.org annotation: `schema:Table` (report data).

#### Scenario: Q1 2026 summary groups income accounts by category

- **GIVEN** T3 is live with default configuration (account 4100–4149
  mapped to "self-employment-income", 4150–4199 to "real-estate-income")
  and Q1 2026 GL entries: €5000 to acct 4100, €2500 to acct 4150,
  €1200 to acct 6000 (expenses)
- **WHEN** `TaxSummaryReport` is generated for (administrationId, 2026,
  quarter-1)
- **THEN** three rows SHALL exist: (self-employment-income, 5000, 0,
  5000), (real-estate-income, 2500, 0, 2500), (deductible-business-
  expenses, 1200, 0, 1200).

#### Scenario: Amendment updates a finalized report

- **GIVEN** Q1 2026 report is status `finalized`
- **WHEN** an operator amends a GL posting (e.g., reclassifies an
  expense from account 6000 to 6100, both in expenses bucket), the GL
  transaction is reposted
- **THEN** the aggregation recomputes; `TaxSummaryReport` row status
  changes to `amended`; audit trail records the change (transaction
  UUID + old/new amounts).

### Requirement: REQ-TAX-004: The `TaxEstimate` schema SHALL declare real-time annual tax liability projection consuming GL YTD + configuration

| Field | Type | Required | Purpose |
|---|---|---|---|
| `administrationId` | string | Yes | FK to administration |
| `fiscalYear` | integer | Yes | Fiscal year (2026, 2027, etc.) |
| `snapshotDate` | date | Yes | Date through which GL is included (e.g., 2026-03-31 for Q1 YTD) |
| `configurationVersionId` | string | Yes | FK to `TaxRegimeConfiguration.versionId` |
| `glTransactionCount` | integer | No | Count of GL transactions included in YTD calculation |
| `ytdTaxableIncome` | number | Yes | YTD income (aggregated from GL per configuration mapping, EUR) |
| `ytdTaxableExpenses` | number | Yes | YTD deductible expenses (EUR) |
| `ytdNetIncome` | number | Yes | `ytdTaxableIncome - ytdTaxableExpenses` (EUR) |
| `estimatedAnnualIncome` | number | Yes | Projected annual income (YTD average × 12 / months-elapsed) (EUR) |
| `estimatedAnnualExpenses` | number | Yes | Projected annual expenses (same method) (EUR) |
| `estimatedAnnualNetIncome` | number | Yes | `estimatedAnnualIncome - estimatedAnnualExpenses` (EUR) |
| `estimatedTaxableIncome` | number | Yes | Net income after statutory allowances per configuration (EUR) |
| `estimatedIncomeTax` | number | Yes | `estimatedTaxableIncome × configurationRate` (EUR) |
| `witholdingCredits` | number | No | Accumulated withheld tax / advance payments (EUR) |
| `estimatedNetLiability` | number | Yes | `estimatedIncomeTax - witholdingCredits` (EUR; may be negative = refund due) |
| `currency` | string | Yes | ISO 4217 code (EUR) |
| `status` | enum | Yes | One of `current`, `superseded` |

Schema.org annotation: `schema:Table` (projection data).

#### Scenario: Q1 YTD estimate projects annual liability

- **GIVEN** T3 is live and GL YTD through 2026-03-31 shows: income
  €10,200, expenses €2,300, net €7,900; configuration specifies 25%
  rate + €0 allowance
- **WHEN** `TaxEstimate` is computed for (administrationId, 2026,
  2026-03-31)
- **THEN**: ytdNetIncome = €7,900; estimatedAnnualIncome = €10,200 ×
  (12/3) = €40,800; estimatedAnnualExpenses = €2,300 × (12/3) = €9,200;
  estimatedAnnualNetIncome = €31,600; estimatedIncomeTax = €31,600 ×
  0.25 = €7,900; estimatedNetLiability = €7,900 (assuming no withholding
  credits yet).

#### Scenario: Estimate updates when GL posts new transaction

- **GIVEN** the above estimate exists at 2026-03-31
- **WHEN** a new GL transaction posts €500 income to account 4100 on
  2026-04-15
- **THEN** an updated `TaxEstimate` is computed (or materialized-view
  refreshed) for snapshotDate = 2026-04-15; YTD income now €10,700;
  estimated annual tax rises to €8,025; old estimate with
  snapshotDate = 2026-03-31 transitions to status `superseded`.

#### Scenario: Estimate captures configuration version used

- **GIVEN** `TaxEstimate` with `configurationVersionId: zzp-2026-v1`
  was computed
- **WHEN** `TaxRegimeConfiguration` is updated (e.g., rate changes
  mid-year from 25% to 26% due to tax law change)
- **THEN** new estimates use the new version (v2); old estimate record
  remains unchanged with v1; operator can compare old/new to see
  liability delta from policy change; no recalculation needed unless
  explicitly requested.

### Requirement: REQ-TAX-005: Tax category mapping SHALL be configuration-driven per GL account range; no hardcoded PHP mapping

GL account → statutory tax category mapping MUST live in
`TaxRegimeConfiguration.categoryMappingRules` (JSON object) with
account-range keys (e.g., `"4000-4099"`, `"4100-4199"`) mapping to
category values (e.g., `"self-employment-income"`,
`"real-estate-income"`). The mapping MUST support:

- Range-based matching (account 4000–4099 match rule `"4000-4099"`).
- Per-account override (rule `"4150"` overrides `"4100-4199"`).
- Zero hardcoded PHP constants (`if ($acct >= 4000 && $acct < 4100) ...`).

If GL-to-category logic requires case-by-case jurisdiction rules (rare),
a single-method `TaxCategoryResolver` PHP guard per ADR-031 exception
MAY ship. The spec is shape-neutral; complex rules are gated.

#### Scenario: Default mapping follows RGS standard

- **GIVEN** Shillinq T3 initializes with default configuration
- **WHEN** the `categoryMappingRules` are examined
- **THEN** account ranges SHALL match RGS hierarchy: 4000–4199 map to
  income categories, 6000–6999 to expense categories (standard Dutch
  chart-of-accounts convention).

#### Scenario: Administrator overrides a single account

- **GIVEN** configuration `{ "4100-4149": "self-employment-income",
  "4150": "real-estate-income" }`
- **WHEN** GL posts €500 to account 4150
- **THEN** the aggregation MUST apply rule `"4150"` (highest precedence),
  categorizing it as `real-estate-income`, NOT falling back to
  `"4100-4149"`.

### Requirement: REQ-TAX-006: Tax filing dashboard SHALL surface KPIs (estimated liability, deadline, documents ready) and linked summaries

`src/manifest.json` MUST declare:

- `Bookkeeping > Tax Filing Prep` — `type: report` dashboard rendering
  4 KPI cards (estimated annual liability, filing deadline days-left,
  tax summaries ready count, GL transaction count included) + a linked
  table widget bound to `TaxSummaryReport` filtered by fiscal year.
- `Bookkeeping > Tax Estimates` — `type: report` showing historical
  `TaxEstimate` snapshots (by snapshotDate) for year-to-date
  comparison.
- `Bookkeeping > Tax Configuration` — `type: detail` on
  `TaxRegimeConfiguration` allowing administrators to review/edit
  regime parameters, category mappings, allowance amounts.

Dashboard rendering MUST use `CnDashboardPage` + `CnStatsBlock` KPI
cards + `CnTableWidget` for linked summaries. Zero custom Vue
components; all rendering via `@conduction/nextcloud-vue`.

#### Scenario: Tax filing dashboard displays estimated liability

- **GIVEN** the manifest declares `Tax Filing Prep` page
- **WHEN** an operator opens `/index.php/apps/shillinq/tax-filing-prep`
- **THEN** `CnDashboardPage` MUST render: (1) KPI card showing
  estimated annual income tax (e.g., "€7,900 estimated annual
  liability"), (2) KPI card showing days until filing deadline
  (e.g., "284 days until 2027-04-20"), (3) KPI card showing tax
  summaries ready (e.g., "4 categories finalized"), (4) linked
  `TaxSummaryReport` table with columns `taxCategory`, `grossAmount`,
  `deductionsAmount`, `netAmount`.

#### Scenario: Tax configuration page allows mapping override

- **GIVEN** `Tax Configuration` detail page loads
- **WHEN** operator adds a custom account-override mapping (e.g.,
  account 4200 → "consulting-income" instead of default)
- **THEN** the form MUST save the mapping to
  `TaxRegimeConfiguration.categoryMappingRules`; **AND** a new
  `TaxSummaryReport` aggregation MUST use the updated mapping for
  subsequent GL posting.

### Requirement: REQ-TAX-007: Tax summary updates SHALL be triggered by GL transaction posting, reflecting changes automatically

When a `GLTransaction` is posted, amended, or reversed (per
`bookkeeping-general-ledger` REQ-GL-*, the aggregation engine MUST:

1. Extract the GL account from the transaction.
2. Apply `TaxRegimeConfiguration.categoryMappingRules` to determine
   the statutory tax category.
3. Upsert the corresponding `TaxSummaryReport` row (same
   administrationId, fiscalYear, reportingPeriod, taxCategory).
4. Increment `TaxSummaryReport.glTransactionCount` and update amounts
   (`grossAmount`, `deductionsAmount`, `netAmount`).
5. Mark the report as `draft` (until finalized by operator).
6. Emit audit-trail event (GL posting → tax category → summary row).

No operator action needed; aggregation is automatic on GL mutation.

#### Scenario: Posting expense GL automatically updates tax summary

- **GIVEN** GL posting infrastructure operational, T3 enabled
- **WHEN** an operator posts €1,200 debit to account 6000 (office
  supplies, mapped to "deductible-business-expenses") for 2026-04-15
- **THEN** within the same transaction, `TaxSummaryReport`
  (administrationId, 2026, Q2, deductible-business-expenses) is
  updated: `grossAmount` += 1200, status = `draft`, audit trail records
  the GL transaction UUID.

### Requirement: REQ-TAX-008: Annual tax estimates SHALL be computed on-read from GL YTD + configuration, enabling forward projection

`TaxEstimate` is declared as a **materialized view** (computed on
query or via scheduled refresh):

1. On read, the engine gathers all `GLTransaction` rows for the target
   administrationId + fiscal year through snapshotDate.
2. Applies `TaxRegimeConfiguration.categoryMappingRules` to segregate
   income vs. expense.
3. Computes YTD net income (`ytdTaxableIncome - ytdTaxableExpenses`).
4. Projects annual income/expenses at current rate (YTD × 12 /
   months-elapsed).
5. Applies statutory allowances from `TaxRegimeConfiguration`.
6. Computes estimated annual tax (estimated net × rate).
7. Deducts withholding credits (if available from separate
   loonheffing / advance-payment register).
8. Returns estimated net liability (may be negative = refund due).

The estimate is **deterministic, audit-trailed, configuration-versioned**
— same inputs always yield same result; configuration version captured
so retroactive recalculation is possible if rules change.

#### Scenario: Estimate shows forward liability on mid-year review

- **GIVEN** operator reviews estimate on 2026-06-30 (mid-year)
- **WHEN** YTD income = €20,000, expenses = €4,500
- **THEN** estimate projects: annual income = €40,000, annual expenses
  = €9,000, net taxable = €31,000, estimated tax @ 25% = €7,750,
  estimated net liability = €7,750 (assuming no withholding). Operator
  sees liability projection 6 months early, enabling tax planning.

#### Scenario: Estimate uses configuration snapshot for recalculation

- **GIVEN** estimates exist with `configurationVersionId: zzp-2026-v1`
  (25% rate)
- **WHEN** rate changes mid-year to 26%, new configuration `zzp-2026-v2`
  created
- **THEN** new estimates default to v2; operator MAY request
  "recalculate all YTD using v1" to compare liability under old rules,
  without amending the original estimates. Audit trail shows both.

### Requirement: REQ-TAX-009: Tax summary and estimate records SHALL include audit trail links to source GL transactions

Every `TaxSummaryReport` and `TaxEstimate` record MUST include:

- `configurationVersionId` — FK to the `TaxRegimeConfiguration.versionId`
  used for aggregation/calculation.
- `snapshotDate` — date through which GL is included (allows operators
  to spot GL posting lag).
- `glTransactionCount` — count of GL transactions included (enables
  sanity checks).
- Automatic audit trail on mutations (configuration change, GL repost,
  amount recalculation).

Operators can drill down from estimate → GL transaction list to trace
any discrepancy.

#### Scenario: Operator traces estimate divergence to GL posting lag

- **GIVEN** estimate on 2026-03-31 shows €7,900 liability
- **WHEN** estimate on 2026-04-15 (after additional GL postings)
  shows €8,025 liability
- **THEN** operator can click "View GL transactions" to see the
  additional €500 posted on 2026-04-10; snapshotDate field makes lag
  explicit ("estimate as of 2026-04-15, 127 GL transactions included").

### Requirement: REQ-TAX-010: ZZP tax regime SHALL be accessible through shillinq manifest navigation

`src/manifest.json` MUST declare three entries per REQ-TAX-006:

- `Bookkeeping > Tax Filing Prep` — dashboard (KPI + summary table).
- `Bookkeeping > Tax Estimates` — estimates list + detail.
- `Bookkeeping > Tax Configuration` — regime configuration detail
  (administrator-only per RBAC).

Rendering uses `CnDashboardPage`, `CnStatsBlock`, `CnTableWidget`,
`CnDetailPage` from `@conduction/nextcloud-vue`. Zero bespoke Vue
components.

#### Scenario: Operator navigates to tax filing dashboard

- **GIVEN** manifest navigation is live
- **WHEN** an operator clicks `Bookkeeping > Tax Filing Prep`
- **THEN** the dashboard SHALL display: estimated annual liability,
  filing deadline countdown, tax summaries ready count, GL transaction
  count, and a table of `TaxSummaryReport` rows filtered by current
  fiscal year.

#### Scenario: Administrator edits tax configuration

- **GIVEN** `Tax Configuration` detail page loads
- **WHEN** administrator edits `categoryMappingRules` (adding a
  custom-account mapping, adjusting rates, or allowances)
- **THEN** changes MUST save to `TaxRegimeConfiguration`; a new
  `versionId` is assigned (e.g., `zzp-2026-v2`); future estimates use
  the new version; prior estimates remain immutable (audit-trail
  preserved).
