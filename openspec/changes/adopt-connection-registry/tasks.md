# Tasks: adopt-connection-registry

- [x] 1.1 `lib/Settings/connections.json`: fifteen keys in roster order,
  twelve `available: false`, three with an `unconfiguredMessage`, a
  `settingsUrl` for `treasury-rates`, a `sourceTemplate` for `kvk`.
  - `tests/Unit/Settings/ConnectionsDeclarationTest.php` against the vendored
    `tests/fixtures/integriq/connections.schema.json`.
- [x] 2.1 `lib/Service/ConnectionReportService.php`: report the three called
  families by event, behind `class_exists`, never throwing.
- [x] 2.2 `lib/BackgroundJob/ConnectionReportJob.php`, registered in
  `appinfo/info.xml`.
  - `tests/Unit/Service/ConnectionReportServiceTest.php`,
    `tests/Unit/BackgroundJob/ConnectionReportJobTest.php`.
  - Stubs under `tests/stubs/Integriq/Event/`, loaded by
    `tests/bootstrap-unit.php`.
- [x] 3.1 `src/manifest.d/external-adapters-w8.json`: `integriq/app_connection`
  index page, `requiresApp`, `showAdd: false`, Add integration header action;
  menu entry `query` and `visibleIf.appInstalled`.
- [x] 3.2 `src/utils/connectionFormatters.js` and
  `src/utils/integriqConnections.js`, wired through `src/main.js` and
  `src/App.vue`.
  - `tests/vitest/externalConnectionsPage.spec.js`.
  - l10n keys in `en` and `nl`.
- [x] 4.1 Remove `ExternalAdaptersAdminController`, its route,
  `ExternalAdaptersStatus.vue`, its registry entry and the tests that only
  served them.
- [x] 4.2 `tests/e2e/external-connections.spec.ts` replaces the three roster
  specs. Not run here: it needs integriq's side installed.
- [ ] 5.1 After integriq ships on the CI instance: run the e2e spec, then
  archive this change.
