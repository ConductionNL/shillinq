# receipt-extraction-consume Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- receipt-extraction-consume

## Purpose

Consumes docudesk's financial field-extraction to prefill supplier-invoice
(BillImportModal) and expense (Receipt) drafts with extracted fields and per-field
confidence, keeping a human in control via an always-editable correction flow and a
re-request path. Shillinq never runs OCR itself (ADR-022) and never shows
fabricated data — every value comes from docudesk or the operator. The canonical
extraction contract (event + endpoint) is owned by the docudesk
`financial-document-field-extraction` spec; this spec is the consume side.

## ADDED Requirements

@e2e exclude the event listener + extraction-request client are backend/integration surfaces; the modal prefill, confidence display, and correction flow are browser-testable (covered by REQ-RXC-002).

### Requirement: REQ-RXC-001 — The system SHALL consume docudesk extraction-completed events into confidence-scored drafts

The system MUST register a listener for `nl.conduction.docudesk.extraction.completed`
with payload `{documentUri, requestedBy, sourceApp, docType:
'receipt'|'supplier-invoice', fields, fieldConfidence: {<field>: 0..1},
overallConfidence: 0..1}`. On receipt it MUST resolve the target by `documentUri`
and `docType` and write a **draft** — a `SupplierInvoice`/bill draft for
`supplier-invoice`, a `Receipt` draft for `receipt` — populated from `fields`, with
the `fieldConfidence` map and `overallConfidence` persisted alongside the values and
`sourceDocumentUri` set to the docudesk document
(`docudesk://attachments/<uuid>/<filename>`,
bookkeeping-document-attachment-integration REQ-DA-002). The draft MUST NOT be
auto-committed. An event whose `documentUri` matches no pending draft MUST create a
pending-review draft (not be dropped) and be surfaced to `requestedBy`.

#### Scenario: Supplier-invoice extraction becomes a confidence-scored draft

- GIVEN a `nl.conduction.docudesk.extraction.completed` event with
  `docType: 'supplier-invoice'`, `fields.invoiceNumber: "F-2026-88"`,
  `fields.totalIncl: 1210.00`, `fieldConfidence.invoiceNumber: 0.97`,
  `overallConfidence: 0.93`
- WHEN the listener processes it
- THEN a supplier-invoice draft MUST be created with those field values, the
  per-field confidence map persisted, `sourceDocumentUri` set to the event's
  `documentUri`, and the draft left uncommitted

#### Scenario: Unmatched documentUri is not dropped

- GIVEN an extraction event whose `documentUri` matches no pending draft
- WHEN the listener processes it
- THEN a pending-review draft MUST be created and surfaced to `requestedBy`, not
  silently discarded

### Requirement: REQ-RXC-002 — BillImportModal SHALL prefill from extraction and display per-field confidence

The `BillImportModal` MUST render the extracted supplier-invoice draft with each
field pre-filled and its confidence visible; fields with confidence below the review
threshold MUST be flagged for review. When `overallConfidence` is below the
one-click-commit gate the operator MUST be required to review before commit; at or
above it, commit MAY be one-click. When no docudesk extraction is available for a
PDF upload, the modal MUST retain the existing honest deferral
(shillinq-bill-import-modal REQ-BIM-002, HTTP 422 `deferred: pdf-ocr`) rather than
fabricating fields.

#### Scenario: Prefilled fields show confidence and a review flag

- @e2e src/modals/**/BillImportModal*.spec.js
- GIVEN a supplier-invoice draft with `invoiceNumber` confidence 0.97 and `glAccount`
  confidence 0.55, review threshold 0.8
- WHEN the operator opens the BillImportModal
- THEN every field MUST be pre-filled with its value, each MUST show its confidence,
  and `glAccount` MUST be flagged for review

#### Scenario: PDF with no extraction keeps the honest deferral

- GIVEN a PDF upload for which no docudesk extraction is available
- WHEN the operator submits it
- THEN the modal MUST surface the existing `deferred: pdf-ocr` message and MUST NOT
  fabricate extracted fields

### Requirement: REQ-RXC-003 — The Receipt capture surface SHALL prefill from extraction with confidence

