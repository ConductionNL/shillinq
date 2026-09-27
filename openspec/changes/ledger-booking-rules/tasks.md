# Tasks: ledger-booking-rules

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Schema

- [ ] 1.1 Add a `register.d` fragment declaring `Account.controlAccountFor` and the `PostingRestriction` schema with its lifecycle, and point the two mappings at `controlAccountFor` (REQ-LBR-001, REQ-LBR-004). Verify: `npm run check:registers`, `npm run check:seeds`.
- [ ] 1.2 Repair step setting `controlAccountFor` from the RGS control codes on existing accounts, logging each change (design Migration Plan). Verify: PHPUnit on a seeded administration.

## 2. Guards

- [ ] 2.1 Add `lib/Lifecycle/PostingRestrictionGuard.php` with the control check (sub-ledger and humaniq payroll exemptions) and the restriction check (REQ-LBR-002, REQ-LBR-005). Verify: PHPUnit per scenario in both spec files.
- [ ] 2.2 Delegate to it from `JournalEntryGuard::canPost` and `RuleComplianceGuard::validateTransaction`, with the refusal message carried to the transition error (REQ-LBR-002, REQ-LBR-005). Verify: PHPUnit that both guards refuse and that a materialised posting passes.

## 3. Pages

- [ ] 3.1 Posting restrictions settings page in `src/manifest.d/` with its menu entry under settings (REQ-LBR-004). Verify: `npm run check:manifest`, nav reachability check.
- [ ] 3.2 Guidance under the account on the JournalDetail and GeneralLedgerDetail line editors and as option secondary text; update `Account.description`'s schema text (REQ-LBR-003). Verify: vitest for the line editor, screenshot in the PR.

## 4. End to end

- [ ] 4.1 Playwright `tests/e2e/ledger-booking-rules.spec.ts`: refused memorial entry on 1300, refused blocked combination, allowed combination posts. Verify: passes locally.
- [ ] 4.2 Dutch and English strings for the refusals and the settings page. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push; `@spec` tags on new methods.
