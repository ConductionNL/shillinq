# portal-contribution Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- customer-invoice-portal-wave2 (this change) — archived 2026-07-14; synced into `openspec/specs/portal-contribution/spec.md` (REQ-SPC-020…022)

## Purpose

Wave 2 of shillinq's portaliq contribution (hydra ADR-046) lifts the Wave-1
customer-side exclusion of `ARInvoice` and `PaymentRequest`: a debtor can see
and pay their own AR invoices through the shared external portal. The surface
stays a pure-data addition to the existing
`OCA\Shillinq\Portal\PortalContributionProvider` customer manifest — no schema
edit, no route, no portaliq change — scoped by the globally-unique CustomerMaster
object UUID and enforced by portaliq's `PortalObjectReader`.

## ADDED Requirements

### Requirement: Customer subjects can see their own AR invoices (REQ-SPC-020)

For `$subject['audience'] === 'customer'`, `getContribution()` MUST append to
the existing customer manifest a read-only `salesInvoices` collection: register
`shillinq`, schema `ARInvoice`, `scopeField: 'customerId'` (the CustomerMaster
**object UUID** the base schema declares via `format: uuid` / `$ref:
CustomerMaster` / `inversedBy: invoices`), `scopeClaim: 'customerMasterId'`
(bare name → `claims.shillinq.customerMasterId`), `listable: true`. It MUST
carry a `fields` whitelist that projects the row to the customer-safe subset —
including `invoiceNumber`, `lines`, the artefact URIs (`sourceDocumentUri`,
`ublXml`) and the read-only `dunning` summary group — and MUST NOT include the
internal accounting fields `glTransactionId`, `matchedBankLineId`,
`settlementReference`, the `writeOff` group, or `administrationId`. The manifest
MUST stay pure data and the five Wave-1 customer collections MUST be unchanged
and MUST remain first. `actions` and `notifications` MUST stay `[]`.

#### Scenario: The customer manifest surfaces AR invoices, UUID-scoped

- GIVEN a server-derived customer subject
- WHEN `getContribution()` is called
- THEN the manifest's collections end with `salesInvoices` (schema `ARInvoice`, register `shillinq`, scopeField `customerId`, scopeClaim `customerMasterId`, listable)
- AND its `fields` whitelist includes `invoiceNumber`, `lines`, `sourceDocumentUri`, `ublXml`, and `dunning` and excludes `glTransactionId`, `matchedBankLineId`, `writeOff`, `administrationId`
- AND the five Wave-1 collections are unchanged and remain first, with `actions: []` and `notifications: []`
- @e2e exclude backend-only contract data; the portal list/detail is rendered and e2e-tested in portaliq, not in shillinq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Customer subjects can pay their own invoices, and another debtor's invoice is unreachable (REQ-SPC-021)

The customer manifest MUST append a read-only `paymentRequests` collection:
register `shillinq`, schema `PaymentRequest`, `scopeField: 'invoiceReference'`,
`scopeClaim: 'customerMasterId'`, `listable: true`, and a one-hop reverse `via`
join `{register: 'shillinq', schema: 'ARInvoice', scopeField: 'customerId',
targetField: 'id', match: 'scopeField'}`, so a payment request is visible only
when its linked `ARInvoice` belongs to the subject's CustomerMaster. Its
`fields` MUST include the computed `paymentLink` (pay-now surface). No customer
collection may scope by `administrationId` or by a client-supplied id, and every
customer collection MUST carry a (server-issued) `scopeClaim` — so another
debtor's invoice or payment request is unreachable (IDOR). Scope enforcement is
portaliq's per-row `verifyScope` + reverse-`via` membership; this app only
declares the manifest that feeds it.

#### Scenario: PaymentRequest is reachable only through the invoice's customer scope

- GIVEN a server-derived customer subject
- WHEN `getContribution()` is called
- THEN the manifest contains `paymentRequests` (schema `PaymentRequest`, scopeField `invoiceReference`, scopeClaim `customerMasterId`) whose `via` is exactly `{register: shillinq, schema: ARInvoice, scopeField: customerId, targetField: id, match: scopeField}`
- AND its `fields` include `paymentLink`
- @e2e exclude backend-only contract data; the reverse-`via` scope is enforced and e2e-tested in portaliq's PortalObjectReader, not in a shillinq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: No customer collection is scoped by administration or a client id

- WHEN the customer manifest is inspected
- THEN no collection declares `scopeField: 'administrationId'`
- AND every collection declares a non-empty `scopeClaim`
- AND `salesInvoices` scopes by the CustomerMaster object UUID (`customerId` / `customerMasterId`), never the per-administration customer code
- @e2e exclude fail-closed backend contract; runtime IDOR enforcement is portaliq-side — covered by PHPUnit at the manifest-declaration level (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: The Wave-2 AR surface is read-only and dunning is summary-only (REQ-SPC-022)

The Wave-2 additions MUST NOT introduce any write, create, or endpoint action:
`actions` and `notifications` stay `[]`. Dunning MUST be surfaced only as the
read-only `ARInvoice.dunning` summary group projected on `salesInvoices`; the
`DunningRun` / `DunningRecord` / `DunningNotice` schemas MUST NOT be added as
collections (recipient PII, rendered letters, AP-side data). An unknown audience
MUST still fail closed (`getContribution()` returns `null`).

#### Scenario: The AR surface adds no write capability and no dunning-run collection

- WHEN the customer manifest is returned
- THEN `actions` is `[]` and `notifications` is `[]`
- AND no collection has schema `DunningRun`, `DunningRecord`, or `DunningNotice`
- AND `salesInvoices.fields` includes the `dunning` summary group
- @e2e exclude backend-only manifest data — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)
