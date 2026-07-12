---
status: in-progress
---

# receipt-extraction-consume Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- receipt-extraction-consume

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

## Notes

- Canonical extraction contract (event + endpoint) owned by the docudesk
  `financial-document-field-extraction` spec; this is the consume side (ADR-022,
  event + one outbound request, no direct RPC into docudesk internals).
- Related canonical specs: `shillinq-bill-import-modal`, `expense-capture-core`,
  `bookkeeping-document-attachment-integration` (docudesk URI, REQ-DA-002),
  `semantic-invoice-consume` (drafts start inert, no accounting side effects on
  arrival).
