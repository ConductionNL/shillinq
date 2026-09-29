# Design: platform-administration-import

Read at shillinq `feat/ledger-booking-rules` @6ee1421d9 on 2026-09-29.

## Context

- The six-stage flow is already described in the manifest's `x-deferred-steps`; this change builds exactly those steps.
- `ImportPipelineService` methods take the batch array and return reports; they do not write the batch.

## Decisions

### D1. Actions, not a controller per step

`lib/Lifecycle/Action/ImportBatchAction.php` implements the declared actions of `parse`, `validate`, `dryRun`, `post` and `reverse`. Each calls the pipeline method and patches the batch (`patchObject`) with its report and the follow-up state (`staged`, `validation_failed`, `posted`, `posting_failed`). The existing guards stay. `post` refuses a batch whose `idempotencyKey` was posted before.

### D2. Files are linked, not copied

`sourceFiles` holds Nextcloud file ids chosen with the Files picker; `parse` reads them as the batch owner. A file the owner cannot read refuses `parse` with its name.

### D3. The wizard component

`src/views/import/ImportWizard.vue` registered as the page's `component`; steps from the manifest's step list; the mapping step embeds the `ImportMappings` list filtered to the batch; the validation step lists findings and disables "Next" while an error finding remains; the dry-run step shows the would-be opening balance and open items.

### D4. Permissions

Import is admin-only (`#[AuthorizedAdminSetting]` on any endpoint added); the batch's `administrationId` is checked in the action.

