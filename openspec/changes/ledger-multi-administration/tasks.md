# Tasks: ledger-multi-administration

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Active administration

- [ ] 1.1 Store the choice in `switch()` and read it in `buildContext()` with the membership fallback (REQ-LMA-001). Verify: PHPUnit for stored, stale and absent preference.

## 2. Scoping

- [ ] 2.1 Generate the list of index pages whose schema declares `administrationId`, and add the scoped wrapper `AdministrationScopedIndex.vue` for them (REQ-LMA-002). Verify: vitest for the wrapper; the generated list in the PR body.
- [ ] 2.2 Raise the `@context.<key>` sentinel request on nextcloud-vue with this change as the consumer, and switch the pages to it when it ships (REQ-LMA-002). Verify: issue link in the PR body.

## 3. Templates

- [ ] 3.1 Add `AdministrationTemplate` and the `followsTemplateId`, `followedTemplateVersion` and `deviatesFromTemplate` fields in `register.d/bookkeeping-multi-administratie.json` (REQ-LMA-003, REQ-LMA-005). Verify: `npm run check:registers`, `npm run check:seeds`.
- [ ] 3.2 Save as template action on the administration detail page (REQ-LMA-003). Verify: PHPUnit that no postings, relations or balances are copied.
- [ ] 3.3 Office templates in the setup wizard template step and in `SettingsService` seeding (REQ-LMA-004). Verify: PHPUnit seeding from an office template.
- [ ] 3.4 Follower sync job in `lib/BackgroundJob/` queued on a template version change, recording on `AdministrationMigration` (REQ-LMA-005). Verify: PHPUnit for add, update and skip; `npm run check:job-registration`.

## 4. End to end and strings

- [ ] 4.1 Playwright `tests/e2e/ledger-multi-administration.spec.ts`: switch, reload, scoped list, start from an office template. Verify: passes locally.
- [ ] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push; IDOR check on the template endpoints (ADR-005).
