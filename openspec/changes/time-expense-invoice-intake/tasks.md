# Tasks: time-expense-invoice-intake

## Implementation Tasks

### Task 1: Register fragment — TimeIntakeBatch schema + UrenRegistratie provenance fields
- **spec_ref**: `openspec/changes/time-expense-invoice-intake/specs/time-expense-invoice-intake/spec.md#requirement-idempotent-intake-by-batch-id`
- **files**: `lib/Settings/register.d/time-expense-invoice-intake.json`
- **acceptance_criteria**:
  - GIVEN the fragment is imported WHEN OpenRegister loads the shillinq register THEN a `TimeIntakeBatch` schema exists with `administrationId`, `batchId`, `sourceApp`, `organisationRef`, `projectId`, `currency`, `periodStart`, `periodEnd`, `entryCount`, `status` (received/invoiced/failed), `invoiceId`, `receivedAt`
  - GIVEN the fragment is imported THEN `UrenRegistratie` materialises three added fields `externalId`, `sourceApp`, `sourceBatchId` without editing `shillinq_register.json` (ADR-037)
- [ ] Implement
- [ ] Test

### Task 2: Seed data for TimeIntakeBatch + provenance fields
- **spec_ref**: `openspec/changes/time-expense-invoice-intake/specs/time-expense-invoice-intake/spec.md#acceptance-criteria`
- **files**: `openspec/changes/time-expense-invoice-intake/_registers.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeds load THEN 3 `TimeIntakeBatch` objects exist (per design.md Seed Data), one demonstrating an idempotent replay pointing at the same invoice
  - GIVEN the seed `UrenRegistratie` rows THEN they carry `externalId`/`sourceApp`/`sourceBatchId` showing the pipelinq provenance chain
- [ ] Implement
- [ ] Test

### Task 3: TimeIntakeService — validate, materialise, idempotency, delegate
- **spec_ref**: `openspec/changes/time-expense-invoice-intake/specs/time-expense-invoice-intake/spec.md#requirement-draft-a-single-t-m-invoice-from-an-approved-batch`
- **files**: `lib/Service/TimeIntakeService.php`
- **acceptance_criteria**:
  - GIVEN a valid T&M batch WHEN `ingest()` runs THEN each entry is materialised as a `UrenRegistratie` row (`externalId`/`sourceApp`/`sourceBatchId`) and exactly one draft `BillableInvoice` is created via the existing `InvoiceGenerationService`
  - GIVEN a known `batchId` THEN `ingest()` short-circuits and returns the existing invoice with `duplicated: true` (no duplicate objects)
  - GIVEN a `batchId` reused with a different payload THEN `ingest()` signals conflict (409)
  - GIVEN an entry whose `externalId` already exists for the administration THEN `ingest()` rejects the batch (422) naming the `externalId`
  - GIVEN `billingModel != t_and_m` or empty entries THEN `ingest()` rejects (422 / 400) and creates nothing
- [ ] Implement
- [ ] Test

### Task 4: BillingIntakeController + route
- **spec_ref**: `openspec/changes/time-expense-invoice-intake/specs/time-expense-invoice-intake/spec.md#requirement-authenticated-billing-intake-endpoint`
- **files**: `lib/Controller/BillingIntakeController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated user WHEN they POST `/apps/shillinq/api/billing/time-intake` THEN the controller resolves the administration server-side, ignores any client `administrationId`, calls `TimeIntakeService::ingest()`, and returns `{invoiceId, invoiceNumber?, status:"draft", lines:n, duplicated:bool}`
  - GIVEN no authenticated user THEN the endpoint returns `401`; the route declares `#[NoAdminRequired]` (no `#[PublicPage]`) and is reachable
- [ ] Implement
- [ ] Test

## Verification
- [ ] `openspec validate` passes and all tasks checked off
- [ ] Newman covers 200-new / 200-replay / 401 / 409 / 422 / 400 paths against acceptance criteria
- [ ] Contract in `contract.md` verified unchanged (pipelinq consumer alignment)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/` — `TimeIntakeService`, idempotency + dedup branches)
- New/changed API endpoint covered by Newman/Postman tests
- No UI changes — draft invoices appear in the existing invoice list UI (no Playwright work)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` (intake endpoint + pipelinq handoff) if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings added for any operator-facing error/notification text (ADR-007)
- `openspec validate` passes
