## ADDED Requirements

### Requirement: An event fee is its own request type (REQ-SOPR-011)

`PaymentRequest.requestType` SHALL accept `eventFee` for the fee for taking
part in an event, beside `leges`, `dwangsom`, `deposit`, `other` and
`contribution`. An object request of type `eventFee` SHALL follow every rule
an object request follows: an amount above zero, a complete subject, at most
one pending request of the type per object (REQ-SOPR-001), and a receipt
booked against the account `paymentRevenueAccounts` maps to `eventFee`, or
`captured_unapplied` when nothing is mapped (REQ-SOPR-002).

#### Scenario: larpinq asks an event fee for a registration

- GIVEN a registration in larpinq
- WHEN larpinq raises a request through the leaf with `requestType = eventFee` and an amount
- THEN one `pending` request of type `eventFee` exists with the registration as subject
- @e2e exclude a leaf call from another app's PHP has no browser; covered by PHPUnit on `PaymentRequestLeafProvider::createAsApp()`

#### Scenario: A captured event fee books against its own account

- GIVEN `paymentRevenueAccounts` maps `eventFee` to `8050`
- WHEN the request for an event fee is captured
- THEN the receipt books one credit line on account `8050`
- @e2e exclude a ledger booking has no screen of its own; covered by PHPUnit on `PaymentReconciliationService`
