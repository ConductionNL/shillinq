# payment-control-guards Specification (delta)

## Purpose

A single invoice or a whole supplier can be blocked from payment, and no
run pays a blocked line. From shillinq matrix row `bnk-payment-block`.

## ADDED Requirements

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
