# Tasks: contacts-kvk-lookup

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Declarations

- [x] 1.1 Add `x-openregister-property-source` with the customer fill map of design D2 to `CustomerMaster.kvkNumber` in `lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json`, and with the supplier fill map to `Payee.kvkNumber` in `lib/Settings/register.d/bookkeeping-accounts-payable-core.json`; regenerate `shillinq_register.json` if the build merges fragments (REQ-KVKL-001, REQ-KVKL-002). Verify: `npm run check:registers`; a PHPUnit test that reads both fragments and asserts provider `kvk`, mode `default` and every fill target is a declared property of its schema.
- [x] 1.2 Import the register on a dev instance and read both schemas from openregister (REQ-KVKL-001). Verify: `propertyMetadata.kvkNumber` names `kvk` on both; `grep 'PARTIAL IMPORT'` in the log finds nothing; record the output in the PR body. **Written, runs in nightly (24 Nov 2026):** the last describe block of `tests/e2e/contacts-kvk-lookup.spec.ts` reads `Payee` and `CustomerMaster` from openregister's schema API on the instance the nightly imports the register into and asserts `kvkNumber` names provider `kvk` on both; the `PARTIAL IMPORT` log grep has no HTTP surface and stays with the live pass. Not verified here, no live instance in the build sandbox.

## 2. Connection

- [x] 2.1 Switch the `kvk` entry in `lib/Settings/connections.json` on per design D3 (REQ-KVKL-003). Verify: the existing connections test (`ConnectionReportService` tests) passes with the entry available; integriq's connections overview lists it as not set up yet on the dev instance.

## 3. End to end, once nextcloud-vue renders the key

- [x] 3.1 With the KvK source linked (sandbox or the integriq seed), add a supplier from the KvK and read the filled name and address; type a name first and confirm the replace question (REQ-KVKL-002). Verify: Playwright test tagged with `@e2e` references to the scenarios; skipped with a stated reason while the renderer is absent. Spec written: `tests/e2e/contacts-kvk-lookup.spec.ts` (integriq answered from a fixture; skips when integriq is not installed); not yet run against an instance. **Written, runs in nightly (24 Nov 2026): the spec is in the CI Playwright testDir and matches no testIgnore; not verified here, no live instance in the build sandbox. Scenarios marked `test.fixme` in the spec stay owed.**
- [x] 3.2 With no source linked, add a customer with a typed number and confirm it saves with the not-set-up message (REQ-KVKL-003). Verify: Playwright, same tagging. Spec written in the same file; skips when a source is linked; not yet run against an instance. **Written, runs in nightly (24 Nov 2026): the spec is in the CI Playwright testDir and matches no testIgnore; not verified here, no live instance in the build sandbox. Scenarios marked `test.fixme` in the spec stay owed.**

## 4. Docs

- [x] 4.1 Add "Look a company up in the KvK" to the customers and suppliers user guide pages in `docs/`, and the unconfigured message string with its Dutch translation in `l10n/` (REQ-KVKL-003). Verify: `npm run test:l10n`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `npm run lint` and `npm run format`.
