# Tasks: ledger-sealed-entries

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Seal

- [ ] 1.1 Add the four seal fields to `GLTransaction` in a `register.d` fragment, read-only in the page config (REQ-LSE-001). Verify: `npm run check:registers`.
- [ ] 1.2 Add the canonicaliser and `LedgerSealService::seal()` (REQ-LSE-001, REQ-LSE-003). Verify: PHPUnit with fixed expected hashes, including a reversed transaction.
- [ ] 1.3 Add `GLTransactionSealListener` with the per-administration lock and register it in `Application.php` (REQ-LSE-001). Verify: PHPUnit for two concurrent seals and for a lock failure failing the post.

## 2. Check

- [ ] 2.1 `LedgerSealService::verify()`, the `GeneralLedger` header action and `occ shillinq:ledger:verify-seal` (REQ-LSE-002). Verify: PHPUnit for intact, changed content, broken link and skipped sequence.
- [ ] 2.2 `occ shillinq:ledger:seal-existing` genesis pass that refuses a sealed administration (design Migration Plan). Verify: PHPUnit.

## 3. End to end and strings

- [ ] 3.1 Playwright `tests/e2e/ledger-sealed-entries.spec.ts`: post, check intact (REQ-LSE-001, REQ-LSE-002). Verify: passes locally.
- [ ] 3.2 Dutch and English strings for the action and results. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push; the check endpoint is admin or controller only (ADR-005).
