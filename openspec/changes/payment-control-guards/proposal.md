# Change: payment-control-guards

## Why

The market-gap sweep flagged four payment-control gaps. The four-eyes approver≠preparer control
already shipped (payment-run-four-eyes, #459/460). This change verifies the remaining three against
HEAD and builds only the genuine gaps:

1. **Duplicate-payment guard at batch-add/disbursement time.** Verified: `InvoiceDeduplicationService`
   dedups **incoming AR billing** (`BillableInvoice.timeEntryIds/expenseIds`) — it has zero knowledge
   of `PaymentRun`/`APTransaction`. No guard blocks paying an AP invoice already in another batch or
   already paid. The only `PaymentRun` control is four-eyes on `approve`. **Real gap.**
2. **Bank-balance tie-out** (statement closing balance vs GL bank account). Verified: **already
   owned** by `bookkeeping-reconciliation-reports` — `StatementVerifyGuard::verifyStatementBalance()`
   (REQ-REC-002) computes `expectedGLBalance = openingBalance + Σ(GLLine.debit − credit)` and persists
   a non-zero `variance` (flag). The aansluitingen framework (#440) explicitly DEFERS bank-balance
   tie-out to reconciliation-reports and does not own it. **Covered — consume, do not duplicate.** This
   change adds only an evidence test pinning the bad path (non-tying balance → flagged).
3. **Suspense/unmatched worklist ageing + block-close-until-empty.** Verified: `unmatched`/
   `routed-to-suspense` `BankStatementLine` items exist and route to a designated
   `Account.isSuspenseAccount`, but nothing AGES them (no days-outstanding) and the period-close
   precondition set (trial balance, AP/AR checklist, close reason) never checks the suspense worklist —
   `detectUnreconciledBankReceipts()` is a non-blocking info flag only. **Real gap (both parts).**

## What Changes

- **ADDED REQ-PCG-001** — `PaymentRun.export` (approved → exported, the disbursement boundary) gated
  by new `PaymentRunDuplicateGuard`: DENY when a line settles an already-`paid` `APTransaction` or one
  already in another open/executed `PaymentRun` (draft/approved/exported). Export is the enforced
  choke point because `approve.requires` is occupied by `FourEyesPaymentRunGuard` and the OR lifecycle
  engine resolves a single `requires` per transition. Fail-closed. `PaymentRun` schema `version`
  0.2.0 → 0.3.0.
- **ADDED REQ-PCG-002** — new `SuspenseAgeingService` ages `unmatched`/`routed-to-suspense`
  `BankStatementLine` items into a per-administration worklist (days outstanding, count, oldest, total,
  oldest-first), surfaced through `PeriodCloseAssistantService` as an `error`-severity flag.
- **ADDED REQ-PCG-003** — the period `close` transition is blocked while the suspense worklist is
  non-empty: imperatively in `PeriodCloseService::closePeriod()` (the executed path) and declaratively
  via a new `PeriodCloseGuard::suspenseAccountDrained` precondition on `PeriodClose.close` (mirroring
  the existing `mandatoryChecklistResolved` dual pattern). Fail-closed. `FiscalPeriod` schema `version`
  0.2.0 → 0.3.0.
- **Item 2 (covered)** — no new production control; one evidence test
  (`StatementVerifyGuardVarianceTest`) proving a statement that does not tie out flags a non-zero
  variance while a tying balance flags zero.

## Impact

- Affected spec: `payment-control-guards` (new capability; consumes `bookkeeping-reconciliation-reports`
  and extends `bookkeeping-period-close`).
- New code: `lib/Lifecycle/PaymentRunDuplicateGuard.php`, `lib/Service/SuspenseAgeingService.php`,
  `PeriodCloseGuard::suspenseAccountDrained()`, `PeriodCloseService` close blocker,
  `PeriodCloseAssistantService::detectAgedSuspense()` + suspense flag.
- Wiring: `lib/AppInfo/Application.php` (guard registration),
  `register.d/bookkeeping-accounts-payable-core.json` (export.requires + version),
  `register.d/bookkeeping-period-close.json` (close precondition + version), `l10n/en.json`,
  `l10n/nl.json`.
- Tests: `PaymentRunDuplicateGuardTest`, `SuspenseAgeingServiceTest`, `PeriodCloseGuardSuspenseTest`,
  `StatementVerifyGuardVarianceTest`, plus new cases in `PeriodCloseServiceTest` and
  `PeriodCloseFragmentTest`; `PeriodCloseAssistantServiceTest` construction updated.
- Security-relevant: two controls on the outgoing-money and period-integrity boundaries; each ships a
  failing-path test proving the BAD path is REJECTED.
