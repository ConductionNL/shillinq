# Tasks: banking-manual-match

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Bulk actions

- [x] 1.1 Change the three `UnmatchedItems` bulk action URLs in `src/manifest.json` to the `/apps/shillinq/api/` route with the reconciliation id resolved from the selection, or move `reconId` into the body if the mass action bar offers no object context (REQ-BMM-004). Verify: a live bulk classify on a local instance returns 200 and the network call is pasted in the PR body.

## 2. Settlement

- [x] 2.1 Add `lib/Listener/ReconciliationMatchSettlementListener.php` on the `confirm` transition, calling `mark-paid`, `pay-overdue`, `matchFull` or `matchPartial` (REQ-BMM-003). Verify: PHPUnit with the real `ObjectTransitionedEvent` class for each transition, a non-payable invoice and a repeated event.

## 3. Match service

- [x] 3.1 Add `lib/Service/Bank/ManualMatchService.php` for invoice targets: over-selection refusal, partial with remainder, confirmed match, line state (REQ-BMM-001). Verify: PHPUnit for full, partial and refused.
- [x] 3.2 Add the ledger-account branch: a balanced `JournalEntry` with optional VAT split, posted through `postDirect`, then the match (REQ-BMM-002). Verify: PHPUnit for a booking with and without VAT; one live post on a local instance with `ledger-posting-path` merged.
- [x] 3.3 Add `POST /api/v1/bank-lines/{lineId}/match` on `ManualMatchController` with `#[NoAdminRequired]` and an administration check on the line (REQ-BMM-001, REQ-BMM-002). Verify: hydra gates route-auth and no-admin-idor pass.

## 4. Screen

- [x] 4.1 Add `src/modals/BankLineMatchModal.vue` with the invoice and ledger tabs, opened from a line action on `BankReconciliationDetail` and a row action on `UnmatchedItems` (REQ-BMM-001, REQ-BMM-002). Verify: hydra gates modal-isolation and nc-input-labels pass.
- [x] 4.2 Playwright `tests/e2e/banking-manual-match.spec.ts`: match DV-7781, book bank costs, refuse an over-selection (REQ-BMM-001, REQ-BMM-002, REQ-BMM-003). Verify: the spec passes against a local instance.

## 5. Docs

- [x] 5.1 User guide section on matching by hand, and a release note naming the settlement listener and the partial limit on sales invoices. Verify: the page builds in `docs/` and the note is in the PR body.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `@spec openspec/changes/banking-manual-match/tasks.md#task-N` on every new method, Dutch and English strings for every new label and message.
