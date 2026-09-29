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

### Requirement: A single invoice can be blocked from payment (REQ-BPR-003)

An `APTransaction` SHALL carry `paymentBlocked` and `paymentBlockReason`.
A bookkeeper SHALL set and release the block from the AP transaction
detail page, and a reason MUST be given to block. The block SHALL NOT
change the invoice's lifecycle state, and every change SHALL appear in the
object's audit trail with actor and time.

#### Scenario: A bookkeeper holds an invoice until the credit note arrives

- GIVEN a bookkeeper on the detail page of overdue invoice 2026-0419 of EUR 605.00
- WHEN they press Block payment and enter the reason Wacht op creditnota
- THEN the page shows the invoice as payment blocked with that reason
- AND the invoice is still overdue
- AND the audit trail names the bookkeeper and the time

#### Scenario: A block needs a reason

- GIVEN the Block payment dialog on an invoice
- WHEN the bookkeeper confirms with an empty reason
- THEN the block is not saved and the dialog asks for a reason

### Requirement: A supplier can be blocked from payment (REQ-BPR-004)

A `Payee` SHALL carry `paymentBlocked` and `paymentBlockReason`, set and
released from the payee detail page with a mandatory reason. A payment
block on a payee SHALL NOT stop its invoices being received, issued or
posted.

#### Scenario: A bookkeeper blocks a supplier whose IBAN changed

- GIVEN a bookkeeper on the payee detail page of Schoonmaakbedrijf De Vries
- WHEN they press Block payment with the reason IBAN change under verification
- THEN the payee shows as payment blocked
- AND a new invoice from that payee can still be issued

### Requirement: No run pays a blocked or disputed line (REQ-BPR-005)

The `export` transition of `PaymentRun` SHALL be refused when any line
settles an `APTransaction` that is payment blocked or in state disputed, or
pays a `Payee` that is payment blocked, whenever the block was set. The
refusal SHALL name every such invoice number. The check SHALL be the same
code the payment run proposal uses, and any submission of a run to a
payment provider SHALL apply it too. It MUST fail closed when a lookup
fails.

#### Scenario: A block set after approval stops the export

- GIVEN a run approved on Monday with a line for invoice 2026-0412
- AND the invoice is payment blocked on Tuesday
- WHEN the controller presses Export to bank on Tuesday
- THEN the export is refused with a message naming 2026-0412
- AND no bank file is written and the run stays approved

#### Scenario: A run with no blocked line exports as before

- GIVEN an approved run whose invoices and payees are not blocked and not disputed
- WHEN the controller presses Export to bank
- THEN the pain.001 file is written and the run shows state exported
