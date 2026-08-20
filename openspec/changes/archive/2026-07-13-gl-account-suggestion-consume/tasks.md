# Tasks: gl-account-suggestion-consume

## Implementation Tasks

### Task 1: Register schema fields (docudeskExtractionId, suggestedGlAccount, Receipt.glAccount)
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-001`
- **files**: `lib/Settings/register.d/gl-account-suggestion-consume.json`
- **acceptance_criteria**:
  - GIVEN the register fragment is imported WHEN `SupplierInvoice`/`Receipt` objects are validated THEN `docudeskExtractionId` and `suggestedGlAccount` are accepted as additive optional fields
  - GIVEN a `Receipt` object WHEN `glAccount` is set THEN it validates as an additive optional string field
- [x] Implement
- [x] Test

### Task 2: Capture the extraction id from the synchronous extraction-request response
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-001`
- **files**: `lib/Service/Extraction/DocudeskExtractionClient.php`, `lib/Controller/ExtractionRequestController.php`
- **acceptance_criteria**:
  - GIVEN docudesk returns 201 with `{id: "ext-123"}` WHEN an existing draft's id was supplied THEN the draft is persisted with `docudeskExtractionId: "ext-123"`
  - GIVEN no existing draft id was supplied WHEN the request succeeds THEN no error is raised
- [x] Implement
- [x] Test

### Task 3: ChartOfAccountsCandidateService — administration-scoped active candidates
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-002`
- **files**: `lib/Service/Extraction/ChartOfAccountsCandidateService.php`
- **acceptance_criteria**:
  - GIVEN accounts in two administrations WHEN candidates are resolved for one THEN only that administration's active accounts are returned
  - GIVEN a blocked/archived account WHEN candidates are resolved THEN it is excluded
- [x] Implement
- [x] Test

### Task 4: GlAccountSuggestionClient — request suggestion + post correction
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-003`
- **files**: `lib/Service/Extraction/GlAccountSuggestionClient.php`
- **acceptance_criteria**:
  - GIVEN a valid extraction id and candidates WHEN a suggestion is requested THEN the decoded docudesk response is returned
  - GIVEN docudesk is unreachable WHEN a suggestion or correction is posted THEN the call fails soft (no throw) and is logged
- [x] Implement
- [x] Test

### Task 5: ExtractionRequestController.suggestGlAccount proxy action + route
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-006`
- **files**: `lib/Controller/ExtractionRequestController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a draft with no known `docudeskExtractionId` WHEN a suggestion is requested THEN the response is `{suggestion: null, reason: "extraction-id-unknown"}`, not an error
  - GIVEN a draft outside the caller's administration WHEN a suggestion is requested THEN a masked 404 is returned (IDOR guard)
- [x] Implement
- [x] Test

### Task 6: ExtractionRequestController.confirm — post booking correction back to docudesk
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-005`
- **files**: `lib/Controller/ExtractionRequestController.php`
- **acceptance_criteria**:
  - GIVEN a known `docudeskExtractionId` and a committed `glAccount` WHEN the draft is confirmed THEN a correction is posted with the operator's chosen code, whether or not it matches the suggestion
  - GIVEN the correction POST fails WHEN the draft is confirmed THEN the local booking still succeeds
- [x] Implement
- [x] Test

### Task 7: BillImportModal — surface suggestion, "use suggestion", graceful degradation
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-003`
- **files**: `src/modals/BillImportModal.vue`, `src/modals/billImportModal.js`, `src/utils/extractionConfidence.js`
- **acceptance_criteria**:
  - GIVEN a suggestion is available WHEN the review step renders THEN the code/label/confidence/rationale are shown and "Use suggestion" fills the picker without committing
  - GIVEN no known extraction id or docudesk unreachable WHEN the review step renders THEN no suggestion block is shown and manual booking works unchanged
- [x] Implement
- [x] Test

### Task 8: ReceiptCapture — glAccount field + suggestion parity
- **spec_ref**: `openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-003`
- **files**: `src/views/ReceiptCapture.vue`, `src/views/receiptCapture.js`
- **acceptance_criteria**:
  - GIVEN a Receipt draft with a suggestion available WHEN the capture page renders THEN the same suggestion display + "use suggestion" behaviour as BillImportModal is shown
  - GIVEN no suggestion available WHEN the capture page renders THEN the GL-account field still works as plain manual input
- [x] Implement
- [x] Test

## Verification

Track via the Task N checkboxes above; `openspec validate` must pass; manual testing against
acceptance criteria and code review against spec requirements happen before archive.

## Tests (company-wide ADR-009)

PHPUnit unit tests required for all new/changed business logic (`tests/Unit/`). Newman/Postman:
N/A — no new externally-facing REST contract beyond the existing `ExtractionRequestController`
proxy pattern already covered. Browser tests: covered via vitest specs referenced in the
capability spec (`@e2e src/modals/**/BillImportModal*.spec.js`), matching
`receipt-extraction-consume`'s established convention. All tests must pass (`composer test`,
vitest) before push.

## Documentation (company-wide ADR-010)

Feature documentation update deferred to the next docs sync pass — this is an incremental
enhancement to the already-documented BillImportModal/ReceiptCapture flows, tracked in the apply
report rather than blocking this change.

## i18n (company-wide ADR-005)

Dutch (`nl_NL`) and English (`en_US`) translation strings required for any new user-facing
strings introduced by Tasks 7–8; keys in English per fleet convention.
