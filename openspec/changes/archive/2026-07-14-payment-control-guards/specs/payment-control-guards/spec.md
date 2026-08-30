# payment-control-guards (delta)

## ADDED Requirements

### Requirement: REQ-PCG-001 — The PaymentRun export transition SHALL block a duplicate or already-paid invoice

The `PaymentRun.export` transition (`approved → exported`) SHALL be gated by
`PaymentRunDuplicateGuard` (wired via `transitions.export.requires`). The guard SHALL DENY the export
when any `paymentLines[].apTransactionRef` settles an `APTransaction` that is already `paid` or is
already in another `PaymentRun` in state `draft`/`approved`/`exported`. Fail-closed on any
indeterminate check.

#### Scenario: An invoice already queued in another batch is blocked at export

- **WHEN** a `PaymentRun` is exported whose line settles an `APTransaction` already present in
  another `draft`/`approved`/`exported` `PaymentRun`
- **THEN** the export is DENIED server-side and no bank file is written

#### Scenario: An already-paid invoice is blocked at export

- **WHEN** a `PaymentRun` is exported whose line settles an `APTransaction` already in state `paid`
- **THEN** the export is DENIED

#### Scenario: The duplicate-payment check fails closed

- **WHEN** the batch cannot be identified, a line carries no `apTransactionRef`, or the lookup throws
- **THEN** the export is DENIED (fail-closed)

### Requirement: REQ-PCG-002 — Unmatched bank / suspense items SHALL be aged into a worklist

`SuspenseAgeingService` SHALL age `unmatched`/`routed-to-suspense` `BankStatementLine` items into a
per-administration worklist (days outstanding, count, oldest age, total, oldest-first), surfaced
through the period-close assistant.

#### Scenario: Unmatched items are aged and scoped

- **WHEN** the suspense worklist is computed for an administration as of a date
- **THEN** each in-scope line is returned with its days-outstanding, sorted oldest-first, with a
  count, oldest age and total

### Requirement: REQ-PCG-003 — A period SHALL NOT close while the suspense worklist is non-empty

The period `close` transition SHALL be blocked (imperatively in `PeriodCloseService::closePeriod()`
and declaratively via a `PeriodCloseGuard::suspenseAccountDrained` precondition) while the suspense
worklist is non-empty; fail-closed when it cannot be determined.

#### Scenario: A non-empty suspense worklist blocks the close

- **WHEN** a period is closed while unmatched/routed-to-suspense bank items remain
- **THEN** the close is REJECTED and the period is not persisted as closed

#### Scenario: An unreadable suspense worklist fails closed

- **WHEN** the suspense worklist cannot be computed
- **THEN** the close is BLOCKED (fail-closed)