For `docType: 'receipt'`, the `Receipt` capture surface MUST prefill `amount`,
`receiptDate`, `currency`, category, and `extractedText` from the extraction and
MUST display per-field confidence. `Receipt.extractedText` (the T3 OCR placeholder
of expense-capture-core REQ-EC-002) MUST be populated from the extraction, and
`Receipt.photoUri` / `sourceDocumentUri` MUST reference the docudesk document.

#### Scenario: Photographed receipt is prefilled with confidence

- GIVEN an extraction event `docType: 'receipt'` with `fields.totalIncl: 45.00`,
  `fields.issueDate: "2026-02-10"`, `fields.currency: "EUR"`
- WHEN the Receipt capture surface opens the draft
- THEN `amount`, `receiptDate`, `currency` and `extractedText` MUST be prefilled with
  visible confidence, and `sourceDocumentUri` MUST point at the docudesk document

### Requirement: REQ-RXC-004 — Every extracted field SHALL be correctable before commit and corrections recorded

The system SHALL allow the operator to edit every extracted field before committing
the draft; an operator edit MUST override the extracted value and MUST record that
the field was human-corrected (for audit/provenance), never silently discarding the
original extracted value or its confidence.

#### Scenario: Operator corrects a low-confidence field

- GIVEN a draft where `glAccount` was extracted at confidence 0.55
- WHEN the operator changes `glAccount` and commits
- THEN the committed value MUST be the operator's, and the record MUST retain that
  `glAccount` was human-corrected alongside the original extracted value/confidence

### Requirement: REQ-RXC-005 — The system SHALL offer a re-request path to docudesk extraction

The system SHALL let the operator (re)request extraction for a document by calling
docudesk `POST /api/extraction/financial` with `{fileId or documentUri, docType,
callbackEvent: true}`; the resulting `nl.conduction.docudesk.extraction.completed`
event MUST flow back through REQ-RXC-001. The request is the only outbound coupling
to docudesk; shillinq MUST NOT call docudesk's internal extraction logic directly.

#### Scenario: Re-request produces a fresh extraction draft

- GIVEN a document whose first extraction was low confidence
- WHEN the operator triggers re-request (`POST /api/extraction/financial` with
  `callbackEvent: true`)
- THEN a subsequent `extraction.completed` event MUST update the draft via
  REQ-RXC-001

### Requirement: REQ-RXC-006 — Confidence SHALL inform, never bypass, human confirmation

The system MUST NOT auto-commit a bill or receipt on the basis of confidence alone:
a human confirmation is always required before commit. Confidence and the
review-threshold/one-click-gate MUST only affect how much review the UI demands, not
whether a human confirms.

#### Scenario: Even a fully-confident extraction requires confirmation

- GIVEN a supplier-invoice draft with `overallConfidence: 0.99`
- WHEN the operator opens it
- THEN commit MUST still require an explicit human confirmation action (it MAY be
  one-click, but it MUST NOT post automatically)

## Non-Functional Requirements

- **Performance:** listener processing of a single extraction event MUST complete
  within 1s server-side; UI prefill MUST render without a blocking spinner beyond the
  draft fetch.
- **Accessibility:** confidence indicators and review flags MUST meet WCAG 2.1 AA and
  MUST NOT convey status by colour alone (text/label required).
- **Internationalization:** Dutch and English MUST be supported (ADR-005); i18n keys
  in English.

## Acceptance Criteria

- [ ] `nl.conduction.docudesk.extraction.completed` consumed into an uncommitted, confidence-scored draft (supplier-invoice or receipt)
- [ ] BillImportModal + Receipt surface prefill with per-field confidence and review flags; PDF-without-extraction keeps the honest 422 deferral
- [ ] Every field correctable before commit; corrections recorded with provenance
- [ ] Re-request via docudesk `POST /api/extraction/financial` round-trips back through the listener
- [ ] No auto-commit: a human always confirms

## Notes

- Canonical extraction contract owned by docudesk `financial-document-field-extraction`
  (event + `POST /api/extraction/financial`); this spec is the consume side.
- Related: `shillinq-bill-import-modal` (REQ-BIM-002 honest deferral retained),
  `expense-capture-core` (REQ-EC-002 `Receipt.extractedText` T3 population),
  `bookkeeping-document-attachment-integration` (REQ-DA-002 docudesk URI).
