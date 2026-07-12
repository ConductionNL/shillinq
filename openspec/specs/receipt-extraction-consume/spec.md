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
