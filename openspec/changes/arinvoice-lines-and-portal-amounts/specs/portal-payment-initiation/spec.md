# portal-payment-initiation Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- arinvoice-lines-and-portal-amounts

## Purpose

A customer in the portal sees the amount, the lines and the status of their own
invoices (`ARInvoice`, schema:Invoice), and sees and pays a payment request that
stands on its own (`PaymentRequest`, schema:Invoice), such as leges on a case.
Completes task 4.1 of `case-payment-requests` (REQ-SOPR-005). ADR-005, ADR-046.

## ADDED Requirements

### Requirement: The customer manifest names the fields ARInvoice declares (REQ-SPPI-007)

Every field the customer and parent manifests list in a collection's `fields`,
`detail.fields` or `columns` SHALL be a property the merged register declares on
that collection's schema. The customer `salesInvoices` collection SHALL list
`grossAmount`, `vatAmount`, `invoiceLines`, `lifecycleState` and `ublRef`, and
SHALL NOT list `totalAmount`, `taxAmount`, `lines`, `state` or `ublXml`.

#### Scenario: A customer sees the amount and status of an invoice

- GIVEN the merged register and the customer manifest
- WHEN every listed field of every collection is looked up on its schema
- THEN each one is a declared property, and the invoice columns are invoice, date, due, `grossAmount` and `lifecycleState`
- @e2e exclude manifest declaration; covered by `PortalContributionProviderTest::testEveryListedFieldIsADeclaredProperty`

### Requirement: A request without an invoice is listed and paid in the portal (REQ-SPPI-008)

`PaymentRequest` SHALL declare `customerId` (`format: uuid`, `$ref: CustomerMaster`,
nullable). A request with no `invoiceReference` and a `debtor.customerMasterId`
SHALL carry that value in `customerId`, written by the leaf API and the leges
intake when they create the request, and by a repair step for requests that
exist already. A request with an invoice SHALL NOT carry it. The customer
manifest SHALL declare a `requestPayments` collection over `PaymentRequest`
scoped by `customerId` against the `customerMasterId` claim, with the `pay` row
action. The pay endpoint SHALL accept `paymentRequestId` when no `invoiceId` is
sent, read the request by uuid, and open a checkout for the request's own amount
only when it names the subject's customer, carries no invoice and is `pending`;
every other target SHALL get the same 403.

#### Scenario: A citizen pays leges from the portal

- GIVEN a pending leges request of 125 euro without an invoice, whose debtor is the subject's customer
- WHEN the subject activates pay on it
- THEN a checkout opens for exactly 125 euro and the provider's intent id is saved on that request
- @e2e exclude needs a live provider round trip; covered by `PortalPaymentSessionServiceTest::testACitizenPaysARequestWithoutAnInvoice`

#### Scenario: Another citizen's request, an invoice-backed one and a paid one are refused

- GIVEN a request for another customer, a request with an `invoiceReference`, and a captured request
- WHEN the subject activates pay on each
- THEN each answer is forbidden and no provider session is opened
- @e2e exclude security boundary; covered by `PortalPaymentSessionServiceTest::testOnlyTheSubjectsOwnPendingRequestWithoutAnInvoiceIsPayable`

#### Scenario: The request carries its customer from the moment it is raised

- GIVEN a leges request raised on a case with `debtor.customerMasterId`, and a contribution request with an invoice
- WHEN each is stamped
- THEN the leges request carries `customerId` and the contribution request does not
- @e2e exclude write path; covered by `PaymentRequestPortalScopeTest`, `LegesIntakeStepServiceTest` and `PaymentRequestLeafProviderTest`

#### Scenario: Requests raised before this change are back-filled

- GIVEN an existing request without an invoice, with a debtor customer and no `customerId`, and an invoice-backed one
- WHEN the repair step runs twice
- THEN the first gets `customerId` once, the second is untouched, and the second run saves nothing
- @e2e exclude repair step; covered by `BackfillPaymentRequestCustomerTest`
