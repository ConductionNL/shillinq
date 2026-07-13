---
kind: code
depends_on: []
---

# Proposal: receipt-extraction-consume

## Summary

Turn Shillinq's honestly-stubbed PDF/receipt path into a real, AI-assisted capture
flow by **consuming docudesk's financial field-extraction**. Shillinq subscribes to
`nl.conduction.docudesk.extraction.completed`, maps the extracted fields onto a
**BillImportModal** prefill (for supplier invoices) or a **Receipt** draft (for
expense capture), **displays per-field confidence**, lets the operator **correct
every field before committing**, and offers a **re-request** path that calls
docudesk `POST /api/extraction/financial`. No fabricated data: fields come from
docudesk or the operator, each carrying a confidence score.

## Motivation

`SupplierInvoiceImportController::import()` today deliberately returns HTTP 422 with
`deferred: pdf-ocr` for PDF uploads — "no fake extraction" (REQ-BIM-002) — and the
`Receipt` schema's `extractedText` is an explicit "OCR placeholder; populated in
T3" (expense-capture-core REQ-EC-002). That T3 population is exactly what docudesk
now provides. MKB and gemeente users photograph receipts and drop in PDF invoices
and expect the fields to fill themselves; doing the extraction inside shillinq
would duplicate docudesk's document intelligence and violate ADR-022. The right
move is to consume docudesk's extraction contract over events and render it with
honest, visible confidence so the human stays in control of what gets booked.

## Affected Projects

- [x] Project: `shillinq` — an extraction-completed event listener + a prefill/
  correction service, per-field confidence display in `BillImportModal` and the
  `Receipt` capture surface, and a re-request path to docudesk.
- [ ] Project: `docudesk` — owns the extraction engine and the canonical contract
  (`financial-document-field-extraction` spec): the
  `nl.conduction.docudesk.extraction.completed` event and the
  `POST /api/extraction/financial` request endpoint. Coupled via event + one
  outbound request (ADR-022); spec'd in docudesk, referenced here, not modified.

## Capabilities

- `receipt-extraction-consume` (NEW).

## Scope

### In Scope

- A listener for `nl.conduction.docudesk.extraction.completed` with payload
  `{documentUri, requestedBy, sourceApp, docType: 'receipt'|'supplier-invoice',
  fields: {...}, fieldConfidence: {<field>: 0..1}, overallConfidence: 0..1}`.
- Mapping the `fields` onto a **draft prefill**: a `SupplierInvoice`/bill draft for
  `docType: 'supplier-invoice'`, a `Receipt` draft for `docType: 'receipt'`,
  storing per-field confidence alongside the values.
- Per-field **confidence display** in `BillImportModal` and the `Receipt` capture
  surface; low-confidence fields flagged for review; `overallConfidence` gates
  whether commit is one-click or requires explicit review.
- A **user correction flow**: every extracted field is editable before commit;
  corrections are recorded (audit + provenance) and never silently overwritten.
- A **re-request path**: the operator can (re)trigger extraction via docudesk
  `POST /api/extraction/financial` with `{fileId or documentUri, docType,
  callbackEvent: true}`.

### Out of Scope

- The extraction engine itself (OCR/LLM field detection) — owned by docudesk.
- Auto-posting without human confirmation — a human always confirms before a
  bill/receipt is committed (confidence informs, never bypasses, the human).
- Replacing the UBL/CSV import paths — those remain; this adds the PDF/photo path.
- Storing the source document — it stays in docudesk, referenced by
  `sourceDocumentUri` (`docudesk://attachments/<uuid>/<filename>`,
  bookkeeping-document-attachment-integration REQ-DA-002).

## Approach

`ExtractionCompletedListener` receives the event, resolves the target draft by
`documentUri` + `docType`, and hands the payload to
`ExtractionPrefillService`, which writes a draft (`SupplierInvoice` or `Receipt`)
with values + a parallel `fieldConfidence` map and a `sourceDocumentUri` back to
docudesk. The frontend renders each field with its confidence and a review flag;
the operator edits and commits. A `POST /api/extraction/financial` client method
(re)requests extraction with a callback. Details in design.md.

## New Dependencies

None new in shillinq. Depends on the docudesk-side
`financial-document-field-extraction` contract (event + endpoint), consumed via the
NC event bus and one HTTP request.

## Impact

- New `lib/Listener/ExtractionCompletedListener.php`,
  `lib/Service/Extraction/ExtractionPrefillService.php`, and a docudesk
  extraction-request client.
- `src/modals/BillImportModal.vue` — prefill + per-field confidence + correction;
  the PDF path can now request extraction instead of only surfacing the 422 hint.
- `Receipt` capture surface — confidence-aware prefill.
- Listener/client registration in `lib/AppInfo/Application.php`.

## Cross-Project Dependencies

- **docudesk** owns the `nl.conduction.docudesk.extraction.completed` event and the
  `POST /api/extraction/financial` endpoint (canonical home: the docudesk
  `financial-document-field-extraction` spec). Coupling is event + one outbound
  request; no shared database, no direct RPC into docudesk internals.

## Risks

### Risk 1: Trusting low-confidence extractions

**Severity:** High — **Mitigation:** Per-field confidence is always displayed;
fields below a threshold are flagged for review; `overallConfidence` gates
one-click commit; a human confirms before anything is booked. No auto-post.

### Risk 2: Event arrives with no matching draft / unknown documentUri

**Severity:** Medium — **Mitigation:** The listener resolves by `documentUri`;
an unmatched event creates a pending-review draft rather than being dropped, and is
surfaced to the requester (`requestedBy`).

### Risk 3: docudesk unavailable

**Severity:** Low — **Mitigation:** The PDF path falls back to the existing honest
422 deferral (REQ-BIM-002) and the UBL/CSV import paths; the re-request is
retriable. No fabricated extraction is ever shown.

## Rollback Strategy

Additive. Rollback = unregister the listener + hide the confidence UI + disable the
re-request button; the PDF path reverts to the existing 422 deferral, and UBL/CSV
imports are unaffected.

## Open Questions

- The confidence threshold for the review flag and the one-click-commit gate —
  proposed 0.8 per-field / 0.9 overall; tunable at apply time, does not change the
  observable "confidence is shown and a human confirms" behaviour.
