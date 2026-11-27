# Tasks: arinvoice-field-backfill-and-bt10

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

Every task starts with a test that fails first.

### Task 1: Back-fill generated invoices from their recurring profile
- **spec_ref**: `specs/recurring-invoicing/spec.md` (REQ-RIN-011)
- **files**: `lib/Repair/BackfillArInvoiceProvenance.php`, `lib/Repair/Support/ArInvoiceProvenance.php`, `appinfo/info.xml`, `tests/Unit/Repair/BackfillArInvoiceProvenanceTest.php`
- **acceptance_criteria**:
  - one fitting profile fills profile, period and line accounts; no fit or two fits leave the invoice alone; never overwrites; a rerun saves nothing
- [x] Implement and test

### Task 2: Back-fill quick drafts from their create audit entry
- **spec_ref**: `specs/shillinq-invoice-quick-draft/spec.md` (REQ-IQD-008)
- **files**: `lib/Repair/Support/ArInvoiceProvenance.php`, `tests/Unit/Service/Support/DuckObjectServiceAdapter.php`, `tests/Unit/Repair/BackfillArInvoiceProvenanceTest.php`
- [x] Implement and test

### Task 3: customerReference is BT-10 in the e-invoice
- **spec_ref**: `specs/bookkeeping-einvoicing-ubl-peppol/spec.md` (REQ-EINV-010)
- **files**: `lib/Service/EInvoice/ArInvoiceUblMapper.php`, `tests/Unit/Service/EInvoice/ArInvoiceUblMapperTest.php`
- [x] Implement and test

### Task 4: Move the letter and record composition out of DunningRunService
- **spec_ref**: `specs/bookkeeping-credit-control-dunning/spec.md` (REQ-CCD-017)
- **files**: `lib/Service/Dunning/DunningLetterComposer.php`, `lib/Service/DunningRunService.php`, `tests/Unit/Service/Dunning/DunningLetterComposerTest.php`
- **acceptance_criteria**:
  - `DunningRunServiceTest` unchanged and green; the class is under 1,300 lines with headroom
- [x] Implement and test
