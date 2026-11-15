# Migration: extracurricular-fee-to-shillinq

## Current State

- `PaymentRequest` 0.3.0 in `lib/Settings/register.d/ar-invoice-payment-links.json`:
  `subject {type, register, schema, id}`, `requestType` enum `leges`, `dwangsom`,
  `deposit`, `other`. No `beneficiary`, `voluntary`, `raiseBatchId`, `settledAt` or
  `settledVia`.
- `ARInvoice`, merged from 18 fragments, effective `version` 0.6.0 (the last
  fragment in sort order that sets it is `checks-vat.json`). No `contribution` group.
- No Nextcloud database table is involved: both schemas are OpenRegister objects.

## Target State

- `PaymentRequest` 0.4.0 with the seven additions of design.md D2. Every existing
  row stays valid: all additions are optional, and `contribution` is a new enum value.
- `ARInvoice` effective `version` 0.14.0 with the nullable `contribution` group,
  declared in the new fragment `lib/Settings/register.d/school-contributions.json`,
  which sorts after every other `ARInvoice` fragment.

## Migration Class

```
Version: none
File: none
Key operations:
- none. The register is re-imported by the existing InitializeSettings repair step
  through ConfigurationService::importFromApp(). The fragment signature folded into
  the register version changes with the new fragment, which triggers the import, and
  the raised schema versions let OpenRegister update the two schemas in place.
```

## Migration Steps

1. The app update runs `InitializeSettings`, which merges the fragments and imports
   the register.
2. OpenRegister updates `PaymentRequest` (0.3.0 to 0.4.0) and `ARInvoice` (0.6.0 to
   0.14.0) because their versions rose.
3. The seed rows of `school-contributions.json` are imported (four demo objects).

## Data Impact

No existing row is rewritten. Existing object requests carry no `beneficiary`, so
the uniqueness check treats them exactly as before. Runs on live data.

## Rollback Procedure

Revert the PR and re-run the repair step. The added properties stay on the schema
rows until the next import with a higher version, and the rows written with them stay
readable; nothing depends on removing them.

## Validation

- `occ maintenance:repair` logs the register import without errors.
- The `PaymentRequest` schema row shows version 0.4.0 and a `beneficiary` property.
- The `ARInvoice` schema row shows version 0.14.0 and a `contribution` property.
- `tests/Unit/Service/SchoolContributionsFragmentTest.php` asserts the merged shape.
