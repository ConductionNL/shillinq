# Design — Bank Reconciliation Reports

## Context

Bank reconciliation is the accountant's **verification control** — the bank
statement (cash position) must match the general ledger within rounding
tolerance. T2 provides the transaction-matching engine; T4 adds the reporting,
workflow, and variance tracking needed to close the books.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire bank reconciliation workflow as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Enable accountants to **verify statement balances**, **match transactions**,
  **resolve unmatched items**, and **track variance** — all audit-trailed.
- Make the spec a **competent-accountant readable contract** — bank reconciliation
  flow recognisable end-to-end (statement load, balance verification, transaction
  matching, unmatched resolution, variance reporting, period close).
- Consume T2's transaction-matching foundation without duplicating matching logic.
- Support both **automatic matching** (T2 rules engine) and **manual override**
  (operator review + confirmation).

## Non-Goals

- No PHP reconciliation service. No `ReconciliationEngine.php` or
  `MatchingAlgorithm.php` in T4; matching logic lives in T2.
- No fuzzy/multi-line matching algorithms — T4 is exact match + manual.
  Fuzzy matching is a T5 optimization.
- No automated variance tolerance rules — T4 reports raw variance;
  auditors configure tolerance thresholds per account in T5.
- No multi-currency reconciliation — T5.

## Decisions

### D1 — Reconciliation is a bounded workflow per bank account + period

`BankReconciliation` captures one account's statement reconciliation for
one date range. A reconciliation is **not** a live dashboard; it's a
bounded audit artifact with opening balance, closing balance, matched
transactions, unmatched items, and variance.

### D2 — Matching is delegated to T2; T4 records match outcomes

`ReconciliationMatch` records the **outcome** of a match (GL transaction UUID,
bank line ID, algorithm used, confidence score). The matching decision comes
from T2's engine or from operator manual override; T4 doesn't compute matches,
only records them.

### D3 — Statement balance verification is a precondition on reconciliation start

`draft → in-progress` transition requires that the statement closing balance
equals (GL account balance at period start + net GL activity for the period).
If they don't align, the operator must investigate before proceeding.

### D4 — Unmatched items are resolution artifacts, not errors

An item is "unmatched" if no GL transaction corresponds to the bank line (or
vice versa). Unmatched items are resolved by marking them as: timing (expected
to match in next period), pending (awaiting bank feedback), or adjustment
(non-transaction difference). All resolutions are audit-trailed.

### D5 — Variance reporting is an aggregation

Variance is `|expected GL balance - statement closing|`. Reported by account,
by period, by variance type (timing, adjustment, error). Pure aggregation;
no `VarianceReportService.php`.

### D6 — Reconciliation lifecycle is bounded and auditable

Lifecycle: `draft` (operator initiates) → `in-progress` (statement verified,
matching underway) → `verified` (operator confirms all items resolved) →
`closed` (period close locked, no further edits). All transitions are
audit-trailed with preparer/verifier/timestamp.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Transaction matching | T2 `bookkeeping-bank-reconciliation` (matching rules + events) | T2 emits match events; T4 records outcomes in `ReconciliationMatch` |
| Reconciliation workflow | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `BankReconciliation` (`draft → in-progress → verified → closed`); statement-balance verification as precondition guard |
| Variance reporting | OR `x-openregister-aggregations` | GROUP BY `(bankAccountId, period, varianceType)` excluding closed reconciliations |
| Unmatched item resolution | New (no prior pattern) | `ReconciliationMatch` with `resolutionStatus` enum + `resolutionReason` text |
| Manifest navigation | T1 manifest pattern | 3 entries (Reconciliations, Unmatched Items, Variance) + their pages |
| Audit trail | T2 `bookkeeping-audit-trail` (consume from OR) | Automatic on lifecycle transitions + manual override edits |
| AR/AP invoice matching | T2 AR/AP (via foreign keys) | `ReconciliationMatch` carries FK to `ARInvoice` or `APTransaction` UUID if matched to sub-ledger item |

**Net new code in implementation cycle**: 3 schema declarations + 1 lifecycle block
+ 2 aggregations + 3 manifest entry pairs. No new PHP service classes.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Reconciliation workflow | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Statement balance verification | Lifecycle guard (declarative or PHP guard per ADR-031 exception) | Requires GL account balance lookup; if GL balance query is declarative, guard is declarative; else single-method `StatementVerifyGuard` |
| Transaction matching | Consumed from T2 events; recorded in `ReconciliationMatch` | T2 owns matching logic; T4 is outcome recorder |
| Variance calculation | Declarative (`x-openregister-aggregations`) | Simple subtraction: GL balance - statement closing |
| Unmatched item resolution | Declarative state + enum (status + reason text) | Pure classification; no service logic |

No service class authored in this envelope (subject to ADR-031 exception: at most
one single-method `StatementVerifyGuard` if GL balance lookup is not yet declarative).

## Seed Data

None. Bank statements and reconciliations are operator-created on import;
no templates.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| GL period lock not yet available for balance-verification guard | Guard becomes optional; spec shape-neutral. Single-method `StatementVerifyGuard` per ADR-031 exception if needed; remove when GL period lock lands |
| Matching algorithm may be insufficient for real-world variance | T4 ships exact-match + manual override; fuzzy matching + multi-line clustering are T5 optimizations |
| Variance tolerance thresholds are subjective | T4 reports raw variance; auditors configure acceptance per account in T5 settings |
| Unmatched items may accumulate if not resolved promptly | Manifest includes "Unmatched Items" page for bulk review + resolution; lifecycle guards prevent close until verifier confirms all resolved (per REQ-REC-006) |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the three
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; reconciliation records remain queryable but unreferenced.

## Open Questions

1. **GL period lock or balance verification API** — resolved in `opsx-ff`
   discovery; if unavailable, guard becomes optional per ADR-031.
2. **Matching algorithm completeness** — exact-match + manual override
   sufficient for T4? Fuzzy/multi-line deferred to T5? Resolved during
   implementing cycle's requirements review.
3. **Variance tolerance thresholds** — resolved during implementing cycle's
   UX review with accountant personas.
