# bookkeeping-accounts-payable-core Specification (delta)

## Purpose

An AP transaction can be issued, so it becomes an open invoice a payment
run can pay. Prerequisite for shillinq matrix row `bnk-sepa-batch`.

## ADDED Requirements

### Requirement: An AP transaction whose amounts add up can be issued (REQ-BPR-001)

The `issue` transition of `APTransaction` SHALL be guarded by a registered,
resolvable guard that allows the transition when the sum of the line
amounts plus `taxAmount` equals `totalAmount` in integer cents, and denies
it with a message naming both totals otherwise. The transition MUST NOT
abort because its `requires` tag cannot be resolved.

#### Scenario: A bookkeeper issues a received supplier invoice

- GIVEN a bookkeeper on the AP transaction detail page of invoice 2026-0412 in state received, with lines of EUR 1,500.00 and tax of EUR 315.00 and a total of EUR 1,815.00
- WHEN they press Issue / post invoice
- THEN the invoice shows state issued

#### Scenario: An invoice whose lines do not add up stays received

- GIVEN a received invoice with lines of EUR 1,500.00, tax EUR 315.00 and a total of EUR 1,850.00
- WHEN the bookkeeper presses Issue / post invoice
- THEN the transition is refused with a message naming EUR 1,815.00 and EUR 1,850.00
- AND the invoice stays received
