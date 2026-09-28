---
kind: code
depends_on: [case-payment-requests, voluntary-contribution-reminder]
---

# Proposal: arinvoice-lines-and-portal-amounts

## Summary

Three places write or read invoice lines under a name `ARInvoice` does not
declare, so OpenRegister drops the lines in silence or the PDF shows none. The
customer portal lists the invoice amounts under names `ARInvoice` does not carry,
so a customer sees no amount and no status. A payment request that stands on its
own, without an invoice, never reaches the portal and cannot be paid there. This
change writes and reads `invoiceLines`, names the portal fields as `ARInvoice`
declares them, and lists and pays request-only payment requests in the portal
(task 4.1 of `case-payment-requests`).

## Motivation

Found by the round 2 lane (`sq-fees`, LANE-LOG-r2.md, gate 108 and the
contribution work) and confirmed on `development` 2026-09-27:

1. `src/modals/invoiceQuickDraft.js` posts the quick draft with `lines`
   (`lineNumber`, `description`, `unitPrice`, `glAccount`). `ARInvoice` declares
   `invoiceLines` (EN 16931 BG-25: `lineId`, `itemName`, `netPrice`, `netAmount`,
   `quantity`, `unitCode`, `vatCategory`, `vatRate`). OpenRegister drops an
   undeclared property, so every quick draft is saved without lines.
2. `RecurringInvoiceGenerator::buildArInvoicePayload()` writes `lines` in the
   same shape. Every generated invoice is saved without lines.
3. `InvoicePdfGenerator::renderHtml()` reads BillableInvoiceLine keys
   (`description`, `billableUnits`, `rateApplied`, `costAmount`). The e-invoice
   hybrid PDF hands it `ARInvoice.invoiceLines`, so every line prints blank and
   zero.
4. The customer portal manifest's `salesInvoices` lists `totalAmount`,
   `taxAmount`, `lines` and `state`. `ARInvoice` declares `grossAmount`,
   `vatAmount`, `invoiceLines` and `lifecycleState`. A customer sees no amount,
   no lines and no status. The parent manifest already maps these names; the
   customer manifest never did.
5. The `paymentRequests` collection reaches a request only through its invoice.
   A leges, dwangsom or deposit request raised on a case (REQ-SOPR-005) has no
   invoice, so it never appears, and the pay endpoint only accepts an invoice id.

## Affected Projects

- [ ] Project: `shillinq`: quick draft payload, recurring generator, PDF line
  rendering, customer portal fields, PaymentRequest.customerId, the request-only
  portal collection and pay path, a backfill repair step.
- [ ] Project: `portaliq`: renders the new collection with the existing `pay`
  row action. No code here.

## Scope

### In Scope

- The quick draft and the recurring generator write `invoiceLines` in the EN
  16931 shape.
- The PDF generator reads both line shapes, so the BillableInvoice PDF stays as
  it is and the ARInvoice hybrid PDF prints its lines.
- The customer manifest lists `grossAmount`, `vatAmount`, `invoiceLines` and
  `lifecycleState`.
- `PaymentRequest.customerId` (0.5.0): the debtor's customer on a request that
  stands on its own. The leaf API and the leges intake stamp it; a repair step
  back-fills existing requests.
- A `requestPayments` collection on the customer manifest, scoped by that field,
  with the `pay` row action; the pay endpoint accepts `paymentRequestId` and
  charges the request's own amount.

### Out of Scope

- A revenue account per invoice line. `ARInvoice.invoiceLines` declares none, so
  the quick draft's and the profile's line account still have nowhere to go.
  Declaring one is a schema decision on `ARInvoice`.
- `recurringProfileId` and `billingPeriod`: the generator writes them and its
  idempotency probe filters on them, but `ARInvoice` declares neither. Named in
  the PR as an inherited finding.
- `requestedBy` on a leaf-API request and the recurring payload's missing
  `invoiceNumber` and `periodId`: also undeclared or missing on `ARInvoice` /
  `PaymentRequest`, also named as inherited.
- The PDF layout, language and currency. `sales-invoice-document` moves the PDF
  to a docudesk template; this change only makes the current renderer read the
  lines it is given.

## Approach

The EN 16931 line shape is the one `ContributionInvoiceBuilder` already writes:
`lineId`, `itemName`, `quantity`, `unitCode` (C62), `netPrice`, `netAmount`,
`vatRate`, `vatCategory` (S above zero, Z at zero). The quick draft and the
recurring generator map their input lines onto it. The PDF generator normalises
each line it is handed, reading the BillableInvoiceLine keys first and the
`invoiceLines` keys second, so neither caller changes.

The customer manifest lists the fields by their declared names, so the parent
manifest's field map has nothing left to translate and goes. A test reads the
merged register and fails when any listed field is not a declared property.

`PaymentRequest.customerId` (`format: uuid`, `$ref: CustomerMaster`, like
`ARInvoice.customerId`) is the portal scope of a request without an invoice. One
rule, `PaymentRequestPortalScope::stamp()`, copies `debtor.customerMasterId` into
it when there is no `invoiceReference`; the leaf API, the leges intake and a
repair step for existing rows all use it. The customer manifest gains
`requestPayments`, scoped by that field, with the existing `pay` row action. The
pay endpoint takes `paymentRequestId` next to `invoiceId`; for a request it
charges the request's own amount after checking the owner, that no invoice stands
behind it and that it is pending (REQ-SOPR-005).

## New Dependencies

None.

## Impact

- `src/modals/invoiceQuickDraft.js`, `lib/Service/RecurringInvoiceGenerator.php`,
  `lib/Service/InvoicePdfGenerator.php`.
- `lib/Portal/PortalContributionProvider.php`.
- `lib/Settings/register.d/ar-invoice-payment-links.json` (PaymentRequest 0.5.0).
- New `lib/Service/PaymentRequestPortalScope.php`,
  `lib/Repair/BackfillPaymentRequestCustomer.php`; `appinfo/info.xml` (one step).
- `lib/Integration/PaymentRequestLeafProvider.php`,
  `lib/Service/LegesIntakeStepService.php`,
  `lib/Service/Payment/PortalPaymentSessionService.php`,
  `lib/Controller/PortalPaymentInitiationController.php`.

## Cross-Project Dependencies

- Stacked on `voluntary-contribution-reminder` (#1724): both change the portal
  provider and the payment session service, and this change uses the
  `PortalSubjectResolver` that one introduces. Land #1724 first.
- Portaliq sends `{paymentRequestId}` for a `requestPayments` row, as it sends
  `{invoiceId}` for an invoice row. Recorded in contract.md for the D30 pay screen.

## Risks

### Risk 1: A request becomes payable by the wrong person
**Severity:** High. **Mitigation:** the owner comes from the subject's portal
account, the request is read by uuid, and it must name that customer, carry no
invoice and be pending; every other case gets the same 403. Tests cover a foreign
request, an invoice-backed one and a paid one.

### Risk 2: Existing requests stay invisible
**Severity:** Medium. **Mitigation:** the repair step stamps every request-only
row that has a debtor customer and no `customerId`; idempotent and logged.

### Risk 3: A field name another consumer reads disappears from the manifest
**Severity:** Low. **Mitigation:** `totalAmount`, `taxAmount`, `lines`, `state` and
`ublXml` never carried a value, because `ARInvoice` does not declare them.

## Rollback Strategy

Revert the PR. `PaymentRequest.customerId` is additive and nullable; stamped
values stay harmless.
