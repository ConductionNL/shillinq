# portal-payment-initiation Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- arinvoice-lines-and-portal-amounts
- portal-pay-row-action-keys

## Purpose

Portaliq offers a per-row Pay now button only for a row action that names the
body key for the row's id and the rows it applies to (portaliq #805, decision
D30). The invoice (`ARInvoice`, schema:Invoice) rows carry their lifecycle in
`lifecycleState`, and the parent sees the voluntary notice on the card. The
checkout returns the payer to the portal. ADR-046, ADR-005.

## ADDED Requirements

### Requirement: The pay action names its row key and its payable rows (REQ-SPPI-009)

The `pay` action SHALL declare `rowField: invoiceId` and `rowWhen: {field:
lifecycleState, in: [issued, partially-paid, overdue]}`, the same states the pay
receiver accepts. `rowWhen` SHALL name `lifecycleState`, the field an `ARInvoice`
row carries; `state` is not an `ARInvoice` field. The parent `salesInvoices`
collection SHALL declare `noticeField: invoiceNote`. The `paymentRequests`
collection SHALL NOT name a row action in the customer or the parent manifest,
because its row id is a payment request, not an invoice.

#### Scenario: A guardian gets a Pay now button on an open contribution only

- GIVEN the parent manifest
- WHEN portaliq reads the `pay` action and the `salesInvoices` collection
- THEN `pay` carries `rowField: invoiceId` and `rowWhen` on `lifecycleState` with issued, partially-paid and overdue, equal to the receiver's payable states
- AND `salesInvoices` carries `noticeField: invoiceNote`, and `paymentRequests` names no row action
- @e2e exclude manifest declaration; covered by `PortalContributionProviderTest::testThePayActionNamesItsRowKeyAndItsPayableRows`

### Requirement: An operator sets where the checkout returns (REQ-SPPI-010)

The settings API SHALL read and write `portal_payment_redirect_url`, and the
admin settings form SHALL show it. A value SHALL be stored only when it is empty
or an absolute `https` address; anything else SHALL be refused with a 400 and
leave the stored value as it was. An empty value SHALL keep today's fallback,
the instance root.

#### Scenario: An operator points the checkout back at the portal

- GIVEN an administrator on the settings page
- WHEN they save `https://portaal.gemeente.example/betalen`
- THEN the pay flow's return address is that value
- @e2e exclude settings API; covered by `SettingsServiceTest::testThePortalReturnAddressIsStoredOnlyWhenHttps`

#### Scenario: A non-https address is refused

- GIVEN an administrator
- WHEN they save `http://portaal.example` or `javascript:alert(1)`
- THEN the answer is 400 and the stored value is unchanged
- @e2e exclude settings API; covered by `SettingsServiceTest::testThePortalReturnAddressIsStoredOnlyWhenHttps` and `SettingsControllerWriteTest::testAnUnsafeReturnAddressIsRefusedWith400`

## MODIFIED Requirements

### Requirement: The customer manifest declares a pay action as a rowAction on open invoices (REQ-SPPI-006)

`OCA\Shillinq\Portal\PortalContributionProvider`'s `customer` manifest MUST
declare exactly two contract-v2 `endpoint-forward` actions: `pay` for an
invoice and `pay-request` for a payment request without an invoice
(REQ-SPPI-008), each `{id, label, type: 'endpoint-forward', endpoint, method:
'POST', minTrust, rowField, rowWhen}` whose `endpoint` is an instance-local
RELATIVE path under `/apps/shillinq/api/portal/payments/` (leading slash, no
scheme, no host, no `..`). The manifest MUST reference `pay` as a `rowAction` on
the `salesInvoices` collection only, gated by its `rowWhen` to payable rows
(REQ-SPPI-009), and `pay-request` on the `requestPayments` collection, so
portaliq renders a per-row pay-now control (a settled/non-payable row MUST NOT
offer it). `minTrust` MUST track the AR surface. The `supplier` and
`accountant` manifests' `actions` MUST remain empty. The provider MUST stay a
plain, dependency-free class (no portaliq import, no `implements`, no
constructor); it only adds pure-data action and rowAction declarations.

#### Scenario: The customer manifest carries the pay action and rowAction

- GIVEN a constructed `PortalContributionProvider` and a subject with `audience: 'customer'`
- WHEN `getContribution($subject)` is called
- THEN the returned manifest's `actions` are exactly `pay` and `pay-request`, both of type `endpoint-forward` with an instance-local relative `endpoint` under `/apps/shillinq/api/portal/payments/`, method `POST`, a `minTrust` tracking the AR surface, a `rowField` and a `rowWhen`
- AND `salesInvoices` references `pay` as a `rowAction`, `requestPayments` references `pay-request`, and `paymentRequests` references none
- AND the `supplier` and `accountant` manifests' `actions` stay empty
- @e2e exclude manifest declaration; covered by `PortalContributionProviderTest::testCustomerManifestPayActionAndRowAction`
