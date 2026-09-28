# Tasks: ledger-journal-line-dimensions

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

- [ ] 1.1 Add `costCenterCode`, `costCarrierCode` and `projectCode` to the `JournalEntry.lines` items and bump the schema version (REQ-LJD-001). Verify: PHPUnit on the register import; `npm run check:schema-l10n` exit 0.
- [ ] 1.2 Copy the three codes in the `JournalEntry` mapper of `materialise-gl-transaction` (REQ-LJD-001). Verify: PHPUnit on the mapper with a line carrying all three codes and a line carrying none.
- [ ] 1.3 The code check in `JournalEntryGuard::canPost()` with one dimension read per entry (REQ-LJD-002). Verify: PHPUnit `JournalEntryGuardTest::testUnknownCostCentreIsRefused`, `testBlockedProjectIsRefused`, `testLinesWithoutCodesStillPost`.
- [ ] 1.4 Line columns and pickers on `JournalDetail`. Verify: Playwright `tests/e2e/journal-line-dimensions.spec.ts` "a bookkeeper books a line on cost centre CC-100".
- [ ] 1.5 Live check with humaniq: post a payroll run with a 60/40 allocation over CC-100 and CC-200. Verify: the posted GL lines carry the two cost centres and add up to the run's gross.
- [ ] 1.6 Run `openspec validate ledger-journal-line-dimensions --strict`.
