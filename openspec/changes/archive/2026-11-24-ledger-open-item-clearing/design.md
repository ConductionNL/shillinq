# Design: ledger-open-item-clearing

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Only GR/IR is reconciled.** `lib/Controller/GRIRReconciliationController.php` exposes `GRIRClearingService::reconcileGRIRSaldoForPeriod()`, a period saldo check on the GR/IR clearing account. Nothing matches individual lines on any other balance account.
- **Ledger lines.** `GLLine` (`lib/Settings/shillinq_register.json`) carries `accountNumber`, `amount`, `side`, `description`, `transactionId`, `subLedgerRef`, `subLedgerType` and dimensions. It has no clearing link.
- **Accounts.** `Account` has `accountType` and fragments add `isSuspenseAccount` (`register.d/add-shillinq-bank-reconciliation.json`). Suspense items from bank reconciliation are aged into a worklist by `payment-control-guards` REQ-PCG-002; that is a bank-line worklist, not ledger clearing.
- **Reconciliation matches.** `ReconciliationMatch` (`register.d/add-shillinq-bookkeeping-compliance.json:1372`, `register.d/bookkeeping-reconciliation-reports.json:274`) pairs bank lines with ledger items. It is the bank reconciliation's record and is not reused here: a clearing group pairs ledger lines with ledger lines.
- **Posting.** Only posted lines can be cleared, which needs `ledger-posting-path`.

## Goals / Non-Goals

**Goals**
- A bookkeeper clears matching lines on a balance account and sees what is left.
- Groups that do not net to zero are visible per account.

**Non-Goals**
- Customer and supplier open items, cross-administration clearing.

## Decisions

### D1. A group is a record, the line points at it

New schema `ClearingGroup`: `administrationId`, `accountNumber`, `lineIds`,
`balanceCents` (calculated: signed sum of the lines), `clearedBy`,
`clearedAt`, `reason`, lifecycle `cleared` and `reopened`. `GLLine` gains
`clearingGroupId`. A group whose `balanceCents` is not zero is allowed
(Moneybird's "niet op nul") and is listed as such.

Alternative considered: a clearing code typed on each line, as Twinfield
shows it. Rejected: a typed code cannot say who cleared what and when.

### D2. `balanceCents` is declarative

`balanceCents` is an `x-openregister-calculations` field over the group's
lines (debit positive, credit negative), so the list of groups that do not
net to zero is a plain filter.

### D3. Suggestions are exact pairs only

`lib/Service/OpenItemClearingService::suggest(account)` returns pairs of
uncleared posted lines on the account with equal amounts, opposite sides,
and an equal `description` reference or `sourceReference` on their
transactions. It never clears on its own.

### D4. A reversal reopens the group

When a transaction with a cleared line is reversed, the object-event
listener that handles reversals sets that line's group to `reopened`,
which lists it again.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Group balance | Declarative: `x-openregister-calculations` on `ClearingGroup` | A derived sum. |
| Groups not netting to zero | Declarative: an index filter on `balanceCents` | A view over data. |
| Clearing, undoing and suggesting | Imperative, one service behind two routes | Writes across several lines at once, with a check that they are posted and on one account. |
| Reopening on reversal | Imperative, an object-event listener (ADR-078) | Reacts to another object's transition. |

## Seed Data

Adviesbureau Van Dijk, account 1950 Kruisposten (open-item managed):
transfer from bank to savings on 2026-09-03, debit 1950 EUR 5,000 and credit
1950 EUR 5,000 on arrival on 2026-09-04, reference "Spaaropdracht 0903";
a cash deposit of EUR 250 debited on 2026-09-10 with no counterpart yet.
The suggestion pairs the two EUR 5,000 lines; the EUR 250 line stays open.

## Risks / Trade-offs

- [Large accounts make the page slow] → the page lists uncleared lines only, paged, per ADR-058 bounded queries.

## Migration Plan

None. Accounts start without the flag.

## Open Questions

None.
