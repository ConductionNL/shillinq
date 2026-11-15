# portal-payment-initiation Specification

## ADDED Requirements

### Requirement: REQ-SPPI-011: The invoice target SHALL be read by its uuid, then by its slug

`PortalPaymentSessionService` SHALL read the target ARInvoice with `find()` by
uuid and, only on a miss, with a `slug` property filter. It SHALL NOT filter
`findAll()` on `id`. Ownership (`customerId`) and a payable `lifecycleState` SHALL
be checked on the record; a foreign, non-payable or missing invoice SHALL give the
same forbidden result. A read failure other than a miss SHALL be a downstream
error.

#### Scenario: A customer pays an invoice addressed by its uuid

- GIVEN an issued invoice owned by the customer, addressed by its uuid
- AND an object service that, like OpenRegister, matches nothing on an `id` filter
- WHEN the customer initiates a payment
- THEN a checkout URL is returned for the server amount
- @e2e exclude service lookup; covered by `PortalPaymentSessionServiceTest::testHappyPathReturnsCheckoutUrl`

#### Scenario: A customer pays an invoice addressed by its slug

- GIVEN the same invoice carrying slug `inv-2026-0001`
- WHEN the customer initiates a payment for `inv-2026-0001`
- THEN a checkout URL is returned
- @e2e exclude service lookup; covered by `PortalPaymentSessionServiceTest::testAnInvoiceAddressedBySlugResolves`
