# Tasks: platform-administration-import

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Lifecycle wiring

- [x] 1.1 `ImportBatchAction` for parse and validate with follow-up states (REQ-AIW-001). Verify: PHPUnit red first with the real pipeline on a fixture XAF 3.2 file; the patched batch validated against the real fragment.
- [x] 1.2 Dry-run, post (idempotent) and reverse actions (REQ-AIW-001, 003). Verify: PHPUnit; a second post of the same key is refused; the posted opening entry balances. (2026-10-02: built in two PRs; post and reverse write payloads validated with Opis against the real JournalEntry and CustomerMaster fragments, see design D5.)
- [x] 1.3 Unreadable source file refuses parse (REQ-AIW-002). Verify: PHPUnit.

## 2. Wizard

- [x] 2.1 `ImportWizard.vue` with the six steps; manifest `x-deferred` removed (REQ-AIW-002). Verify: vitest walks the steps with a stubbed store; `npm run check:manifest`; nav reachability.

## 3. Strings and end to end

- [x] 3.1 Dutch and English strings. Verify: `npm run test:l10n`.
- [ ] 3.2 Playwright `tests/e2e/platform-administration-import.spec.ts`: import a fixture Snelstart XAF to a new administration. Verify: passes locally. (2026-10-02: written, not run: needs the live instance.)

Quality reminders (not tracked as tasks): `composer check:strict` once before push.
