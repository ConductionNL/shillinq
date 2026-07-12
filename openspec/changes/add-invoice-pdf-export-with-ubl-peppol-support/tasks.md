# Tasks: add-invoice-pdf-export-with-ubl-peppol-support

## Implementation Tasks

### Task 1: Declare ARInvoice delivery fields + sub-lifecycle + seed data
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-011`
- **files**: `lib/Settings/register.d/add-shillinq-einvoicing-ubl-peppol.json`, `lib/Settings/register.d/_registers.json` (seed)
- **acceptance_criteria**:
  - GIVEN the register fragment WHEN OR loads it THEN `ARInvoice` gains optional `deliveryStatus`/`transmissionId`/`payloadFileUri`/`deliveryDetail` and a delivery `x-openregister-lifecycle` keyed on `deliveryStatus`
  - GIVEN a pre-existing ARInvoice with no `deliveryStatus` WHEN re-validated THEN it passes and defaults to `not-sent`
  - GIVEN install seed WHEN loaded THEN the three seed ARInvoices carry realistic delivery-status values
- [x] Implement
- [x] Test

### Task 2: NLCIUS UBL 2.1 mapper
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-001`
- **files**: `lib/Service/EInvoice/ArInvoiceUblMapper.php`
- **acceptance_criteria**:
  - GIVEN an issued ARInvoice + lines + parties WHEN mapped THEN well-formed UBL 2.1 with NLCIUS `CustomizationID` and `PayableAmount` = gross
  - GIVEN a draft ARInvoice WHEN mapping is requested THEN it refuses naming the required `issued` state
- [x] Implement
- [x] Test

### Task 3: Hybrid PDF/A-3 embedding in InvoicePdfGenerator
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-002`
- **files**: `lib/Service/InvoicePdfGenerator.php`
- **acceptance_criteria**:
  - GIVEN a rendered UBL XML WHEN the hybrid export runs THEN a PDF/A-3 with the XML embedded as `AFRelationship=Alternative` is produced
  - GIVEN the existing `generatePdf()` call WHEN invoked THEN it returns the same `{filename, html, mimeType}` shape (no regression)
- [x] Implement
- [x] Test

### Task 4: Pre-send validation (KvK / BTW-VIES / Peppol participant)
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-003`
- **files**: `lib/Service/EInvoice/EInvoiceValidationService.php`
- **acceptance_criteria**:
  - GIVEN a malformed BTW-nummer WHEN send is triggered THEN validation blocks and no event is emitted
  - GIVEN a Peppol lookup returning `{exists:false}` WHEN validated THEN the PDF+email fallback is offered and `deliveryStatus` stays `not-sent`
  - GIVEN a VIES outage WHEN a valid BTW-nummer is checked THEN a non-blocking warning is returned
- [x] Implement
- [x] Test

