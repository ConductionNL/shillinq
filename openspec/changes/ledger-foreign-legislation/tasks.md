# Tasks: ledger-foreign-legislation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

## 1. Jurisdiction

- [ ] 1.1 Add `Administration.jurisdiction` with the lock after posting, and a repair step setting NL on existing administrations (REQ-LFL-001). Verify: `npm run check:registers`; PHPUnit for the lock.
- [ ] 1.2 Read it in `RuleComplianceGuard::context()` (REQ-LFL-002). Verify: PHPUnit that a BE invoice runs BE rules and no NL-only rule.

## 2. Pack

- [ ] 2.1 Define the pack format and add `lib/Settings/seeds/legislation/be/` with chart, rates and pack files (REQ-LFL-003). Verify: `npm run check:seeds`; a schema check on the pack files.
- [ ] 2.2 Seed from a pack in `SettingsService` and offer it in the setup wizard by jurisdiction (REQ-LFL-001, REQ-LFL-003). Verify: PHPUnit seeding a BE administration.

## 3. Dutch-only outputs

- [ ] 3.1 Refuse non-NL administrations in the Dutch VAT return, SBR and ICP generators (REQ-LFL-004). Verify: one PHPUnit case per generator.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/ledger-foreign-legislation.spec.ts`: create a BE administration, see its chart and rates. Verify: passes locally.
- [ ] 4.2 Dutch and English strings, and the pack's account names in Dutch and French where the MAR gives both. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.
- [ ] 4.3 User docs page on foreign administrations. Verify: `docs/` build.

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
