# object-payment-refunds-and-credit Specification (delta)

## Purpose

An app that asked for money on an object can ask shillinq to refund it or to keep it as credit for the same debtor. Shillinq books both and holds the balance; the app reads the outcome from the payment request. Requested by larpinq's `registration-cancel-transfer-refund`.

## ADDED Requirements

### Requirement: An app asks for a refund or credit through a typed event (REQ-ORC-001)

Shillinq SHALL publish `OCA\Shillinq\Event\PaymentRefundRequestedEvent` and `PaymentCreditRequestedEvent` and SHALL answer each with a result or an error. It SHALL accept the request only for a settled request that stands on an object whose `subject.app` is the event's `sourceApp` and that is not already `refund_requested`, `refunded` or `credited`.

#### Scenario: Larpinq asks for a refund of Mila's paid registration

- GIVEN Mila's request of 85.00 on a larpinq registration, settled by the provider
- WHEN larpinq dispatches `PaymentRefundRequestedEvent` with that request id and `sourceApp: larpinq`
- THEN the event comes back handled with state `refund_requested`

#### Scenario: Another app cannot refund larpinq's request

- GIVEN the same request
- WHEN an app with `sourceApp: learniq` dispatches the refund event for it
- THEN the event comes back with an error and the request is unchanged

### Requirement: A refund is approved and paid by finance (REQ-ORC-002)

A request in `refund_requested` SHALL appear on the "Refunds to pay" page. A user with `payment.administer` SHALL be able to approve it, which books the reversal to the refunds payable account, and to mark it paid with a bank reference, which books the payment and moves the request to `refunded`.

#### Scenario: A treasurer pays Mila's refund

- GIVEN Mila's request in `refund_requested`
- WHEN a treasurer approves it, pays 85.00 by bank and marks it paid with reference "RF-2026-0007"
- THEN the request reads `refunded`
- AND larpinq's listener sets Mila's registration to refunded

### Requirement: Credit is held per debtor (REQ-ORC-003)

On a valid credit request shillinq SHALL book the settled amount to the customer credit account, SHALL record a `DebtorCredit` for the debtor's customer record or, without one, their email address, and SHALL move the request to `credited`. A debtor with neither SHALL get an error and no credit.

#### Scenario: Joris keeps Mila's payment as credit

- GIVEN Joris paid 85.00 for Mila's registration with his email address
- WHEN larpinq dispatches `PaymentCreditRequestedEvent` for that request
- THEN the request reads `credited`
- AND an open credit of 85.00 exists for Joris's email address

### Requirement: Open credit pays the next request first (REQ-ORC-004)

When a request is created for a debtor with open credit, shillinq SHALL settle it with credit up to the amount, oldest credit first, and SHALL stamp it settled when the credit covers it whole.

#### Scenario: Joris's credit pays the next event

- GIVEN Joris's open credit of 85.00
- WHEN larpinq creates a request of 60.00 for Joris for the spring event
- THEN the request is settled by `credit` at once
- AND Joris's credit has 25.00 remaining
