# Design — Fixed Assets & Depreciation

**status: pr-created**

## Context

Asset management and depreciation tracking are fundamental to Dutch SMB
accounting. The Dutch tax system requires precise asset-depreciation
tracking for each business year with support for multiple depreciation
methods. Per ADR-022, depreciation calculations come from OR's
declarative business logic extension, not from an app-local depreciation
service. Per ADR-031, asset analytics are declarative aggregations, not
PHP report services.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire fixed-asset and depreciation surface as
  **declarative metadata** — schemas + lifecycle + aggregations +
  manifest entries — per ADR-031.
- Support multiple depreciation methods (linear, declining-balance,
  units-of-production) with **precise float handling** respecting
  System Settings precision configuration.
- Declare depreciation-schedule tracking per asset per fiscal year,
  with automatic yearly calculations materialised as GL postings.
- Handle **internal asset transfers** with proportional depreciation
  schedule adjustment and GL impact.
- Make the spec a **competent-accountant readable contract** — Dutch
  SMB asset management recognisable end-to-end (acquisition intake,
  registration, depreciation schedule, yearly expense posting,
  internal transfer, retirement).
- Declare GL posting patterns so T1 can materialise asset acquisition
  and depreciation-expense transactions.

## Non-Goals

- No PHP depreciation service, no `DepreciationCalculatorService.php`.
- No impairment testing or asset revaluation — T3.
- No depreciation-method switching mid-lifecycle — deferred.
- No multi-currency asset valuation — T5.
- No lease accounting (IFRS 16) — future phase.

## Decisions

### D1 — Fixed assets are a sub-ledger that materialises GL transactions

Symmetric to D1 of `bookkeeping-accounts-payable-core` and
`bookkeeping-accounts-receivable-core`: `FixedAsset` is a sub-ledger
register; asset acquisition materialises a balanced `GLTransaction` per
the T1 `JournalEntry` pattern. Yearly depreciation materialises an
expense `GLTransaction` (debit depreciation expense, credit accumulated
depreciation).

### D2 — Depreciation is declared as lifecycle-driven automatic calculations

`DepreciationSchedule` declares the method (linear, declining-balance,
units-of-production), annual rate, and period. The yearly depreciation
posting is driven by OR's declarative business logic extension or, if
not yet stable, a single-method `DepreciationCalculator` per ADR-031
exception. Shillinq carries no app-local depreciation service.

### D3 — Float Precision setting governs depreciation-rate rounding

Depreciation rates MUST respect the Float Precision setting configured
in Nextcloud System Settings. Rates are rounded to the configured
decimal places at calculation time, ensuring consistent display and
calculation results across all asset records in an administration.

### D4 — Depreciation-schedule is immutable; changes tracked in audit trail

`DepreciationSchedule` records are immutable (append-only). Changes to
depreciation method, useful life, or residual value create new
schedule records with an effective-date transition, logged in the
audit trail. This preserves the historical record for tax compliance.

### D5 — Internal asset transfers adjust depreciation schedules proportionally

When an asset is transferred between cost centers or departments (per
internal invoice or manual transfer), the depreciation schedule remains
unified but may be split or allocated across the transfer targets. The
spec declares the transfer pattern; GL postings balance to zero for
internal transfers.

### D6 — Asset depreciation aggregations enable cost-center reporting

