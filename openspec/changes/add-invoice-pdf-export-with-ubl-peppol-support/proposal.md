---
kind: code
depends_on: []
---

# Proposal: add-invoice-pdf-export-with-ubl-peppol-support

## Summary

Give Shillinq **outbound e-invoicing**: turn an issued `ARInvoice` into an
EN 16931-compliant **UBL 2.1 + NLCIUS** XML document, embed that XML inside a
**PDF/A-3 hybrid** (Factur-X / ZUGFeRD layout) produced by the existing
`InvoicePdfGenerator`, validate the debtor's **KvK / BTW-nummer / Peppol
participant ID** before send, and dispatch the document over **Peppol BIS
Billing 3.0** through a generalised transmission-adapter port (the openconnector
access point provides the real connector; a Log adapter is the dev/CI default).
Delivery is tracked as a first-class `deliveryStatus` sub-lifecycle on the
`ARInvoice`, driven declaratively and updated from openconnector delivery events.

## Motivation

The Netherlands has **already mandated e-invoicing for B2G**: every supplier
invoicing a Dutch government body must send a structured e-invoice (Peppol BIS /
NLCIUS), and central government rejects PDF-only invoices. A **draft B2B
e-invoicing law is expected in Q4 2026** aligning the NL with the EU ViDA
(VAT in the Digital Age) trajectory toward a **2030 structured-invoicing +
digital-reporting mandate**, with **Peppol as the preferred network**. Shillinq
today can only render a human-readable PDF (`InvoicePdfGenerator`); it produces
no structured XML and cannot transmit over Peppol, so it cannot invoice
government customers at all and will be non-compliant for B2B when the mandate
lands. The inbound half already exists (`SupplierInvoiceService::parseUblInvoice`
ingests Peppol-received UBL), and the PO side already has a Peppol transmission
port — this change closes the loop on the **AR outbound** side that
`bookkeeping-accounts-receivable-core` REQ-AR-009 explicitly deferred to "a
future T4 UBL/Peppol e-invoicing capability".

## Affected Projects

- [x] Project: `shillinq` — new e-invoicing capability (UBL/NLCIUS generation,
  PDF/A-3 embedding, KvK/BTW/Peppol validation, generalised transmission port +
  Log adapter, outbound event emission, delivery-status consume); AR-core delta
  adds a `deliveryStatus` field + Peppol delivery sub-lifecycle to `ARInvoice`.
- [ ] Project: `openconnector` — provides the real Peppol access-point connector
  and participant-lookup endpoint; consumes `nl.conduction.peppol.outbound.requested`
  and emits `nl.conduction.peppol.delivery.status`. Coupled via events only
  (ADR-022) — spec'd in the openconnector repo, referenced here, not modified here.

## Capabilities

- `bookkeeping-einvoicing-ubl-peppol` (NEW) — generation, embedding, validation,
  transmission, delivery-status consume.
- `bookkeeping-accounts-receivable-core` (MODIFIED) — `ARInvoice.deliveryStatus`
  field + declarative Peppol delivery sub-lifecycle.

## Scope

### In Scope

- Generate EN 16931 semantic-model UBL 2.1 XML restricted to the **NLCIUS**
  customisation (`urn:cen.eu:en16931:2017#compliant#urn:fdc:nen.nl:nlcius:v1.0`)
  from an issued `ARInvoice` + its lines + creditor/debtor master data.
- Embed the XML as an `AF_Relationship=Alternative` attachment in a **PDF/A-3**
  produced by extending `InvoicePdfGenerator` (Factur-X / ZUGFeRD hybrid).
- Pre-send validation of debtor identifiers: **KvK** (8-digit), **BTW-nummer**
  (NL VAT, `NL` + 9 digits + `B` + 2 digits, VIES-consistent), and **Peppol
  participant ID** existence (via the openconnector lookup contract).
- Generalise the existing PO-side `PeppolTransmissionAdapterInterface` into a
  document-type-agnostic port shared by PO (Order) and AR (Invoice); keep a
  **Log adapter** as the default DI binding.
- Emit `nl.conduction.peppol.outbound.requested`; consume
  `nl.conduction.peppol.delivery.status` and drive `ARInvoice.deliveryStatus`.
- A **Send e-invoice** action on the AR invoice detail surface, and a
  delivery-status indicator.

### Out of Scope

- The **inbound** Peppol receive path (already covered by `SupplierInvoiceService`).
- The physical Peppol access-point / SMP registration — owned by openconnector.
- **Digital reporting** (ViDA near-real-time reporting to the tax authority) —
  deferred to a future change once the reporting schema is published.
