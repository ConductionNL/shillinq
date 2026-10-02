## ADDED Requirements

### Requirement: The debtor gets a receipt by mail (REQ-SOPR-012)

When shillinq books a captured payment on an object request that has no
invoice behind it (REQ-SOPR-002), it SHALL mail the debtor's `debtor.email`
one receipt naming what was paid, the amount with its currency, the date it
was paid and the payment reference. It SHALL send the receipt only after the
captured request is saved. It SHALL NOT send a receipt for a capture that
lands in `captured_unapplied`, for a replayed capture, or when the debtor has
no valid email address. A failure to send SHALL be logged and SHALL NOT undo
or block the booking.

#### Scenario: A player gets a receipt for the event fee

- GIVEN a pending object request with `debtor.email` `j.devries@example.nl`
- WHEN the gateway reports it captured and shillinq books the receipt
- THEN one mail goes to `j.devries@example.nl` with the description, the amount, the date and the reference
- @e2e exclude a gateway webhook and an outgoing mail have no screen; covered by PHPUnit on `PaymentReceiptMailer` and `PaymentReconciliationService`

#### Scenario: No receipt for money that waits

- GIVEN a pending object request whose type has no revenue account mapped
- WHEN the gateway reports it captured
- THEN the request lands in `captured_unapplied` and no mail is sent
- @e2e exclude a gateway webhook has no screen; covered by PHPUnit on `PaymentReconciliationService`
