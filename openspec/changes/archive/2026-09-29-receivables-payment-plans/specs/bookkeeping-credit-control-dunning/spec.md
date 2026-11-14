# bookkeeping-credit-control-dunning Specification (delta)

## Purpose

A customer who cannot pay at once gets a payment plan whose instalments are
followed, paid and, when missed, end the plan and resume dunning. From shillinq
matrix row `rec-payment-plan`.

## ADDED Requirements

### Requirement: A payment plan is agreed for a customer's overdue invoices (REQ-RPPL-001)

A user SHALL be able to agree a `PaymentPlan` for one or more overdue invoices of
one customer from `CustomerDetail` or from `ARInvoiceDetail`, choosing the number
of instalments or the instalment amount, the frequency, the first due date and
the grace period. The instalments MUST add up to the plan total to the cent, and
on activation the customer SHALL receive a mail with the covered invoices, the
schedule, the IBAN and the plan's payment reference.

#### Scenario: A café agrees six monthly instalments

- GIVEN customer Café De Zwaan with overdue invoices 2026-0231 of EUR 1,815.00 and 2026-0266 of EUR 605.00
- WHEN the bookkeeper chooses Agree a payment plan on the customer page, selects both invoices and six monthly instalments from 2026-11-01
- THEN plan RGL-2026-0007 shows five instalments of EUR 403.33 and a last of EUR 403.35
- AND on activation Café De Zwaan receives the schedule with the reference RGL-2026-0007

### Requirement: An active plan pauses dunning of its invoices (REQ-RPPL-002)

Activating a plan SHALL pause dunning of every covered invoice with reason
`PAYMENT_PLAN` until the plan is completed, broken or cancelled, and only those
transitions SHALL lift the plan's pauses.

#### Scenario: No reminder while the plan is kept

- GIVEN plan RGL-2026-0007 active and invoice 2026-0231 due for its third dunning stage
- WHEN the daily dunning run takes place
- THEN no reminder is sent for invoice 2026-0231

### Requirement: Instalment payments are recognised and allocated (REQ-RPPL-003)

A payment for a plan SHALL be recognised from a bank line naming the plan's
payment reference, from the capture of an instalment's payment request, or from a
settle action, and SHALL be allocated to the covered invoices oldest first. An
invoice whose amount due reaches zero SHALL move to paid, and an amount above the
instalment SHALL pay the following instalments in order.

#### Scenario: The first instalment arrives by bank

- GIVEN plan RGL-2026-0007 with its first instalment of EUR 403.33 due on 2026-11-01
- WHEN a bank line of EUR 403.33 with remittance RGL-2026-0007 termijn 1 is reconciled
- THEN the instalment shows paid on 2026-11-01
- AND invoice 2026-0231 shows EUR 403.33 paid and EUR 1,411.67 due

### Requirement: A missed instalment breaks the plan and resumes dunning (REQ-RPPL-004)

When an instalment is unpaid at the end of the grace period after its due date,
the instalment SHALL be marked missed and the plan broken, the plan's dunning
pauses SHALL be resumed, the customer SHALL be mailed that the arrangement has
ended, and the `ar-controller` members SHALL be notified.

#### Scenario: The third instalment is not paid

- GIVEN plan RGL-2026-0004 with its third instalment due on 2026-09-01 and a grace period of 14 days
- WHEN the monitor runs on 2026-09-16 and the instalment is still unpaid
- THEN the plan shows broken and its invoices are again in the dunning ladder
- AND the customer and the ar-controllers are told the arrangement ended

### Requirement: A fully paid plan completes (REQ-RPPL-005)

When every instalment of a plan is paid, the plan SHALL move to completed, its
covered invoices SHALL be paid, and its pauses SHALL be closed.

#### Scenario: The last instalment clears the debt

- GIVEN plan RGL-2026-0007 with five instalments paid
- WHEN the sixth instalment of EUR 403.35 is paid
- THEN the plan shows completed and both invoices show paid

### Requirement: Plans are followed on one page (REQ-RPPL-006)

A Payment plans page SHALL list the administration's plans with customer, total,
paid, arrears, next due instalment and state, and the customer and invoice pages
SHALL show the plans that cover them.

#### Scenario: The credit controller reviews this month's instalments

- GIVEN twelve active plans
- WHEN the credit controller opens the Payment plans page and filters on instalments due this month
- THEN each plan with an instalment due this month is listed with its amount and whether it has been paid
