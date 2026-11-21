---
kind: code
depends_on: [ledger-posting-path]
---

# Proposal: ledger-journal-line-dimensions

## Why

A controller at a municipality wants the wage costs of each department and each subsidised project in the ledger. Humaniq's open change `payroll-cost-allocation` (humaniq development `50acc7b`) splits the payroll journal's debit lines per cost centre and project, and its "Cross-app dependencies" section asks shillinq for the half that makes those codes land:

> shillinq: accept `costCenterCode` and `projectCode` on the lines of the draft journal entry humaniq creates, and carry them onto the ledger lines it materialises on posting. shillinq's ledger line (`GLLine`) already has `costCenterCode` and `projectCode` (`bookkeeping-cost-centers-dimensions`); the journal entry humaniq writes must pass them through. humaniq's cost-centre codes must match shillinq's cost-centre dimension codes.

Its design D4: "`buildLines()` groups the run's `WageCostAllocation` rows by `(costCenter, projectId)` and writes one gross debit line and one employer-charges debit line per group, each with `costCenterCode` and `projectCode`." Its risk section counts on shillinq refusing unknown codes: "the journal is created as a draft in shillinq and its own posting validation refuses unknown codes, as it does for accounts."

Today the codes would be lost. `JournalEntry.lines` declares `accountNumber`, `side`, `amount` and `description` only, and `JournalEntryGuard::canPost` checks balance, not codes. The same gap sits under shillinq's own open change `ledger-booking-rules`, whose REQ-LBR-005 refuses "a journal entry with a line on 4600 for cost centre KP-100 and project P-2026-014": a journal entry line cannot carry that cost centre yet.

The humaniq rows behind it are `pay-cost-allocation` and `plt-accounting-link` in humaniq's `openspec/parity/capabilities.json`. This half was handed to shillinq after shillinq's own OpenSpec-pass lane had finished (owner-moves pass, 28 September 2026). Decision: build, because another product's merged change depends on it.

## What changes

- A journal entry line can carry `costCenterCode`, `costCarrierCode` and `projectCode`.
- Posting a journal entry carries them onto the ledger lines it makes.
- Posting refuses a line whose code names no active dimension of the right kind in the administration, and says which line and which code.
- The journal entry page shows and edits the three codes per line.

## Scope

### In scope

- The three line properties, the mapping in the posting handler and the code check in the post guard.
- The line editor on `JournalDetail`.

### Out of scope

- `dimensions` (custom dimensions) on journal entry lines.
- Required dimensions per account; `ledger-booking-rules` lists that as a non-goal too.

## Impact

- `lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json`: `JournalEntry.lines` items.
- The `materialise-gl-transaction` handler of `ledger-posting-path`: the `JournalEntry` mapper.
- `lib/Lifecycle/JournalEntryGuard.php`: the code check in `canPost()`.
- `src/manifest.json`: `JournalDetail` line columns.

## Rows and halves

| requesting repo | requesting change | half |
|---|---|---|
| humaniq | payroll-cost-allocation | journal-line cost codes |
