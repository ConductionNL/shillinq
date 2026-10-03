# Design: arinvoice-lines-and-portal-amounts

## Architecture Overview

```
quick draft (JS) ─┐
recurring (PHP)  ─┴─> ARInvoice.invoiceLines (EN 16931 BG-25)
                          │
EInvoiceService ──────────┴─> InvoicePdfGenerator.renderHtml(lines)
                                 normaliseLine(): BillableInvoiceLine keys, else invoiceLines keys

leaf API / leges intake / repair ─> PaymentRequestPortalScope.stamp()
                                     no invoiceReference + debtor.customerMasterId -> customerId

portaliq ─ requestPayments (scope customerId) ─ pay-request {paymentRequestId}
        └> PortalPaymentInitiationController -> PortalPaymentSessionService.initiateForRequest()
             PortalSubjectResolver -> find(PaymentRequest, uuid) -> owner + no invoice + pending
             -> provider.createSession(request amount) -> paymentIntentId on the request
```

## Decisions

### D1: One line shape, the one the contribution builder writes

`ContributionInvoiceBuilder` already writes `invoiceLines` in the EN 16931 shape
that the UBL mapper and the compliance checks read. The quick draft and the
recurring generator map onto the same keys. `vatCategory` is `S` above zero and
`Z` at zero: neither form knows an exemption reason, and `Z` is the neutral
zero-rate code.

### D2: No line account

Both writers carried a per-line GL account (`glAccount`). `ARInvoice.invoiceLines`
declares none, so it was dropped before and is not written now. Declaring one is
an `ARInvoice` schema decision whose version is owned by `school-contributions.json`
since #1724; named as out of scope.

### D3: The PDF reads both shapes

`InvoicePdfGenerator` serves BillableInvoice (time and expense) and ARInvoice
(hybrid e-invoice). A small `normaliseLine()` reads the BillableInvoiceLine keys
first and falls back to the `invoiceLines` keys. Neither caller changes, and a
time-and-expense PDF renders exactly as before. The hybrid PDF discarded its
HTML and printed only a summary line, so it also gets one text line per invoice
line under that summary (number, description, quantity x price = amount with
the currency code, VAT rate; up to 45 lines on its one page).

### D4: The manifest lists declared names, and a test holds it there

The customer manifest names `grossAmount`, `vatAmount`, `invoiceLines`,
`lifecycleState` and `ublRef`. The parent manifest's field map then has nothing
to translate and is removed. A provider test merges the register the way the
repair step does and fails when any listed field is undeclared, so the class of
bug cannot return one field at a time.

### D5: A flat customerId on a request without an invoice

Portaliq's per-row scope check compares a flat key (`row[scopeField]`), so a
collection cannot scope on `debtor.customerMasterId`. A `via` join through
`CustomerMaster` would filter on its `id`, which OpenRegister's `findAll` cannot
match. `PaymentRequest.customerId` mirrors `ARInvoice.customerId` (uuid,
`$ref: CustomerMaster`). It is set only when no invoice stands behind the
request: an invoice-backed request already reaches the portal through its invoice
in `paymentRequests`, and would otherwise show twice.

### D6: One stamping rule, three writers

`PaymentRequestPortalScope::stamp()` is the rule. The leaf API and the leges
intake call it before saving; `BackfillPaymentRequestCustomer` calls it for rows
that exist. A case app that writes a request straight through the OpenRegister
API is not covered; the leaf API is the documented path (REQ-SOPR-003).

### D8: A second action, not a second meaning for `pay`

Portaliq's row action (#805) forwards the proven row id under the action's
`rowField` and shows the button only while the action's `rowWhen` holds on the
row. `pay` will name `invoiceId` and an ARInvoice state; a `requestPayments` row
is a payment request with a `state` of its own. So the collection gets
`pay-request` (`rowField: paymentRequestId`, `rowWhen: state in [pending]`) on
the same endpoint. This modifies REQ-SPPI-006, which said "exactly one" action.

### D7: The request pay path shares the invoice path's chain

`initiateForRequest()` uses the same audience gate, `PortalSubjectResolver`,
provider call and intent persistence as `initiate()`. It reads the request with
`find()` by uuid (a `findAll` filter on `id` matches nothing). It charges the
request's own amount (REQ-SOPR-005), refuses a zero or unreadable amount like the
invoice path, and never mints a new request.

## Declarative-vs-imperative decision

| Behaviour | Path | Why |
|---|---|---|
| `PaymentRequest.customerId` | declarative property in `ar-invoice-payment-links.json` | data model |
| stamping the value | imperative, `PaymentRequestPortalScope` in the two writers and a repair step | OpenRegister has no derived stored field the portal query can filter on |
| PDF line reading | imperative | document generation exception (ADR-031) |
| portal collection | declarative manifest data | ADR-046 |

## Nextcloud Integration

- Controllers: `PortalPaymentInitiationController` (reads `paymentRequestId`).
- Services: `PaymentRequestPortalScope`, `PortalPaymentSessionService`, `RecurringInvoiceGenerator`, `InvoicePdfGenerator`.
- Repair: `BackfillPaymentRequestCustomer` (`OCP\Migration\IRepairStep`, post-migration, after `InitializeSettings`).

## Security Considerations

The request pay path adds a target type to a `#[PublicPage]` receiver. The owner
comes from the subject's own portal account; the request must name that customer,
carry no invoice and be `pending`; the target is an opaque id; every refusal is
one 403 (ADR-005). The new collection scopes by a uuid reference to
`CustomerMaster`, the same isolation argument as `salesInvoices`.

## File Structure

```
src/modals/invoiceQuickDraft.js
lib/Service/RecurringInvoiceGenerator.php
lib/Service/InvoicePdfGenerator.php
lib/Portal/PortalContributionProvider.php
lib/Service/PaymentRequestPortalScope.php          (new)
lib/Repair/BackfillPaymentRequestCustomer.php      (new)
lib/Integration/PaymentRequestLeafProvider.php
lib/Service/LegesIntakeStepService.php
lib/Service/Payment/PortalPaymentSessionService.php
lib/Controller/PortalPaymentInitiationController.php
lib/Settings/register.d/ar-invoice-payment-links.json   (PaymentRequest 0.5.0)
appinfo/info.xml
```

## Seed Data

`PaymentRequest` gains one property. Every seeded request (the three in
`ar-invoice-payment-links.json`, the contribution one in
`school-contributions.json`) is invoice-backed, so none carries it, which
`ArPaymentLinksFragmentTest` asserts. No new seed: the seeded customers use
customer codes, not the uuids `customerId` is declared as. The seed checker
(gate 101) and the property checker (gate 108) run on the fragment.

## Risks / Trade-offs

- [A request written straight through the OpenRegister API is not stamped] → the leaf API is the documented path; the repair step can be rerun.
- [The `pay` action body now differs per collection] → recorded in contract.md for portaliq's pay screen (D30).

## Migration Plan

No Nextcloud migration class. PaymentRequest 0.5.0 is imported by the repair
step; `BackfillPaymentRequestCustomer` then stamps existing rows, idempotently.
