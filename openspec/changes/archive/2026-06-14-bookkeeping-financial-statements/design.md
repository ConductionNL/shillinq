# Design — Financial Statements

**Status:** pr-created

## Context

Financial reporting is mandatory for Dutch regulatory compliance (RJ, IFRS,
local audit requirements). Balance sheets, trial balances, and consolidated
statements must be audit-ready by fiscal-year close. Per ADR-022, consolidation
workflow comes from OR's consolidation extension, not from an app-local
consolidation service. Per ADR-031, balance-sheet aggregation and trial-balance
verification are declarative aggregations, not PHP report services.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire financial-statements surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per
  ADR-031.
- Consume OR's publication and consolidation abstractions — per ADR-022. Zero
  parallel report-generation service.
- Make the spec a **competent-bookkeeper readable contract** —
  Dutch SMB / public-sector financial-close flow recognisable end-to-end
  (GL posting → balance-sheet → trial-balance → consolidation → publication).
- Declare balance-sheet composition and trial-balance verification as pure
  aggregations, not services.
- Support multi-administration consolidation with inter-company eliminations.

## Non-Goals

- No PHP financial-report service, no `FinancialStatementService.php`.
- No multi-currency consolidation translation — T5.
- No tax reconciliation or VAT posting automation — T3.
- No audit-trail export format specialization — T4.

## Decisions

### D1 — Financial statements are read-only aggregates over GL transactions

`BalanceSheet`, `TrialBalance`, and their line details are computed from
GL entries. The statements themselves are registered as entities (for
lifecycle, publication, audit trail) but their line items are queried
on-demand via aggregation. No separate `FinancialStatementLine` table.

### D2 — Balance-sheet composition is declared as aggregation

Balance-sheet totals (Assets, Liabilities, Equity) are computed via
`x-openregister-aggregations` grouping GL entries by Account.accountType
and summing debit/credit per type. No `BalanceSheetService`.

### D3 — Trial balance is declared as aggregation with verification

Trial-balance line listing and the isBalanced check are both computed
via aggregation: sum(debits) = sum(credits) per GL entries for the fiscal period.
No `TrialBalanceService` or background job.

### D4 — Consolidation consumes OR's consolidation extension

`ConsolidatedReport` declares consolidation method + organization references
via FK to `ConsolidationGroup`. The consolidation workflow (inter-company
elimination, reclassification) runs in OR's engine per ADR-022; shillinq
carries no app-local consolidation logic.

If OR's consolidation extension is not yet stable, ADR-031's
exception path applies: a single-method
`OCA\Shillinq\Consolidation\ConsolidationGuard` ships, cited in the spec.

### D5 — Financial-statement lifecycle is draft → final → published

Statements transition from draft (in preparation) to final (GL period closed,
all entries posted) to published (released to stakeholders, immutable). The
lifecycle consumes OR's publication extension per ADR-022.

### D6 — Inter-company eliminations are declared as rules, not computed

`ConsolidationGroup.eliminationRules` captures the elimination policy
(offset-by-FK, percentage-based, custom formula). Execution lives in OR's
consolidation engine or admin-configured fallback per ADR-031 exception.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Balance-sheet composition | OR `x-openregister-aggregations` (ADR-031) | Aggregation query grouping GL entries by Account.accountType |
| Trial-balance generation | OR `x-openregister-aggregations` (ADR-031) | Aggregation query listing GL entries + verification check (debits = credits) |
| Financial-statement lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `BalanceSheet` / `TrialBalance` / `ConsolidatedReport` (draft → final → published) |
| Publication workflow | OR publication extension (if stable; else gap) | Consumed via lifecycle reference; PHP guard fallback per ADR-031 exception if needed |
| Consolidation workflow | OR consolidation extension (if stable; else gap) | Consumed via `ConsolidationGroup.eliminationRules` + lifecycle action; PHP guard fallback per ADR-031 exception if needed |
| GL aggregation by account | T1 `GeneralLedgerEntry` + `Account.accountType` | Query GL entries for fiscal period, group by account type (assets, liabilities, equity), sum amounts |
| Fiscal period boundary | T1 `FiscalYear` | Reference FiscalYear for period start/end; GL queries filtered by period |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions; no custom audit service |
| Manifest navigation | T1 manifest pattern | 4 entries (Balance Sheet, Trial Balance, Consolidations, Consolidated Report) + their pages |

**Net new code in implementation cycle**: 4 schema declarations + 3 lifecycle blocks
+ 2 aggregations + 4 manifest entry pairs. At most 1 single-method PHP guard
(`ConsolidationGuard`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Balance-sheet composition | Declarative (`x-openregister-aggregations`) | Pure SUM query grouping by account type |
| Trial-balance generation | Declarative (`x-openregister-aggregations`) | Pure query + verification check |
| Balance-sheet lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine (draft → final → published) |
| Financial-statement publication | Consumed from OR publication-extension if stable; else single-method `ConsolidationGuard` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Consolidation workflow | Consumed from OR consolidation-extension if stable; else single-method `ConsolidationGuard` per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Inter-company elimination rules | Declarative — declared as object fields on `ConsolidationGroup` | Simple patterns (offset, percentage) declarative; complex custom rules defined by admin |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `ConsolidationGuard`).

## Seed Data

None. Balance sheets, trial balances, and consolidated reports are
operator-generated during fiscal-period close; no templates.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR consolidation-extension not yet stable | Spec shape-neutral; PHP guard fallback (`ConsolidationGuard`, single-method, ~30 LOC) per ADR-031 exception; remove when OR extension lands |
| Balance-sheet aggregation slow with many GL entries | Pre-aggregated cache via OR's aggregation extension if performance gates trip; per-spec optimisation in implementing cycle |
| Inter-company elimination rules require custom logic | Simple patterns declarative; complex rules defined by administration during setup per ADR-031 exception if PHP guard needed |
| Trial-balance verification fails silently | Spec requires `isBalanced` flag computed by aggregation; UI must display verification status prominently |
| Consolidation with proportional method complex | Consolidation method declared; proportional calculations delegated to OR extension or admin-configured rules per ADR-031 |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the four
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 4 new menu entries + their
   pages (additive).
3. If OR's consolidation/publication extensions are not yet stable,
   `lib/Consolidation/ConsolidationGuard.php` ships (single method, ~30 LOC,
   ADR-031 exception annotated).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; financial statements remain queryable but unreferenced.

## Open Questions

1. **OR consolidation/publication extension stability** — resolved in `opsx-ff`
   discovery; OR issue filed if needed.
2. **Inter-company elimination rule complexity** — resolved per ADR-022 review.
3. **Publication workflow trigger** — manual operator publish or automatic
   on fiscal-period close; resolved during implementing cycle's UX review.
