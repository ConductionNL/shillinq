# Proposal: bookkeeping-reconciliation-reports

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`BankReconciliation`, `ReconciliationMatch`, `ReconciliationReport`) +
`x-openregister-lifecycle` consuming OR utilities for matching + aggregations + manifest entries.
No PHP reconciliation service. Aggregations for statement variance + unmatched
item tracking.

## Summary

Introduce the **bank reconciliation reports** capability for Shillinq
as one of the T4 advanced engine features (per
`adr-001-bookkeeping-tier-roadmap.md`). This capability enables
accountants to **reconcile bank statements against internal transaction
records** — verifying statement balances, matching transactions to GL
entries, handling unmatched items, and generating variance reports.

The change declares the `BankReconciliation`, `ReconciliationMatch`,
and `ReconciliationReport` registers; the reconciliation workflow
lifecycle (`draft → in-progress → verified → closed`) with automatic
and manual matching support; unmatched item resolution workflow;
statement balance verification as a precondition; and variance
reporting as aggregations. Consumes the T2 `bookkeeping-bank-reconciliation`
foundation but adds reporting, bulk operations, and audit workflow.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`bookkeeping-bank-reconciliation`](../add-shillinq-bookkeeping-compliance/specs/bookkeeping-bank-reconciliation/spec.md)
(T2 transaction matching rules and payment match events),
[`bookkeeping-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md)
(AR invoices matched by bank reconciliation),
[`bookkeeping-accounts-payable-core`](../add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-payable-core/spec.md)
(AP invoices matched by bank reconciliation).

## Motivation

Bank reconciliation is a **mandatory accounting control** — the bank
statement must tie to the general ledger within rounding tolerance.
T2 provides the transaction-matching engine; T4 adds the reporting,
workflow, and variance tracking that auditors and accountants require
to close the books.

The legacy AP/AR draft cluster from intelligence-db
(`competitor_features` with `app_slug=shillinq`) calls out reconciliation
reporting + unmatched item resolution + statement variance as
operational completions alongside AR/AP dunning.

This is one of seven T4 capability changes; this proposal scopes
only the reconciliation-reports slice.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-reconciliation-reports`); declares 3 new registers
  (`BankReconciliation`, `ReconciliationMatch`, `ReconciliationReport`) with
  lifecycles and aggregations; adds 3 manifest navigation entries (Reconciliations,
  Unmatched Items, Variance Report).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`.

## Scope

### In Scope

- One new capability spec (`bookkeeping-reconciliation-reports`) —
  see the `specs/` folder.
- The `BankReconciliation` register tracking reconciliation state per
  bank account + period (statement date, opening balance, closing balance,
  expected GL balance, variance).
- The `ReconciliationMatch` register recording individual matched
  transactions (GL transaction UUID, bank statement line, match algorithm,
  confidence score, manual override flag).
- The `ReconciliationReport` register capturing reconciliation outcomes
  for audit trails (reconciliation date, matched count, unmatched GL
  items, unmatched bank items, variance amount, preparer, verifier).
- Reconciliation workflow lifecycle (`draft → in-progress → verified → closed`)
  with automatic matching + manual match override + bulk unmatched item
  resolution.
- Unmatched item resolution: marking items as reconciling differences (timing,
  pending, adjustment) with audit-trailed reason.
- Statement balance verification as precondition on `draft → in-progress` transition.
- Variance reporting as `x-openregister-aggregations` query (by account, by
  period, by variance type: timing, adjustment, error).
- Bulk operation support: matching multiple GL lines at once, auto-clearing
  small unmatched amounts, exporting reconciliation results.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Bank data import** — T5. T4 assumes statements are loaded into
  `BankStatement` via a separate bank-connector capability.
- **Automated matching algorithms** — T4 declares the match schema and
  workflow; algorithm tuning (fuzzy matching, multi-line clustering,
  threshold adjustment) is a T5 optimization.
- **Multi-currency reconciliation** — T5.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-reconciliation-reports`** — declares the three
registers, the lifecycle (automatic matching + manual override), the
statement-balance verification precondition, the unmatched-item
resolution workflow, and variance reporting aggregations.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-REC-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions, T2 bank-reconciliation
spec, and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 3 new schemas
  (`BankReconciliation`, `ReconciliationMatch`, `ReconciliationReport`);
  declares lifecycle on `BankReconciliation`, aggregations on variance
  reporting.
- `src/manifest.json` — adds 3 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services.
- No new bespoke Vue components.

## Cross-Project Dependencies

- **T2 bank-reconciliation** — depends on the T2 transaction-matching
  engine and `ReconciliationMatch` events.
- **T2 AR/AP core** — reconciliation matches against AR invoices and
  AP invoices declared in T2.
- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `x-openregister-aggregations`.

## Risks

### Risk 1: Matching algorithm not yet stable

**Severity**: Medium
**Mitigation**: T4 spec declares the match schema (GL transactionId,
bank statement line ID, confidence score, override flag); the matching
algorithm lives in T2 and is stable. T4 can ship without algorithm
tuning. If fuzzy/multi-line matching is needed, it becomes a T5 task.

### Risk 2: Statement balance verification requires GL close period lock

**Severity**: Low
**Mitigation**: REQ-REC-002 declares the balance-verification guard on
the `draft → in-progress` transition; the guard depends on the GL
period being marked as closed or verified (T2 responsibility). If GL
period lock is not yet available, the guard becomes optional per ADR-031.

### Risk 3: Variance tolerance (rounding, timing) is not yet defined

**Severity**: Low
**Mitigation**: REQ-REC-003 declares variance as `|expected GL balance - statement closing|`.
Tolerance thresholds (e.g., ±0.01) are a T5 configuration; T4 ships
the raw variance, auditors configure acceptance thresholds per account.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — reconciliation records remain queryable.

## Open Questions

1. **GL period lock availability** — REQ-REC-002 depends on T2's
   FiscalPeriod.isClosed or similar. Resolved in `opsx-ff` discovery;
   if not available, guard becomes optional per ADR-031 exception.
2. **Matching algorithm tuning** — fuzzy matching / multi-line clustering
   are possible but deferred to T5. T4 ships exact-match + manual override.
3. **Variance tolerance thresholds** — resolved during implementing cycle's
   UX review with accountant personas (e.g., small unmatched amounts <€0.01
   auto-clear, timing differences tracked separately).
