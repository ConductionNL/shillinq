# Proposal: bookkeeping-financial-statements

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`BalanceSheet`, `TrialBalance`, `ConsolidatedReport`,
`ConsolidationGroup`) + `x-openregister-lifecycle` + aggregations +
manifest entries. No PHP financial-report-service classes are authored
(subject to ADR-031 exception: at most one single-method `ConsolidationGuard`
if OR's consolidation extension is not yet stable).

## Summary

Introduce the **financial statements and reporting** capability for Shillinq
as one of the T2 compliance + operations capabilities (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability **enables
comprehensive financial reporting for public-sector and SMB compliance**.
The change declares the `BalanceSheet`, `TrialBalance`, `ConsolidatedReport`,
and `ConsolidationGroup` registers; the financial-statement lifecycle
(draft → final → published); group consolidation with inter-company
eliminations consuming OR's consolidation abstractions per ADR-022;
balance-sheet composition via aggregation; trial-balance verification;
and real-time consolidated reporting across multiple administrations.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`bookkeeping-chart-of-accounts`](../bookkeeping-chart-of-accounts/proposal.md)
(Account master data),
[`bookkeeping-general-ledger`](../bookkeeping-general-ledger/proposal.md)
(GL transactions for period closure).

## Motivation

Financial reporting is mandatory for Dutch regulatory compliance (RJ, IFRS,
local audit requirements). Balance sheets, trial balances, and consolidated
statements must be audit-ready by fiscal-year close. Per ADR-022, consolidation
workflow comes from OR's consolidation extension, not from an app-local
consolidation service; per ADR-031, balance-sheet aggregation and trial-balance
verification are declarative aggregations, not PHP report services.

The legacy financial-reporting draft cluster from intelligence-db calls out
balance-sheet composition + trial-balance + consolidated reporting as top-tier
financial-control features for public-sector and SMB bookkeeping.

This is one of eight T2 capability changes; this proposal scopes only the
financial-statements core slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-financial-statements`); declares 4 new registers
  (`BalanceSheet`, `TrialBalance`, `ConsolidatedReport`, `ConsolidationGroup`)
  with lifecycles and aggregations; adds 4 manifest navigation entries
  (Balance Sheet, Trial Balance, Consolidations, Consolidated Report).
- [ ] Project: openregister — no source changes; consumes existing
  consolidation extension (if stable; else ADR-031 exception).

## Scope

### In Scope

- One new capability spec (`bookkeeping-financial-statements`) —
  see the `specs/` folder.
- The `BalanceSheet` register showing assets, liabilities, and equity
  at a fiscal-period snapshot with status (draft/final/published).
- The `TrialBalance` register listing all GL accounts with debit/credit
  balances for period verification, including isBalanced flag and
  preparedBy audit trail.
- The `ConsolidatedReport` register aggregating financials across
  multiple administrations with consolidation method and inter-company
  elimination tracking.
- The `ConsolidationGroup` register defining which organizations are
  consolidated together, consolidation method (full/proportional/equity),
  and elimination rules.
- Financial-statement lifecycle (draft → final → published) consuming
  OR's publication extension per ADR-022 or single-method fallback
  per ADR-031.
- Balance-sheet composition as `x-openregister-aggregations` query
  (sum GL entries by account type: assets, liabilities, equity).
- Trial-balance verification as aggregation (total debits = total credits).
- Consolidation with inter-company elimination rules declared as
  object fields; consolidation trigger via operator action or scheduled
  workflow.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Multi-currency consolidation** — T5.
- **Tax reconciliation** — T3.
- **Audit-trail export formats** — T4.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-financial-statements`** — declares the four registers,
the lifecycle (consuming OR publication extension or fallback),
balance-sheet composition + trial-balance verification aggregations,
consolidation with elimination rules, and manifest entries.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-FS-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.66`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 new schemas
  (`BalanceSheet`, `TrialBalance`, `ConsolidatedReport`, `ConsolidationGroup`);
  declares lifecycle on financial statements, aggregations on balance-sheet
  composition + trial-balance verification.
- `src/manifest.json` — adds 4 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `ConsolidationGuard` if OR's consolidation extension
  is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on publication extension (ADR-022 — if
  stable; else ADR-031 exception path), `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T1 chart-of-accounts** — depends on `bookkeeping-chart-of-accounts`
  for Account master data.
- **T1 general-ledger** — depends on `bookkeeping-general-ledger`
  for GL entries that feed balance-sheet and trial-balance aggregations.

## Risks

### Risk 1: Consolidation extension not yet stable on OR

**Severity**: Medium
**Mitigation**: If OR's consolidation extension is still draft
at T2 implementation time, the spec captures the gap, files an OR
issue, and the implementing cycle MAY ship a single-method
`OCA\Shillinq\Consolidation\ConsolidationGuard` per ADR-031 §"PHP guards
remain a legitimate seam". The guard is removed once OR's
extension lands. Spec is shape-neutral.

### Risk 2: Balance-sheet aggregation performance with many GL entries

**Severity**: Low-Medium
**Mitigation**: Per ADR-031 § "Aggregations may require caching",
pre-aggregated cache via OR's aggregation extension if performance gates
trip; per-spec optimisation in implementing cycle.

### Risk 3: Inter-company elimination rules may require domain logic

**Severity**: Low
**Mitigation**: REQ-FS-006 declares elimination rules as object fields
on `ConsolidationGroup`. Simple elimination patterns (offset-by-FK,
percentage-based) are declarative; complex custom rules defined by
administration during setup. PHP guard fallback per ADR-031 if needed.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — financial statements remain queryable.

## Open Questions

1. **Consolidation extension stability on OR** — see Risk 1; resolved in
   `opsx-ff` discovery; OR issue filed if needed.
2. **Elimination rule complexity** — see Risk 3; resolved per
   ADR-022 review.
3. **Publication workflow** — manual operator publish or automatic
   on fiscal-period close; resolved during the implementing cycle's UX review.
