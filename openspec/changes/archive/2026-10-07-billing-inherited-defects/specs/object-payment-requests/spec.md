# object-payment-requests Specification

## ADDED Requirements

### Requirement: REQ-SOPR-009: The requester of a leaf payment request SHALL be kept

PaymentRequest SHALL declare `requestedBy` (the Nextcloud user id of the caller),
so the value the leaf API writes (REQ-SOPR-003) is stored. Every key the leaf
writes SHALL be a declared PaymentRequest property.

#### Scenario: The leaf payload writes only declared fields

- GIVEN a mapped caller raising a request on a host object
- WHEN the leaf builds the request
- THEN `requestedBy` is the caller and every key is declared on PaymentRequest
- @e2e exclude leaf provider; covered by `PaymentRequestLeafProviderTest::testEveryWrittenKeyIsADeclaredPaymentRequestProperty`
