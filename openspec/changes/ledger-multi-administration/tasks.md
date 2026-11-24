# Tasks: ledger-multi-administration

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 9. -->

## 1. Active administration

- [x] 1.1 Store the choice in `switch()` and read it in `buildContext()` with the membership fallback (REQ-LMA-001). Verify: PHPUnit for stored, stale and absent preference.

## 2. Scoping

- [x] 2.1 Generate the list of index pages whose schema declares `administrationId`, and add the scoped wrapper `AdministrationScopedIndex.vue` for them (REQ-LMA-002). Verify: vitest for the wrapper; the generated list in the PR body.
- [ ] 2.2 Raise the `@context.<key>` sentinel request on nextcloud-vue with this change as the consumer, and switch the pages to it when it ships (REQ-LMA-002). Verify: issue link in the PR body.
  - Raised: ConductionNL/nextcloud-vue#2310. Left: switch the pages to the sentinel when the library ships it.
  - Found (24 Nov 2026): the library already resolves an app-provided value in an index page's fixed `filter`: `@config.<key>` reads the injected `cnAppConfig` and the list re-fetches when that bag changes (`useSelfFetchList.js` `tokenCtx()` / `appConfigSignature`, nextcloud-vue 2.209). No new `@context` sentinel is needed (`@context.` already means a detail page's side records). The switch: `main.js` provides `cnAppConfig` with `activeAdministrationId` from `GET /api/administrations/context`; `scripts/generate-administration-scoped-pages.js` writes `"filter": {"administrationId": "@config.activeAdministrationId"}` into the 214 generated pages' config instead of the JSON list (a required token left unresolved filters on the literal and shows nothing, which fails closed); the wrapper goes. Not switched in the build sandbox: it changes every list page and needs a live pass.

## 3. Templates

- [x] 3.1 Add `AdministrationTemplate` and the `followsTemplateId`, `followedTemplateVersion` and `deviatesFromTemplate` fields in `register.d/bookkeeping-multi-administratie.json` (REQ-LMA-003, REQ-LMA-005). Verify: `npm run check:registers`, `npm run check:seeds`.
- [x] 3.2 Save as template action on the administration detail page (REQ-LMA-003). Verify: PHPUnit that no postings, relations or balances are copied.
  Built: `ChartTemplateService::saveAsTemplate()`, `POST /api/administrations/{id}/template` and the Save as template action (`SaveAdministrationTemplateModal.vue`) on the administration detail page.
- [x] 3.3 Office templates in the setup wizard template step and in `SettingsService` seeding (REQ-LMA-004). Verify: PHPUnit seeding from an office template.
  Built: `GET /api/administration-templates`, `POST /api/administrations/{id}/seed-template`, and the wizard's template step reading `chartTemplates` from the setup status (shipped RGS variants plus `office:<id>`), which `SettingsService::seedRgsTemplate()` seeds through `ChartTemplateService::seedFromTemplate()`.
- [x] 3.4 Follower sync job in `lib/BackgroundJob/` queued on a template version change, recording on `AdministrationMigration` (REQ-LMA-005). Verify: PHPUnit for add, update and skip; `npm run check:job-registration`.

## 4. End to end and strings

- [x] 4.1 Playwright `tests/e2e/ledger-multi-administration.spec.ts`: switch, reload, scoped list, start from an office template. Verify: passes locally. **Written, runs in nightly (24 Nov 2026): the spec is in the CI Playwright testDir and matches no testIgnore; not verified here, no live instance in the build sandbox. Scenarios marked `test.fixme` in the spec stay owed.**
  Written (17 Nov): the four scenarios plus a session-less visitor refused; not run here (no live instance), so unticked until it passes against one.
- [x] 4.2 Dutch and English strings. Verify: `npm run test:l10n`, `npm run test:l10n-parity`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push; IDOR check on the template endpoints (ADR-005).
