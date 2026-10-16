# payment-run-sepa-export Specification (delta)

## Purpose

A payment run is proposed from the invoices due, and each payment in it
can go out on its own day. From shillinq matrix rows `bnk-sepa-batch` and
`bnk-scheduled-payment`.

## ADDED Requirements

### Requirement: A payment run is proposed from the invoices due (REQ-BPR-002)

Shillinq SHALL offer a "Propose payment run" action on the payment runs
page that, for one administration, a chosen due date and a debtor account,
writes one `PaymentRun` in state draft with one line per `APTransaction` in
state issued, overdue or partially-paid whose `dueDate` is on or before the
chosen date. Each line SHALL carry the payee's IBAN, the open amount and the
invoice number as remittance information. The action MUST leave out, and
report with a reason, every invoice that is payment blocked, disputed, from
a payment blocked payee, without a payee IBAN, or already on a run in state
draft, approved or exported.

#### Scenario: A bookkeeper proposes this week's supplier payments

- GIVEN a bookkeeper of Gemeente Voorbeeld on the payment runs page, with invoice 2026-0412 of EUR 1,815.00 due 2026-10-01 and not blocked
- WHEN they choose Propose payment run with due on or before 2026-10-01
- THEN a draft payment run opens with one line for 2026-0412 of EUR 1,815.00 to NL20INGB0001234567 with remittance 2026-0412

#### Scenario: Blocked and already batched invoices are named, not paid

- GIVEN invoice DV-7781 from a payment blocked payee and invoice 2026-0398 already on an approved run, both due before 2026-10-01
- WHEN the bookkeeper proposes a run for 2026-10-01
- THEN neither invoice is a line of the new run
- AND the result lists DV-7781 with reason payee blocked and 2026-0398 with reason already on a payment run

#### Scenario: A proposed run can be trimmed before approval

- GIVEN a proposed run in draft with three lines
- WHEN the bookkeeper removes one line and saves
- THEN the run has two lines and its total is recalculated
- AND approval still requires a second person

### Requirement: Each payment can carry its own execution date (REQ-BPR-006)

A payment line SHALL accept an optional `requestedExecutionDate`. The
pain.001.001.03 export SHALL write one payment information block per
distinct requested date, each with its own number of transactions, control
sum and `ReqdExctnDt`, using the run's `executionDate` for lines without
one. A run whose lines share one date SHALL render exactly one payment
information block, as before.

#### Scenario: Two suppliers are paid on their own due dates

- GIVEN an approved run with a line for EUR 1,815.00 dated 2026-10-01 and a line for EUR 605.00 dated 2026-10-15
- WHEN the controller presses Export to bank
- THEN the pain.001 file holds two PmtInf blocks with ReqdExctnDt 2026-10-01 and 2026-10-15
- AND the group header counts two transactions with a control sum of 2420.00

#### Scenario: The proposal fills the due date when asked

- GIVEN invoices due 2026-10-01 and 2026-10-15 and a run execution date of 2026-09-29
- WHEN the bookkeeper proposes a run with pay on the due date ticked
- THEN the lines carry requested dates 2026-10-01 and 2026-10-15
