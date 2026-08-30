# Tasks: receipt-extraction-consume

## Implementation Tasks

### Task 1: Draft confidence fields on SupplierInvoice + Receipt (declarative) + seed
- **spec_ref**: `openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-001`
- **files**: `lib/Settings/register.d/receipt-extraction-consume.json`, `lib/Settings/register.d/_registers.json`
- **acceptance_criteria**:
  - GIVEN the register fragment WHEN loaded THEN `SupplierInvoice` and `Receipt` drafts accept optional `fieldConfidence` map, `overallConfidence`, `humanCorrected`, and a URI-validated `sourceDocumentUri`
  - GIVEN install seed WHEN loaded THEN low- and high-confidence draft examples exist for demo
- [x] Implement
- [x] Test

### Task 2: Consume extraction-completed events into drafts
- **spec_ref**: `openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-001`
- **files**: `lib/Listener/ExtractionCompletedListener.php`, `lib/Service/Extraction/ExtractionPrefillService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a `supplier-invoice` extraction event WHEN processed THEN an uncommitted draft with field values, confidence map, and `sourceDocumentUri` is created (no auto-commit)
  - GIVEN an event whose `documentUri` matches no draft WHEN processed THEN a pending-review draft is created and surfaced to `requestedBy` (not dropped)
- [x] Implement
- [x] Test

### Task 3: BillImportModal prefill + confidence + review flags
- **spec_ref**: `openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-002`
- **files**: `src/modals/BillImportModal.vue`, `src/components/FieldConfidenceBadge.vue`
- **acceptance_criteria**:
  - GIVEN a draft with mixed confidence WHEN the modal opens THEN every field is prefilled, shows its confidence, and sub-threshold fields are flagged for review
  - GIVEN a PDF with no extraction WHEN submitted THEN the existing `deferred: pdf-ocr` message shows and no fields are fabricated
- [x] Implement
- [x] Test

### Task 4: Receipt capture surface prefill with confidence
- **spec_ref**: `openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-003`
- **files**: `src/views/ReceiptCapture.vue`
- **acceptance_criteria**:
  - GIVEN a `receipt` extraction event WHEN the capture surface opens the draft THEN `amount`, `receiptDate`, `currency`, `extractedText` are prefilled with confidence and `sourceDocumentUri` references the docudesk document
- [x] Implement
- [x] Test

### Task 5: Correction flow with provenance
- **spec_ref**: `openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004`
- **files**: `src/modals/BillImportModal.vue`, `lib/Service/Extraction/ExtractionPrefillService.php`
- **acceptance_criteria**:
  - GIVEN a low-confidence field WHEN the operator edits and commits THEN the committed value is the operator's and the record retains that the field was human-corrected alongside the original extracted value/confidence
- [x] Implement
- [x] Test

### Task 6: Re-request path to docudesk + human-confirmation gate
- **spec_ref**: `openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005`
- **files**: `lib/Service/Extraction/DocudeskExtractionClient.php`, `lib/Controller/ExtractionRequestController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a low-confidence document WHEN the operator triggers re-request THEN `POST /api/extraction/financial` is called with `callbackEvent:true` and the resulting event updates the draft (REQ-RXC-001)
  - GIVEN a draft with `overallConfidence: 0.99` WHEN opened THEN commit still requires an explicit human confirmation (no auto-post) (REQ-RXC-006)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off and `openspec validate` passes
- [x] No-auto-commit + PDF-without-extraction deferral covered by tests; UBL/CSV import paths re-tested (no regression)
- [ ] Manual browser test of extraction prefill, confidence display, correction, and re-request — NOT live-verified in this apply (see apply report); Playwright coverage authored data-defensively, not executed against a live instance

## Quality checklist

- [x] All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- [x] Extraction-request proxy endpoint covered by a Newman/Postman test (`tests/integration/shillinq.postman_collection.json` — "Extraction (receipt-extraction-consume)" folder)
- [x] Modal + capture UI covered by Playwright browser tests (`tests/e2e/receipt-extraction-consume.spec.ts`)
- [x] All tests pass (PHPUnit 3416/3416 modulo the 2 pre-existing env errors; vitest all green)
- [x] Feature documentation updated in `docs/` (`docs/user-guide/bookkeeping/receipt-extraction-consume.md`)
- [x] Dutch (`nl_NL`) and English (`en_US`) strings added; keys English. Deviation: "Save"/"Opslaan" reused for the commit action instead of a separate "Bevestigen" label, for consistency with the existing BillImportModal Save button (REQ-BIM-003) — same action, one label across the app.
- [x] `openspec validate` passes
