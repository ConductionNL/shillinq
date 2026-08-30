# Design: payment-control-guards

## Verify-first verdicts (truth against HEAD)

| # | Gap | Verdict | Evidence |
| --- | --- | --- | --- |
| 1 | Duplicate-payment at batch-add | **REAL GAP — build** | `InvoiceDeduplicationService::deduplicateSourceIds()` dedups `BillableInvoice` billing sources (AR), not `PaymentRun`/`APTransaction`. `FourEyesPaymentRunGuard` only checks approver≠preparer; it never inspects `paymentLines`. No class references duplicate/already-paid on the payment side. |
| 2 | Bank-balance tie-out | **COVERED — consume** | `StatementVerifyGuard::verifyStatementBalance()` (bookkeeping-reconciliation-reports, REQ-REC-002) computes `expectedGLBalance` and persists a `variance` on `BankReconciliation`. Aansluitingen (#440) proposal.md explicitly lists bank-balance tie-out as Out of Scope, deferred to reconciliation-reports "rather than building a THIRD parallel bank-reconciliation register family". Only gap: no test pinned the bad path → added one. |
| 3a | Suspense ageing | **REAL GAP — build** | `unmatched`/`routed-to-suspense` `BankStatementLine` exist (`valueDate`/`transactionDate` present) but nothing computes days-outstanding; `StatementParser::noUnmatchedLinesRemain()` is per-statement boolean only. |
| 3b | Block-close on non-empty suspense | **REAL GAP — build** | `PeriodCloseService::closePeriod()` + `PeriodCloseGuard` enforce trial-balance, AP/AR checklist, close-reason, backdating — never the suspense worklist. `detectUnreconciledBankReceipts()` is a non-blocking `info` flag. |

## Item 1 — duplicate-payment guard placement

Batch construction in this app is generic OpenRegister ObjectService CRUD (no "add line" controller,
no dedicated line-add transition), so the only declarative choke points are the `PaymentRun`
lifecycle transitions. The OR `LifecycleValidationListener` reads a **single** `requires` string per
transition; `approve.requires` is already `FourEyesPaymentRunGuard`. Therefore the duplicate check is
placed on **`export`** (approved → exported) — the transition `PaymentRunExportService` drives through
`ObjectService::saveObject()` (so the engine runs the guard) and the definitive point before the SEPA
file is written and money leaves. `PaymentRunDuplicateGuard::check()`:

1. resolve batch id (`id` / `@self.id`); empty → DENY `MESSAGE_NO_OBJECT`.
2. collect `paymentLines[].apTransactionRef`; a line without one → DENY `MESSAGE_INDETERMINATE`
   (cannot be checked, must not be silently paid); no lines → ALLOW.
3. for each ref, resolve the `APTransaction`; state `paid` → DENY `MESSAGE_ALREADY_PAID`.
4. scan other `PaymentRun`s (exclude self id) in state `draft`/`approved`/`exported`; a shared ref →
   DENY `MESSAGE_ALREADY_BATCHED`. `reconciled` runs are terminal and their invoices are `paid`, so
   the already-paid check covers them (excluded here to avoid the two-terminal-batch case).
5. any thrown lookup → DENY `MESSAGE_INDETERMINATE` (fail-closed).

Ref matching tolerates `id`/`uuid`/`slug` shapes. Two approved batches that both hold one invoice is a
genuine error state that this guard makes non-disbursable (fail-safe); the operator removes/deletes one
(controller has `delete`) — far preferable to a silent double payment.

## Item 2 — consume the existing tie-out

No new production code. `StatementVerifyGuardVarianceTest` drives `verifyStatementBalance()` with a
recording ObjectService stub: opening 5000 + GL net 0 = expected 5000; closing 5100 ⇒ the tie-out
fails by 100 and a `variance` of 100.00 is persisted (flagged), transition still allowed (REQ-REC-002
"variance surfaces warning but allows proceed"); a tying balance flags 0.00. This is the missing
evidence that the covered capability actually flags a mismatch.

## Item 3 — suspense ageing + close blocker

`SuspenseAgeingService.agedUnmatchedItems(administrationId, asOf)` queries `BankStatementLine` per
unresolved status (`unmatched`, `routed-to-suspense` — OR filters cannot express `IN`), scopes lines
to the administration's `BankStatement` ids, computes `daysOutstanding = floor((asOf − valueDate)/1d)`
(floored at 0), and returns a summary sorted oldest-first. Two entry points:

- `agedUnmatchedItems()` — reporting path; catches and degrades to an empty worklist so the close
  assistant never crashes. Consumed by `PeriodCloseAssistantService::detectAgedSuspense()` → an
  `error`-severity `flag-suspense`.
- `hasUnresolvedItems()` — CONTROL path; does NOT swallow so callers FAIL CLOSED.

The close block lives in **both** places (mirroring the existing `mandatoryChecklistResolved` dual
pattern, because the OR engine consumes `requires` but not `preconditions`):

- Enforced: `PeriodCloseService::closePeriod()` calls `hasUnresolvedItems()` inside try/catch — a
  throw → `PeriodCloseException` (fail-closed block); non-empty → `PeriodCloseException` naming the
  count + oldest age; the period is not persisted.
- Declared: `PeriodCloseGuard::suspenseAccountDrained()` added to `PeriodClose.close.preconditions`
  (resolves `SuspenseAgeingService` lazily from the container to keep the guard constructor unchanged);
  no-administration-scope → allow; fail-closed on throw.

## ADR-031 decision (dialect / control placement)

Both new controls are **lifecycle transition guards** (ADR-031 register-declared `requires` /
`preconditions` DI-tag dialect), read-only, never notifications. The duplicate guard is a lifecycle
guard because the check is a cross-object lookup expressible as a transition precondition. The suspense
close block is the imperative arm (cross-schema BankStatementLine ageing is logic the declarative DSL
cannot express) mirrored by a declarative precondition — exactly the pattern `mandatoryChecklistResolved`
already follows. No bespoke actor/state fields are introduced; the audit trail and the existing
`BankStatementLine.status` / `Account.isSuspenseAccount` remain authoritative.

## Seed Data

No new seed objects. The AP-core seed already ships an approved `PaymentRun` and the period-close seed
ships worked example periods; the guards read whatever `PaymentRun`/`APTransaction`/`BankStatementLine`
objects exist at runtime. Adding a `requires` / precondition does not require new fixtures — the seeded
approved batch has no duplicate/paid line and the seeded closable periods have empty suspense worklists,
so seeded flows stay green.

## Test strategy

- `PaymentRunDuplicateGuardTest` — **already-batched → REJECTED** and **already-paid → REJECTED** (the
  failing-path proofs), plus clean-allowed, self-not-blocked, uuid-match, reconciled-not-blocking,
  no-object/no-ref/throw fail-closed.
- `SuspenseAgeingServiceTest` — ages + scopes + sorts oldest-first; `hasUnresolvedItems` reflects the
  count; empty for an administration without items.
- `PeriodCloseGuardSuspenseTest` — **non-empty suspense → close DENIED** (failing path), empty → allowed,
  no-scope → allowed, unreadable → fail-closed.
- `PeriodCloseServiceTest` — **non-empty suspense → `PeriodCloseException`, period NOT persisted**
  (failing path), empty → closes.
- `StatementVerifyGuardVarianceTest` — non-tying balance → 100.00 variance flagged; tying → 0.00.
- `PeriodCloseFragmentTest` — the `close` transition declares the `suspenseAccountDrained` precondition.
