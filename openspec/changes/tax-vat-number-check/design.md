# Design: tax-vat-number-check

Read at shillinq development `79f438f33` on 2026-09-27.

## Context

- **Service.** `lib/Service/ViesService.php::validate(administrationId, vatId, now)` (line 113) calls VIES through Nextcloud's HTTP client, stores a `ViesValidation` (`vatId`, `valid`, `validationTimestamp`, `validUntil`, `name`, `address`, `requestId`, `outage`), reuses a valid record younger than 30 days during an outage (line 147) and hands outages to `ViesOutageRetryJob`.
- **Callers.** `EInvoiceValidationService` calls it before sending an e-invoice. `IcpController::lookupVatId` (`appinfo/routes.php:482`, `POST /api/icp/vat-id-lookup`, reading `administration_id` at line 234) wraps it; nothing in `src/` calls that route.
- **Customer fields.** `CustomerMaster` has `vatId`, `vatIdValidatedAt`, `vatIdValidUntil`, `vatIdValidationStatus` (`register.d/bookkeeping-icp-opgaaf.json`) and, separately, `vatID` (`register.d/add-shillinq-bookkeeping-compliance.json`): two spellings of one fact.
- **Supplier fields.** `VendorMaster` is shillinq's financial profile keyed by the Nextcloud contact (spec `shillinq-product-vendor-to-pipelinq`); it has no VAT number. `SupplierInvoice` has totals but no seller VAT number, although a UBL invoice carries one (`AccountingSupplierParty/Party/PartyTaxScheme/CompanyID`), which `SupplierInvoiceService::ingestUBLInvoice()` reads past.
- **Page.** `CustomerDetail` is at `src/manifest.json:7587`.

## Goals / Non-Goals

**Goals**
- A person checks any customer or supplier VAT number from its page.
- A foreign supplier's number is checked when its invoice arrives.

**Non-Goals**
- Blocking postings, non-EU registers.

## Decisions

### D1. One service, three triggers

`ViesService::validate()` stays the only path. Triggers: the Check VAT
number header action on `CustomerDetail` and on the vendor profile page
(calling `lookupVatId`), and `SupplierInvoiceService` after intake when
`sellerVatId` starts with an EU country code other than NL. The result is
written to the record's `vatIdValidationStatus`, `vatIdValidatedAt` and
`vatIdValidUntil`.

### D2. Suppliers get the same fields

`VendorMaster` gains `vatId` and the three validation fields.
`SupplierInvoice` gains `sellerVatId`, filled by the UBL ingest and
editable on a typed invoice; when it differs from the vendor profile's
`vatId`, the invoice shows both.

### D3. One spelling for customers

A repair step copies `vatID` into `vatId` where `vatId` is empty and removes
`vatID` from the schema.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Validation fields | Declarative: schema properties | Data. |
| The VIES call | Imperative, the existing service (ADR-031 exception: external lookup) | An external register. |
| Showing status on invoices | Declarative: page config reading the related record | View. |

## Seed Data

Customers of Adviesbureau Van Dijk: Brightside Consulting Ltd is outside
the EU (no check); Kunstverlag Müller GmbH, Köln, VAT id `DE000000000`
(placeholder), status invalid on 2026-09-20. Supplier Softwarehuis BVBA,
Gent, VAT id `BE0000000000` (placeholder), status valid until 2026-10-20.

## Risks / Trade-offs

- [Rate limits at VIES] → checks are per record on demand and per incoming foreign invoice, reusing a fresh result.

## Migration Plan

The repair step of D3. No other data changes.

## Open Questions

None.
