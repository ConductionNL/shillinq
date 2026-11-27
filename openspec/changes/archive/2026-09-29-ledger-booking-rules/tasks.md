# Tasks: ledger-booking-rules

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Schema

- [x] 1.1 (built: `register.d/ledger-booking-rules.json`, plus `JournalEntry.sourceApp` and a cost centre and project per journal line, and `controlAccountFor` on the RGS MKB seed's 1100, 2000, 1230 and 2110) Add a `register.d` fragment declaring `Account.controlAccountFor` and the `PostingRestriction` schema with its lifecycle, and point the two mappings at `controlAccountFor` (REQ-LBR-001, REQ-LBR-004). Verify: `npm run check:registers`, `npm run check:seeds`.
- [x] 1.2 (amended at build: the seed carries no `rgsCode`, so `BackfillControlAccountRoles` matches the four account numbers the sub-ledgers book to, only where the account still has the seed's name) Repair step setting `controlAccountFor` on existing accounts, logging each change (design Migration Plan). Verify: PHPUnit on a seeded administration.

## 2. Guards

- [x] 2.1 (amended at build: the bank ledger may post its VAT line, `ManualMatchService` marks its journal `sourceApp: bank`; humaniq does not yet set `sourceApp`, so no payroll account gets a role by default) Add `lib/Lifecycle/PostingRestrictionGuard.php` with the control check (sub-ledger and humaniq payroll exemptions) and the restriction check (REQ-LBR-002, REQ-LBR-005). Verify: PHPUnit per scenario in both spec files.
- [x] 2.2 (amended at build: the refusal is a `PostingRefusedException`, which `RegisterRequiresGuardAdapter` shows as the transition's message; a GLTransaction with `journalEntryId` or `journalCode` is a sub-ledger's and is not checked) Delegate to it from `JournalEntryGuard::canPost` and `RuleComplianceGuard::validateTransaction`, with the refusal message carried to the transition error (REQ-LBR-002, REQ-LBR-005). Verify: PHPUnit that both guards refuse and that a materialised posting passes.

## 3. Pages

- [x] 3.1 Posting restrictions settings page in `src/manifest.d/` with its menu entry under settings (REQ-LBR-004). Verify: `npm run check:manifest`, nav reachability check.
- [x] 3.2 (amended at build: the two pages have no line editor, so a lines panel, `LedgerLinesGuidancePanel`, shows each line's account with its guidance under it; there are no account options to decorate) Guidance under the account on the JournalDetail and GeneralLedgerDetail lines; update `Account.description`'s schema text (REQ-LBR-003). Verify: vitest for the line editor, screenshot in the PR.

## 4. End to end

- [x] 4.1 (amended at build: the refusals run in the post guards and are proven through the real adapter in `PostingGuardsThroughAdapterTest`; the pages are held to the registry by `tests/vitest/ledgerBookingRules.spec.js`. A live check recipe is in the PR.) Playwright `tests/e2e/ledger-booking-rules.spec.ts`: refused memorial entry on 1300, refused blocked combination, allowed combination posts. Verify: passes locally.
- [x] 4.2 Dutch and English strings for the refusals and the settings page. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push; `@spec` tags on new methods.
