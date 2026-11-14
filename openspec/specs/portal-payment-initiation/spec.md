# portal-payment-initiation Specification

## Purpose
Lets a debtor pay an open invoice or payment request from the portal. A bearer-scoped endpoint returns an iDEAL checkout URL through the existing Mollie adapter for an invoice the debtor owns, the amount always comes from the server, and a signature-verified webhook settles the payment once and writes a confirmation the debtor can see. The customer manifest declares the pay action on the rows it can pay, and an operator sets where the checkout returns.

## Requirements

### Requirement: A payment-provider port drives iDEAL through the existing Mollie adapter (REQ-SPPI-001)

Shillinq MUST expose a `PaymentProviderInterface` port whose shipped binding
delegates to the existing
`OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface`
(`createPayment()` → `MolliePaymentResult{paymentId, checkoutUrl}`). The port
MUST request iDEAL (`method: 'ideal'`) as the payment method for the portal
pay-now flow (iDEAL is the required Dutch MKB rail). The Mollie API key and
test-mode flag MUST be sourced from app config (never hardcoded). When the bound
provider is dormant (`isDormant()` true — no live binding configured) the port
MUST return a deferred outcome carrying NO checkout URL, and the initiation
endpoint MUST surface that honestly (a `deferred`/`503` result) rather than a
fabricated URL. The port MUST NOT be a second, forked Mollie client — it wraps
the one verified adapter.

#### Scenario: The port mints an iDEAL session through the Mollie adapter

- GIVEN a live-bound `PaymentProviderInterface` and a server-resolved invoice amount and currency
- WHEN the initiation flow requests a payment session
- THEN the port calls `MolliePaymentAdapterInterface::createPayment()` with `method: 'ideal'`, the server-side amount/currency, and the app-config API key/test-mode
- AND it returns the Mollie `checkoutUrl` + `paymentId`
- AND when the bound adapter is dormant it returns a deferred outcome with no checkout URL and the endpoint responds `503`/`deferred` rather than a fabricated URL
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: A bearer-subject-scoped initiation endpoint returns a checkout URL for an owned invoice (REQ-SPPI-002)

Shillinq MUST ship a `#[PublicPage]` + `#[NoCSRFRequired]` receiver at an
instance-local endpoint under `/apps/shillinq/api/portal/payments/` that
portaliq forwards the `pay` action to server-to-server. A `PortalAssertionVerifier`
MUST validate the inbound `X-Portal-Subject` header as portaliq's frozen A6
assertion BEFORE any other work: verify the HS256 signature against the
portaliq-managed shared signing secret; reject any token whose header `alg` is
not exactly `HS256` (defeating `none`/alg-confusion); require `iss = portaliq`,
`use = assertion`, and a present, unexpired `exp`; and require the frozen claim
set. A missing, malformed, wrongly-signed, expired or wrong-`use` assertion — or
an unconfigured shared secret — MUST fail closed with `401` before any
OpenRegister read or PSP call. On a valid assertion the endpoint MUST require
`audience = customer` (else `403`), resolve the target invoice / payment-request
from the client-supplied opaque id, mint or reuse a `PaymentRequest`, drive the
provider port, and relay `{ checkoutUrl }` as JSON, returning `502` on a
downstream/OpenRegister failure without leaking internals.

#### Scenario: An invalid assertion is rejected before any work

- GIVEN a POST to the portal payment initiation endpoint
- WHEN the `X-Portal-Subject` header is absent, has `alg` other than `HS256`, has `iss` other than `portaliq`, has `use` other than `assertion`, is expired, or its signature does not match the shared secret, or no shared secret is configured
- THEN the receiver responds `401` and performs no OpenRegister read, no PaymentRequest write and no PSP call
- @e2e exclude e2e added in apply phase - spec-only PR

#### Scenario: A verified owning subject receives a checkout URL

- GIVEN a valid `customer` assertion carrying the subject's `customerMasterId` scope claim, and a body `invoiceId` naming an open AR invoice whose `customerId` equals that `customerMasterId`
- WHEN the receiver processes the `pay` action
- THEN it mints (or reuses a pending) `PaymentRequest` for that invoice, drives the provider port with `method: 'ideal'`, persists the `paymentIntentId`, and relays `{ checkoutUrl }`
- AND a downstream/PSP/OpenRegister failure is relayed as `502` with no raw exception text
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: Ownership is server-derived and a non-owned invoice is unreachable (REQ-SPPI-003)

