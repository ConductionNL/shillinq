# Tasks: ledger-journal-line-dimensions

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

- [x] 1.1 Add `costCenterCode`, `costCarrierCode` and `projectCode` to the `JournalEntry.lines` items and bump the schema version (REQ-LJD-001). Verify: PHPUnit on the register import; `npm run check:schema-l10n` exit 0.
- [x] 1.2 Copy the three codes in the `JournalEntry` mapper of `materialise-gl-transaction` (REQ-LJD-001). Verify: PHPUnit on the mapper with a line carrying all three codes and a line carrying none.
- [x] 1.3 The code check in `JournalEntryGuard::canPost()` with one dimension read per entry (REQ-LJD-002). Verify: PHPUnit `JournalEntryGuardTest::testUnknownCostCentreIsRefused`, `testBlockedProjectIsRefused`, `testLinesWithoutCodesStillPost`.
- [ ] 1.4 Line columns and pickers on `JournalDetail`. Verify: Playwright `tests/e2e/journal-line-dimensions.spec.ts` "a bookkeeper books a line on cost centre CC-100".
- [ ] 1.5 Live check with humaniq: post a payroll run with a 60/40 allocation over CC-100 and CC-200. Verify: the posted GL lines carry the two cost centres and add up to the run's gross.
- [ ] 1.6 Run `openspec validate ledger-journal-line-dimensions --strict`.

## Build notes

- 1.1: the three properties are on `JournalEntry.lines` items in `add-shillinq-bookkeeping-foundation.json`, JournalEntry 0.2.0; the app version moved and `tests/fixtures/register-versions.lock.json` records it.
- 1.2: the posting handler already copied `costCenterCode` and `projectCode` (ledger-booking-rules); it now copies `costCarrierCode` too, through one `LINE_DIMENSIONS` list.
- 1.3: `LineDimensionCheck` runs after the booking rules in `JournalEntryGuard::canPost()`, reading the administration's active dimensions once per entry and only when a line carries a code.
- 1.4 is left: `JournalDetail` shows `lines` as one generic array field, so per-line columns with a dimension picker need a line editor first; the Playwright spec needs a live instance. 1.5 needs humaniq live, 1.6 the openspec CLI.