Depreciation tracking by cost center, by depreciation method, by fiscal
year, and accumulated depreciation by asset are declarative
`x-openregister-aggregations` queries. No PHP report service.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Fixed asset lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `FixedAsset` (active → retired); GL postings per T1 pattern |
| Depreciation calculation | OR `x-openregister-aggregations` (if stable; else gap) | Yearly calculation via lifecycle action; single-method `DepreciationCalculator` fallback per ADR-031 exception if needed |
| Float Precision configuration | Nextcloud System Settings API | Queried at depreciation-calculation time; fallback to 2 decimal places (Dutch standard) |
| GL materialization (acquisition + expense) | T1 `JournalEntry` pattern | Same lifecycle action shape; balanced posting templates |
| Depreciation tracking by period | OR `x-openregister-aggregations` | GROUP BY `(assetId, fiscalYear)`, SUM accumulated depreciation |
| Useful-life defaults | Industry standards (codified during UX review) | Bundled with seed data in register; customisable per administration |
| Asset revaluation and impairment | Deferred to T3 | Not in scope; future audit trail extension |
| Internal transfer handling | Lifecycle action with proportional split | Transfer action adjusts depreciation schedule; GL posting balances to zero |
| Audit trail and change tracking | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions and schedule changes |
| Manifest navigation | T1 manifest pattern | 3 entries (Fixed Assets, Depreciation Schedules, Depreciation Expense) + their pages |

**Net new code in implementation cycle**: 2 schema declarations + 1
lifecycle block + 3 aggregations + 3 manifest entry pairs. At most
1 single-method PHP calculator (`DepreciationCalculator`) gated by ADR-031
exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Fixed asset lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Depreciation calculation | Consumed from OR calculation extension if stable; else single-method `DepreciationCalculator` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Depreciation aggregations | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM + method logic |
| Float Precision rounding | Declarative — queried from System Settings at calculation time | Pure configuration dependency |
| Internal transfer handling | Lifecycle action with proportional schedule adjustment | No new service; GL posting balances |
| Depreciation expense GL posting | Lifecycle action invoking T1's materialization extension | No new service |
| Asset retirement | Lifecycle transition with audit-trailed reason | No new service |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `DepreciationCalculator`).

## Seed Data

3–5 realistic example assets per Dutch SMB context:
- Example 1: Company vehicle (€25,000, 5-year linear depreciation, active)
- Example 2: Office building (€200,000, 20-year linear depreciation, active)
- Example 3: Computer equipment (€5,000, 3-year declining-balance depreciation, active)
- Example 4: Retired asset (€3,000, fully depreciated, retired)

Each with corresponding `DepreciationSchedule` records showing yearly
calculations for the current fiscal year.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Float Precision configuration unavailable at runtime | Spec captures the dependency explicitly; fallback to 2 decimal places (Dutch accounting standard) |
| OR depreciation-calculation extension not yet stable | Spec shape-neutral; single-method `DepreciationCalculator` fallback (~30 LOC) per ADR-031 exception; remove when OR extension lands |
| Internal transfer handling creates GL imbalance | Transfer action enforces zero-balance GL posting; audit trail tracks allocation splits |
| Useful-life estimates may be challenged in tax audit | Immutable depreciation-schedule records with audit trail; implementing cycle ensures audit visibility |
| Depreciation-method switching mid-lifecycle | Deferred to T3 (impairment/revaluation phase); requires retroactive adjustment flagged as out-of-scope for T2 |
| Depreciation calculation complexity across methods | Methods (linear, declining-balance, units-of-production) are parameterized in schema; calculation engine is single implementation point |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the two
   schemas (`FixedAsset`, `DepreciationSchedule`) (additive — no
   existing schema changes).
2. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).
3. 3–5 seed assets are loaded into the register (via `importFromApp()`
   pattern).
4. If OR's calculation extension is not yet stable,
   `lib/Lifecycle/DepreciationCalculator.php` ships (single method,
   ~30 LOC, ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; asset records remain queryable but unreferenced.

## Open Questions

1. **Float Precision API availability** — is Nextcloud System Settings
   accessible via IAppConfig or native API? Resolved in `opsx-ff`
   discovery.
2. **Depreciation-method switching** — permitted mid-lifecycle (with
   retroactive adjustment) or deferred to T3? Resolved per ADR-022
   review and tax-compliance guidance.
3. **Useful-life defaults** — what defaults ship with seed data for
   vehicle (5y), building (20y), equipment (3y)? Customisable per
   administration? Resolved during implementing cycle's UX review.
4. **Impairment-test workflow** — required for T2 or deferred to T3?
   Resolved per product roadmap.
