---
status: done
---

# payment-control-guards Specification

## Purpose

Closes the remaining payment-control gaps found by the market-gap sweep, beyond the already-shipped
four-eyes approver≠preparer control (payment-run-four-eyes): a duplicate-payment guard at the
outgoing-batch disbursement boundary, and a bank-reconciliation suspense worklist that is aged and
that blocks a period close while it is non-empty. The bank-balance tie-out gap the sweep also named
is verified to be ALREADY OWNED by `bookkeeping-reconciliation-reports`
(`StatementVerifyGuard::verifyStatementBalance`, REQ-REC-002) and is consumed, not re-implemented —
this change only pins its bad-path behaviour with an evidence test.

@e2e exclude pure backend controls: server-side lifecycle guards and a service-layer close blocker on register transitions, proven by unit tests exercising the guard/service methods directly — not browser-testable

## Requirements

### Requirement: REQ-PCG-001 — The PaymentRun export transition SHALL block a duplicate or already-paid invoice

The `PaymentRun.export` lifecycle transition (`approved → exported`) — the transition that generates
the SEPA `pain.001` / CSV bank file and disburses money — SHALL be gated by a server-side
duplicate-payment guard (`PaymentRunDuplicateGuard`, wired via the schema's
`transitions.export.requires` DI tag). The guard SHALL DENY the export when any
`paymentLines[].apTransactionRef` in the batch settles an `APTransaction` that is EITHER already in
state `paid`, OR already present in another `PaymentRun` in an open/executed state (`draft`,
`approved` or `exported`). Export is the enforced choke point because the `approve` transition's
`requires` slot is occupied by `FourEyesPaymentRunGuard` and the OpenRegister lifecycle engine
resolves a single `requires` guard per transition. The guard SHALL fail closed: an unidentifiable
batch, a line without an `apTransactionRef`, or any thrown lookup all DENY the export.

#### Scenario: An invoice already queued in another batch is blocked at export

- **WHEN** a `PaymentRun` is exported whose line settles an `APTransaction` already present in
  another `draft`/`approved`/`exported` `PaymentRun`
- **THEN** the export is DENIED server-side and no bank file is written

#### Scenario: An already-paid invoice is blocked at export

- **WHEN** a `PaymentRun` is exported whose line settles an `APTransaction` already in state `paid`
- **THEN** the export is DENIED — paying it again would be a duplicate payment

#### Scenario: A clean batch exports

- **WHEN** every line settles an unpaid invoice that is in no other open/executed batch
- **THEN** the export is ALLOWED

#### Scenario: The duplicate-payment check fails closed

- **WHEN** the batch cannot be identified, a line carries no `apTransactionRef`, or the cross-object
  lookup throws
- **THEN** the export is DENIED (fail-closed), never silently allowed

### Requirement: REQ-PCG-002 — Unmatched bank / suspense items SHALL be aged into a worklist

Bank-reconciliation items in state `unmatched` or `routed-to-suspense` (`BankStatementLine`) SHALL be
aged by `SuspenseAgeingService` into a per-administration worklist that reports, for each item, the
days it has been outstanding (as-of today or an explicit as-of date), plus a summary count, oldest
age and total amount, sorted oldest-first. The worklist SHALL be surfaced to the operator through the
period-close assistant so the ageing is visible before a close is attempted.

#### Scenario: Unmatched items are aged and scoped

- **WHEN** the suspense worklist is computed for an administration as of a date
- **THEN** each `unmatched`/`routed-to-suspense` line belonging to that administration's statements
  is returned with its days-outstanding, sorted oldest-first, with a count, oldest age and total

### Requirement: REQ-PCG-003 — A period SHALL NOT close while the suspense worklist is non-empty

The period `close` transition (`closing → closed`) SHALL be blocked while the administration's
bank-reconciliation suspense worklist (REQ-PCG-002) is non-empty. The block SHALL be enforced
imperatively in `PeriodCloseService::closePeriod()` (the executed close path) and declared
declaratively as a `PeriodCloseGuard::suspenseAccountDrained` precondition on the transition. The
control SHALL fail closed: when the worklist cannot be determined, the close is BLOCKED rather than
treated as empty.

#### Scenario: A non-empty suspense worklist blocks the close

- **WHEN** a period is closed while unmatched/routed-to-suspense bank items remain for its
  administration
- **THEN** the close is REJECTED with a validation error naming the count and oldest age, and the
  period is not persisted as closed

#### Scenario: An empty suspense worklist allows the close

- **WHEN** every bank item is matched or resolved and the mandatory checklist is complete
- **THEN** the close proceeds

#### Scenario: An unreadable suspense worklist fails closed

- **WHEN** the suspense worklist cannot be computed (lookup failure)
- **THEN** the close is BLOCKED (fail-closed), not allowed
