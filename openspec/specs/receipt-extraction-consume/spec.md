---
capability: receipt-extraction-consume
status: done
built_by: openspec/changes/archive/2026-07-13-receipt-extraction-consume
---

# receipt-extraction-consume Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- [receipt-extraction-consume](../../changes/archive/2026-07-13-receipt-extraction-consume/) _(done)_

## Purpose

Consumes docudesk's financial field-extraction to prefill supplier-invoice
(BillImportModal) and expense (Receipt) drafts with extracted fields and per-field
confidence, with an always-editable correction flow and a re-request path. Shillinq
never runs OCR itself (ADR-022) and never shows fabricated data — replacing the
honest `deferred: pdf-ocr` stub (shillinq-bill-import-modal REQ-BIM-002) and
populating the `Receipt.extractedText` T3 placeholder (expense-capture-core
REQ-EC-002).
## Requirements
### Requirement: REQ-RXC-000 — Shillinq SHALL prefill bill and receipt drafts from docudesk extraction with visible confidence and human confirmation

The system SHALL consume docudesk's `nl.conduction.docudesk.extraction.completed`
event to prefill a supplier-invoice (BillImportModal) or `Receipt` draft with the
extracted fields and per-field confidence, MUST let the operator correct any field,
and MUST require a human confirmation before commit — never fabricating data and
never running OCR itself (ADR-022). Detailed sub-requirements (REQ-RXC-001 …
REQ-RXC-006) are authored in the in-progress change delta and synced here on archive.

#### Scenario: An extraction event prefills a draft that a human confirms

- GIVEN a `nl.conduction.docudesk.extraction.completed` event for an uploaded PDF
- WHEN shillinq processes it
- THEN an uncommitted draft MUST be created with the extracted values and per-field
  confidence, and the operator MUST be able to correct fields and MUST confirm before
  the draft is committed

The normative requirements (REQ-RXC-001 … REQ-RXC-006) are authored as the change
delta at
`openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md`
and will be synced into this canonical spec on archive (`openspec sync`). They
cover: consuming `nl.conduction.docudesk.extraction.completed` into confidence-scored
uncommitted drafts, BillImportModal + Receipt prefill with confidence and review
flags, the correction flow with provenance, the `POST /api/extraction/financial`
re-request path, and the always-required human-confirmation gate.

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

## Notes

- Canonical extraction contract (event + endpoint) owned by the docudesk
  `financial-document-field-extraction` spec; this is the consume side (ADR-022,
  event + one outbound request, no direct RPC into docudesk internals).
- Related canonical specs: `shillinq-bill-import-modal`, `expense-capture-core`,
  `bookkeeping-document-attachment-integration` (docudesk URI, REQ-DA-002),
  `semantic-invoice-consume` (drafts start inert, no accounting side effects on
  arrival).
