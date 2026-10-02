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


### D5. The import posts its opening entry (2 Oct 2026, lane 21)

Read at `feat/platform-administration-import` @3006765c1. The JournalEntry lifecycle has `postDirect` (draft to posted, guarded by `JournalEntryGuard::canPost`, materialising the GLTransaction). The spec says posting writes the opening entry and reversing reverses it, and a draft left for the bookkeeper would put nothing in the ledger. So `ImportPosting` saves the entry as a draft and runs `postDirect` through `ObjectTransitionRunner`: the balance check, the blocked combinations and the GLTransaction all apply.

One rule stood in the way: an opening balance carries the balances of the control accounts (debtors, creditors, VAT), and `PostingRestrictionGuard` refuses a person's posting on those. The entry therefore carries `sourceApp: import`, and `import` may post on every control role. Like `bank` and `humaniq`, `sourceApp` is a plain field, so this widens what a hand-made entry naming it could do; that is the existing design of the sub-ledger rule, not new to the import.

Order and refusal: customers are written first, then the opening entry. A write the register or the books refuse removes what the post wrote and comes back as a `posting-refused` error finding on `postingReport`, with the batch in `posting_failed`; before this change each refusal was a warning and the batch read `posted` with nothing written. The reversing entry is the opening entry with its sides swapped, dated on the migration date, posted the same way; a refused reversal refuses the transition and the batch stays posted.

Left out, named on `postingReport`: open items (no path stages them yet, so a batch holding them is refused before any write), suppliers (there is no supplier record), customers without an email address (`CustomerMaster.email` is required), and Nextcloud contacts. An existing customer with the same KvK number, VAT id or email is linked, not written again, and is never removed by a reversal.
