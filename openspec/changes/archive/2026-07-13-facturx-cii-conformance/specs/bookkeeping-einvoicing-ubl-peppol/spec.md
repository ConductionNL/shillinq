# bookkeeping-einvoicing-ubl-peppol Specification (delta)

**Status**: archived 2026-07-13 (synced into the canonical spec)
**Scope**: shillinq
**OpenSpec changes**:
- facturx-cii-conformance

## MODIFIED Requirements

### Requirement: REQ-EINV-002 — The system SHALL embed the UBL XML into a PDF as an Associated File, honestly labelled

`InvoicePdfGenerator` MUST gain a hybrid path that attaches the generated NLCIUS
UBL XML into a PDF document as an embedded file with `AFRelationship = Alternative`,
under the filename `ubl-invoice.xml` (`InvoicePdfGenerator::HYBRID_XML_FILENAME`),
producing a single artefact that is both human-readable and machine-readable for
the NL/Peppol UBL path. The existing plain-PDF path
(`generatePdf(...): {filename, html, mimeType}`) MUST remain unchanged and
callable.

The artefact MUST NOT claim conformance it does not meet:
- it MUST NOT be named or labelled as a Factur-X/ZUGFeRD document (those
  formats require UN/CEFACT CII syntax; the embedded payload is UBL — see
  REQ-EINV-008 for the explicit non-goal);
- it MUST NOT assert `pdfaid:part`/`pdfaid:conformance` PDF/A XMP metadata
  unless the generator actually emits the corresponding ISO 19005-3
  requirements (at minimum an ICC `OutputIntent` and fully embedded fonts) —
  today it emits neither, so no `pdfaid:*` claim MUST be present.
<!-- Previous behavior: the filename was the Factur-X/ZUGFeRD well-known
     name `factur-x.xml` and the XMP metadata asserted
     `pdfaid:part=3`/`pdfaid:conformance=B` (PDF/A-3B) despite the
     generator emitting neither an ICC OutputIntent nor embedded fonts —
     a false conformance claim on both axes (facturx-cii-conformance). -->

#### Scenario: Hybrid export embeds the XML under a truthful filename

- GIVEN an issued `ARInvoice` and its rendered NLCIUS XML
- WHEN the hybrid export is generated
- THEN the returned artefact MUST be `application/pdf`
- AND the UBL XML MUST be retrievable as an embedded file named
  `ubl-invoice.xml` with `AFRelationship = Alternative`
- AND the PDF's XMP metadata MUST NOT contain a `pdfaid:part` or
  `pdfaid:conformance` assertion

#### Scenario: Plain PDF path is unaffected

- GIVEN the existing `generatePdf(invoice, lines, creditor, recipient)` call
- WHEN invoked without requesting the hybrid path
- THEN it MUST return the same `{filename, html, mimeType}` shape as before

## ADDED Requirements

### Requirement: REQ-EINV-008 — True Factur-X/ZUGFeRD (UN/CEFACT CII) invoice output is explicitly NOT provided

The system SHALL NOT represent its hybrid PDF export as a Factur-X/ZUGFeRD
invoice to any caller, document, or trading partner. Factur-X (FR) and
ZUGFeRD (DE) both mandate an embedded **UN/CEFACT CrossIndustryInvoice
(CII)** XML document, not UBL; Shillinq's e-invoicing pipeline generates
NLCIUS **UBL 2.1** (REQ-EINV-001) for the NL/Peppol market and does not
generate CII. A DE/FR trading partner requiring true Factur-X/ZUGFeRD
conformance MUST be told this generator cannot serve that requirement
rather than being handed a UBL payload under a Factur-X-branded filename.

#### Scenario: A DE/FR trading partner cannot be served Factur-X/ZUGFeRD by this generator

@e2e exclude pure backend/document-generation logic — not browser-testable

- GIVEN a trading partner that requires true Factur-X/ZUGFeRD (CII) conformance
- WHEN Shillinq's e-invoicing pipeline is asked to produce that partner's
  invoice artefact
- THEN `InvoicePdfGenerator::generateHybridPdf()` MUST NOT be presented as
  satisfying that requirement — the embedded XML is UBL, the filename is
  `ubl-invoice.xml` (not a Factur-X/ZUGFeRD well-known name), and no
  `pdfaid:*` PDF/A conformance metadata is asserted
- AND a genuine UN/CEFACT CII generator is out of scope until a follow-up
  change ships one behind a profile flag with a real conformance validator
  in the loop (KoSIT/Mustang for CII, veraPDF for PDF/A)

#### Scenario: NL/Peppol UBL delivery is unaffected

@e2e exclude pure backend/document-generation logic — not browser-testable

- GIVEN an NL debtor with a valid Peppol participant ID
- WHEN an issued `ARInvoice` is sent via `EInvoiceService::sendInvoice()`
- THEN the NLCIUS UBL 2.1 XML (REQ-EINV-001) is generated, embedded in the
  hybrid PDF (REQ-EINV-002), and transmitted over Peppol exactly as before
  this change — only the embedded filename and the (now absent) false
  PDF/A-3B XMP claim changed