### Task 5: Generalise the Peppol transmission port + Log adapter
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-004`
- **files**: `lib/Service/Peppol/PeppolTransmissionPortInterface.php`, `lib/Service/Peppol/LogPeppolTransmissionAdapter.php`, `lib/Service/PurchaseOrder/PeppolTransmissionAdapterInterface.php`
- **acceptance_criteria**:
  - GIVEN the Log adapter WHEN `submit(participantId,'ubl-invoice-2.1',uri)` is called THEN it returns a `urn:uuid:` id and logs one redacted line
  - GIVEN the existing PO transmission flow WHEN a PO is sent after the refactor THEN it behaves identically (alias retained)
- [x] Implement
- [x] Test

### Task 6: EInvoiceService orchestrator + controller + outbound event
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-005`
- **files**: `lib/Service/EInvoice/EInvoiceService.php`, `lib/Controller/ARInvoiceEInvoiceController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a validated issued ARInvoice WHEN Send e-invoice runs THEN exactly one `nl.conduction.peppol.outbound.requested` is emitted and `deliveryStatus` → `queued`
  - GIVEN the send action WHEN called on another administration's invoice THEN a per-administration guard rejects it (no IDOR)
  - GIVEN a B2G debtor WHEN sent THEN `transmissionId` + `payloadFileUri` are persisted (REQ-EINV-006)
- [x] Implement
- [x] Test

### Task 7: Delivery-status listener advances the sub-lifecycle
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-005`
- **files**: `lib/Listener/PeppolDeliveryStatusListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `deliveryStatus: sent` WHEN a `delivery.status` event with `status: delivered` arrives THEN `deliveryStatus` → `delivered` and `detail` persisted
  - GIVEN an in-flight invoice WHEN `status: rejected` arrives THEN `deliveryStatus` → `rejected`, detail persisted, operator notified
- [x] Implement
- [x] Test

### Task 8: AR invoice detail — Send action + status indicator
- **spec_ref**: `openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-007`
- **files**: `src/views/ARInvoiceDetail.vue`, `src/components/DeliveryStatusChip.vue`
- **acceptance_criteria**:
  - GIVEN an issued ARInvoice with valid identifiers WHEN the operator clicks Send e-invoice THEN the status indicator shows `queued` and a success toast appears
  - GIVEN a draft ARInvoice WHEN viewed THEN the Send action is disabled
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off and `openspec validate` passes
- [x] PO transmission path re-tested (no regression) — `PurchaseOrderPeppolTransmissionTest` + `PurchaseOrderServiceTest` + `PurchaseOrderIntegrationTest` green after the port generalisation. NLCIUS golden-file Schematron: NOT run (see Deviations)
- [ ] Manual browser test of Send e-invoice + delivery-status indicator (see Deviations)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New `send-einvoice` endpoint covered by a Newman/Postman test
- UI changes covered by Playwright browser tests (`ARInvoiceDetail*.spec.js`)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` (user-facing e-invoicing flow, ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings added; keys English. NL: "E-factuur versturen" (Send e-invoice), "Bezorgstatus" (Delivery status), "In wachtrij" (queued), "Verzonden" (sent), "Afgeleverd" (delivered), "Afgewezen" (rejected), "Mislukt" (failed)
- `openspec validate` passes

## Deviations

1. **Task 1 — no second `x-openregister-lifecycle` block.** OpenRegister supports
   exactly ONE `x-openregister-lifecycle` annotation per schema (single top-level
   key, deep-merged across `register.d` fragments with scalar-overwrite semantics).
   `ARInvoice` already declares its canonical lifecycle (`field: lifecycleState`)
   in `add-shillinq-bookkeeping-compliance.json`; a second full block keyed on
   `deliveryStatus` would deep-merge into the SAME object and silently clobber
   `field`/`initialState`. The delivery sub-lifecycle is therefore declared as
   data under the documentation-only `x-shillinq-delivery-substates` extension
   (ignored by OR's validator/engine — no merge-collision risk) and enforced
   imperatively by `EInvoiceService` (not-sent → queued) and
   `PeppolDeliveryStatusListener` (all downstream transitions, whitelist-guarded).
2. **Task 1 — seed location.** There is no `lib/Settings/register.d/_registers.json`
   in this repo; per the established fragment pattern the seed objects
   (3 ARInvoices + 3 CustomerMasters) live in the fragment's own
   `components.objects` array.
3. **Task 3 — PDF/A-3 is best-effort, not veraPDF-certified.** The hybrid writer
   is dependency-free (design.md rejects a heavy PDF/A-3 toolchain as
   gold-plating): it emits the ISO 32000 `/AF` + `/EmbeddedFiles` +
   `AFRelationship=Alternative` machinery and an XMP `pdfaid:part=3` /
   `pdfaid:conformance=B` metadata stream, but embeds no ICC output-intent
   profile. The UBL XML remains the compliance artefact and travels
   independently of the PDF. Embedded filename resolved to `factur-x.xml`
   (design.md open question) per the cross-industry Factur-X/ZUGFeRD convention.
4. **Verification — EN 16931 Schematron golden-file check not run.** No
   Schematron toolchain (schxslt/saxon) is available in this environment;
   structural conformance (CustomizationID, ProfileID, LegalMonetaryTotal,
   TaxTotal, InvoiceLine round-trip against `SupplierInvoiceService::parseUblInvoice`)
   is covered by `ArInvoiceUblMapperTest` instead.
5. **Verification — manual browser test not performed.** The change was built in
   an isolated worktree; deploying to the shared dev instance mid-flight is
   banned (house rule). UI coverage: `tests/vitest/arEInvoiceActions.spec.js`
   (pure logic) + `tests/e2e/ar-invoice-einvoice.spec.ts` (Playwright shell-smoke,
   authored for the CI live-instance run; not executed locally).
6. **Task 8 — no `src/views/ARInvoiceDetail.vue`.** `ARInvoiceDetail` is a
   manifest-driven `type: detail` page, not a Vue view file; the Send action +
   status chip mount via the page's `actionsComponent`
   (`src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json` →
   `AREInvoiceActions` in `src/registry.js`, ADR-036).
7. **Newman item not executed locally** (needs a live instance); the two new
   requests are deterministic 400/404 contract checks that do not depend on
   seeded state.