The receiver MUST derive the owning identity ONLY from the verified assertion's
`customerMasterId` scope claim, NEVER from the request body, query or
`Authorization` header. It MUST treat the client-supplied `invoiceId` /
`paymentRequestId` ONLY as an opaque OpenRegister object id, MUST reject any
value that is a full URL or contains a path/scheme/host (SSRF hardening), and
MUST NEVER use it to build an outbound request. Before minting a session the
receiver MUST resolve, via OpenRegister, the `ARInvoice` whose `id` equals the
target AND whose `customerId` equals the assertion-derived `customerMasterId`
AND whose `state` is a payable state (`issued`, `partially-paid` or `overdue`).
When no such invoice exists (foreign owner, non-payable/settled state, or a
non-existent id) the receiver MUST fail closed with a single uniform
not-authorised result, MUST NOT reveal whether the invoice exists (no existence
oracle), and MUST NOT call the PSP. A body-supplied `customerId` / `amount` /
`customerMasterId` MUST NEVER influence the resolution or the charged amount.

#### Scenario: A debtor cannot pay an invoice they do not own

- GIVEN a valid `customer` assertion resolving to `customerMasterId` M
- AND a body `invoiceId` whose AR invoice `customerId` is NOT M, or is in a settled/non-payable state, or does not exist
- WHEN the receiver processes the `pay` action
- THEN it fails closed with the identical not-authorised response for a foreign invoice, a non-payable invoice, and a non-existent id (no existence oracle)
- AND the PSP is never called and no `PaymentRequest` is written
- AND an `invoiceId` value that is a full URL or contains a path is rejected before any lookup
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: The charged amount is the server-side invoice amount (REQ-SPPI-004)

The amount and currency sent to the PSP MUST be read from the server-resolved
`ARInvoice` (or its linked `PaymentRequest`) — never from the request body. A
client that supplies an `amount` in the body MUST be charged the invoice's own
outstanding amount regardless. The minted `PaymentRequest.amount` MUST equal the
server-side outstanding amount.

#### Scenario: A client-supplied amount is ignored

- GIVEN a valid owning `customer` assertion for an open invoice with outstanding amount A
- AND a request body that also carries a smaller `amount`
- WHEN the receiver mints the payment session
- THEN the `PaymentRequest.amount` and the PSP payload amount are A (the server invoice amount), and the body `amount` is ignored entirely
- @e2e exclude e2e added in apply phase - spec-only PR

### Requirement: The signature-verified webhook settles idempotently and writes a subject confirmation (REQ-SPPI-005)

On a captured payment the existing signature-verified, idempotent webhook path (`PaymentRequestWebhookController` and `PaymentReconciliationService`, `REQ-APL-004`) MUST settle the payment and write the subject a confirmation. It MUST transition the `PaymentRequest` to a captured/paid state, settle the linked `ARInvoice` (`state` becomes `paid`), and — added by this change — write a subject-safe
`confirmationSummary` onto the `PaymentRequest` that the debtor reads through the
existing read-only `paymentRequests` portal collection. The webhook MUST remain
idempotent (a replayed event is a no-op) and MUST NOT trust any amount supplied
by the caller: settlement reconciles the PSP event against the stored
`PaymentRequest`. The `confirmationSummary` MUST be added to the
`paymentRequests` collection field whitelist so the subject can see it.

#### Scenario: Settlement pays the invoice and shows the debtor a confirmation

- GIVEN a minted `PaymentRequest` for an owned invoice and a captured PSP webhook event with a valid signature
- WHEN the webhook reconciles the event
- THEN the `PaymentRequest` moves to captured/paid, the linked `ARInvoice.state` becomes `paid`, and a subject-safe `confirmationSummary` is written and exposed through the `paymentRequests` collection
- AND a replayed webhook event is an idempotent no-op that does not double-settle or overwrite the confirmation
- AND the settlement amount is reconciled from the stored `PaymentRequest`, never from a client-supplied amount
- @e2e exclude e2e added in apply phase - spec-only PR

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
scoped by `customerId` against the `customerMasterId` claim, with the row action
`pay-request`: an endpoint-forward action on the same pay endpoint that declares
`rowField: paymentRequestId` and `rowWhen: {field: state, in: [pending]}`, so
portaliq forwards the proven row id only for a pending request. The parent
manifest SHALL carry neither. The pay endpoint SHALL accept `paymentRequestId`
when no `invoiceId` is sent, read the request by uuid, and open a checkout for
the request's own amount only when it names the subject's customer, carries no
invoice and is `pending`; every other target SHALL get the same 403.

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