- Credit notes over Peppol (UBL CreditNote) — a follow-up; this change ships
  Invoice only.
- Non-NL CIUS profiles (e.g. Peppol BIS Billing without NLCIUS) — deferred.

## Approach

A stateless `ArInvoiceUblMapper` renders the NLCIUS XML (mirroring the existing
`PeppolBisOrderMapper` structure and reusing the CBC/CAC vocabulary that
`SupplierInvoiceService::parseUblInvoice` already speaks). `InvoicePdfGenerator`
gains a hybrid path that embeds the XML into a PDF/A-3. A
`EInvoiceValidationService` runs KvK/BTW/Peppol checks (reusing the existing VIES
integration). A generalised `Peppol\PeppolTransmissionPortInterface` (with a
`submit(participantId, documentType, payloadFileUri)` method) supersedes the
PO-only port; the PO service adopts it and the existing interface becomes a thin
alias so nothing regresses. Sending emits `nl.conduction.peppol.outbound.requested`;
an event listener consumes `nl.conduction.peppol.delivery.status` and advances the
declarative `deliveryStatus` sub-lifecycle. Details in design.md.

## New Dependencies

- A PDF/A-3 embedding capability. Prefer extending the current PDF toolchain;
  if the current renderer cannot produce PDF/A-3, add a single well-known PHP
  PDF library (documented in design.md). No new runtime service beyond
  openconnector (already a fleet dependency).

## Impact

- `lib/Service/InvoicePdfGenerator.php` — new hybrid PDF/A-3 embed path
  (backward compatible; the plain-PDF path is unchanged).
- `lib/Service/PurchaseOrder/PeppolTransmissionAdapterInterface.php` +
  `LogPeppolTransmissionAdapter.php` — generalised into a shared `Peppol` port;
  PO path adopts the shared port (no behavioural regression).
- `ARInvoice` schema — additive `deliveryStatus` field + delivery sub-lifecycle.
- New event listeners registered in `lib/AppInfo/Application.php`.

## Cross-Project Dependencies

- **openconnector** owns the Peppol access-point connector and the participant
  lookup `GET /participants/{peppolId}` → `{exists, supportedDocTypes[]}`.
  Coupling is **event-only**: shillinq emits
  `nl.conduction.peppol.outbound.requested`
  `{sourceApp, objectType:'ar-invoice', objectUri, recipientPeppolId,
  documentType:'ubl-invoice-2.1', payloadFileUri}` and consumes
  `nl.conduction.peppol.delivery.status`
  `{objectUri, transmissionId, status, timestamp, detail}`. The Log adapter lets
  shillinq function end-to-end (minus real transmission) when openconnector is
  absent.

## Risks

### Risk 1: NLCIUS / EN 16931 conformance failures rejected by the access point

**Severity:** High — **Mitigation:** Build against the official EN 16931
validation artefacts (Schematron); include a golden-file conformance test with a
known-good NLCIUS sample; gate transmission behind local validation so a
malformed document never leaves the app.

### Risk 2: Generalising the PO transmission port regresses the 3-way-match flow

**Severity:** Medium — **Mitigation:** Keep the existing
`PeppolTransmissionAdapterInterface` as a thin alias/extension of the shared
port; cover the PO path with its existing tests before and after; ship the
generalisation as a pure refactor with no behavioural delta.

### Risk 3: PDF/A-3 embedding needs a heavier PDF toolchain

**Severity:** Medium — **Mitigation:** Isolate the embed behind the generator's
hybrid method; if the current toolchain cannot emit PDF/A-3, add exactly one
vetted library and document the decision; the structured XML (the compliance
artefact) is transmitted independently of the PDF, so a PDF limitation never
blocks Peppol delivery.

### Risk 4: Debtor identifier data quality (missing KvK/BTW/Peppol ID)

**Severity:** Low — **Mitigation:** Validation runs pre-send and blocks with a
clear operator message; fall back to PDF + email when no Peppol participant is
found (mirrors the PO `null`-participant fallback).

## Rollback Strategy

The capability is additive: the `deliveryStatus` field and delivery lifecycle
default to `not-sent`, and the plain-PDF path is untouched. Rolling back means
disabling the **Send e-invoice** action and reverting the new services + register
fragment; existing invoices and the human-readable PDF are unaffected. No data
migration is destructive (the new field is optional with a default).

## Open Questions

- Whether openconnector exposes participant lookup synchronously (HTTP) or via a
  request/response event pair — resolved by adopting the brief's
  `GET /participants/{peppolId}` contract; revisit if openconnector diverges.
