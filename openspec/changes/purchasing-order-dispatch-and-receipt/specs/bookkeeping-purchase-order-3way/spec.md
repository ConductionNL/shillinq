# bookkeeping-purchase-order-3way Specification (delta)

## Purpose

A purchase order actually reaches the supplier, its state follows its
declared lifecycle, and a delivered service is confirmed from a screen.
From shillinq matrix rows `pur-po` and `pur-receipt`.

## ADDED Requirements

### Requirement: A purchase order sent by Peppol is handed to integriq (REQ-PODR-001)

When a user sends an approved purchase order by Peppol, shillinq SHALL look
up the supplier through integriq's participant endpoint, store the UBL
Order, and emit `nl.conduction.peppol.outbound.requested` with document type
Order. The order SHALL show the dispatch as pending, SHALL move to sent only
when integriq reports the delivery status sent, and SHALL show the reason
and offer email when integriq reports it failed. When the supplier is not a
Peppol participant, the order SHALL be offered for email instead.

#### Scenario: A buyer sends an order to a Peppol supplier

- GIVEN an approved order PO-2026-031 to Drukkerij Van der Meer B.V., Peppol participant 0106:12345678
- WHEN the buyer presses Send via Peppol on the order page
- THEN the order page shows dispatch pending via Peppol
- AND after integriq reports the delivery as sent the order shows state sent with the time

#### Scenario: A failed Peppol delivery is shown, not hidden

- GIVEN a pending Peppol dispatch
- WHEN integriq reports the delivery as failed with reason receiver unreachable
- THEN the order page shows the failure and that reason
- AND the order stays approved and offers Send by email

### Requirement: A purchase order sent by email is handed to integriq's email channel (REQ-PODR-002)

When a user sends an approved purchase order by email, shillinq SHALL
render the order as a PDF and hand it with the supplier's order address to
integriq's email channel. The order SHALL move to sent only when the
channel accepted the message. When the channel is not available, the send
button MUST say so and the order MUST NOT be marked sent.

#### Scenario: A buyer mails an order to a supplier without Peppol

- GIVEN an approved order PO-2026-032 to Adviesbureau Groen with order address inkoop@example.nl and no Peppol id
- WHEN the buyer presses Send by email
- THEN the order shows state sent by email to inkoop@example.nl

#### Scenario: Without a mail channel nothing is claimed

- GIVEN an instance without integriq
- WHEN the buyer opens an approved order
- THEN Send by email is disabled with the text that email sending is not configured
- AND the order stays approved

### Requirement: The purchase order state follows its declared lifecycle (REQ-PODR-003)

The purchase order service SHALL keep the order's state in the declared
lifecycle field `statusCode` and SHALL move it only through the declared
transitions. It MUST NOT write a state to any other field.

#### Scenario: Sending an order runs the declared transition

- GIVEN an approved order
- WHEN it is sent and delivery is confirmed
- THEN its statusCode is sent and its audit trail shows the send transition

### Requirement: A delivered service is confirmed from a screen (REQ-PODR-004)

Shillinq SHALL offer pages to list, enter and review service receipts
against an order with service lines, reachable from the purchasing menu and
from the order page. A line SHALL be confirmed by percentage complete,
quantity or amount, and a receipt SHALL be confirmed, accepted or rejected
from its page through the existing service receipt endpoints.

#### Scenario: A budget holder confirms part of an advice assignment

- GIVEN approved order PO-2026-032 for 40 hours of advice
- WHEN the budget holder presses Confirm service on the order page, enters 25 hours for September and confirms
- THEN a service receipt shows state confirmed with 25 of 40 hours received
- AND when the controller accepts the receipt, the order shows state partially received

#### Scenario: A rejected receipt says why

- GIVEN a confirmed service receipt
- WHEN the controller rejects it with the reason Uren niet onderbouwd
- THEN the receipt shows state rejected with that reason
