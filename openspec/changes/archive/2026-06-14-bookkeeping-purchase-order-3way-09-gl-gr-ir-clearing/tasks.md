# Tasks — Member 09: GR/IR Clearing & GL Posting (code)

## GRIRClearingService

- [x] Implement `createGRIRPosting()` — on GRN accept, materialise DR [PO-line gl_account] / CR [GR/IR clearing account from profile]
- [x] Implement `settleGRIRPosting()` — on invoice approval, materialise DR [GR/IR clearing] / CR [AP liability + VAT payable]
- [x] Preserve cost_center + project_code from the PO line on both postings; link to the ThreeWayMatch record

## GL account configuration

- [x] Define GR/IR clearing account code (e.g., 2910) at administration level
- [x] Make GR/IR clearing account configurable per ToleranceProfile (optional override)

## Tests

- [x] Unit tests: GR/IR posting creation (balanced entries, proper GL codes), settlement (clearing → AP + VAT), cost-center preservation
- [x] Integration test: GRN accept → verify GR/IR clearing posting; invoice approval → verify settlement posting
- [x] Integration test: GR/IR saldo reconciliation sums to zero at period-end
