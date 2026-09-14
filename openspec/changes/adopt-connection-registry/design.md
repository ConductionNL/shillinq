# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This
file records how shillinq meets it and where the fit is loose.

## D1. What shillinq's adapters really are

Every family binds one class in `Application::register()`, and it is a
log-only adapter for all fifteen. No real adapter ships in shillinq. A
downstream app can override the binding. No app-config key names the class.

Measured on development with `git grep` over `lib/` for each port interface:

| Family key | Called by | Declared as |
|---|---|---|
| `digipoort-sbr` | nothing | not available |
| `salarisbureau` | nothing | not available |
| `rvo` | nothing | not available |
| `ib47` | nothing | not available |
| `cbs-bestanden` | nothing | not available |
| `cbs-iv3` | nothing | not available |
| `bzk-sisa` | nothing | not available |
| `mollie` | `MolliePaymentProvider`, which the portal pay-now flow drives | reported |
| `bunq` | nothing | not available |
| `kvk` | nothing | not available |
| `uwv` | nothing | not available |
| `treasury-rates` | `FxRateImportJob`, `FxRateAdminController`, `TreasuryRateService` | reported |
| `ccm-rule-engine` | nothing | not available |
| `csrd-esrs-xbrl` | nothing | not available |
| `deposit-payment` | `DepositReconciliationService`, `NoShowFeeCaptureService` | reported |

## D2. The declaration

`lib/Settings/connections.json` keeps the roster's keys and order, so links
and tests keep their meaning (contract D10 step 1). `order` runs 10 to 150.

- **Not called: `available: false`.** The message says a log-only adapter is
  bound, nothing calls it, and nothing reaches the outside party. That is the
  same reading dossiq gave its KvK row.
- **Called: no `available`, no `adapter`.** The row starts at contract rule 6
  with an `unconfiguredMessage` saying shillinq reports on it once a day. The
  first report moves it to Simulated (rule 4).
- **`settingsUrl`** only for `treasury-rates`. It opens the FX rates admin
  page, the one screen that shows that adapter's state. No other family has a
  settings screen, so no other row links anywhere.
- **`sourceTemplate`** only for `kvk`. Integriq ships `kvk-source.json` and no
  template for any other family.
- **No `requiredConfig`.** The roster listed config keys such as
  `mollie.api.key`, and no code reads them. A key nothing reads says nothing
  about the connection.

## D3. The reporter

`ConnectionReportService::reportAdapterBindings()` looks at the three called
families and sends one `ConnectionStatusReportedEvent` each:

| What shillinq sees | status | message |
|---|---|---|
| The bound adapter says `isDormant()` | `simulated` | A log-only adapter answers here, and what does not happen |
| A bound adapter that is not dormant | `configured` | A real adapter is bound, and shillinq does not test it |
| The binding throws on resolve | `error` | Shillinq could not load the adapter, and why |

- `ConnectionReportJob` runs it once a day. A binding only changes with a
  deploy, so a daily look is enough, and each report is one object write in
  integriq.
- The event class is a string constant, resolved with `class_exists`, as
  dossiq's `IntegrationStatusService` does (ADR-041). Without integriq nothing
  is sent and nothing is logged.
- A listener that throws is caught and logged as a warning.
- The twelve not-available families get no report. Contract rule 2 outranks a
  report, so one would only cost a write.

The dormancy check is the one the roster controller made. It moves out of a
page request into the job.

## D4. The page

- `src/manifest.d/external-adapters-w8.json` keeps the menu id
  `ExternalConnections`, its order, the page id `ExternalAdaptersStatus` and
  the route `/external-adapters`. The menu id is what
  `menu-layout.json#settingsSection` lifts into the settings foldout.
- The page is `type: index` over `integriq/app_connection` with
  `requiresApp: {id: integriq, name: Integriq}`, `showAdd: false` and the
  columns dossiq uses: title, status, status message, last checked, settings.
- The menu entry carries `query: {app: shillinq}` and
  `visibleIf.appInstalled: integriq` (ADR-097 decision 5).
- Add integration is a header action whose handler, `openIntegriqConnections`,
  opens `/apps/integriq/connections?app=shillinq&link=1`. A `navigate` handler
  only pushes a route inside shillinq.
- Shillinq passes no formatters to `CnAppRoot` today. `connectionStatus` and
  `connectionSettingsLabel` live in `src/services/connectionFormatters.js` and
  reach the page through a new `formatters` prop. The handler joins the
  `customComponents` map, which is where `CnIndexPage` resolves a named
  handler.

## D5. What is removed and what stays

Removed, because they only served the status page:

- `lib/Controller/ExternalAdaptersAdminController.php` and the
  `externalAdaptersAdmin#index` route.
- `src/views/external-adapters/ExternalAdaptersStatus.vue` and its registry
  entry.
- `tests/Unit/Controller/ExternalAdaptersRegisterResolutionTest.php` and
  `tests/Unit/Support/FakeSlugResolver.php`, which only that test used.
- `tests/vitest/externalAdapters.spec.js`, and the three roster e2e specs
  with their visual baseline.

Kept:

- Every adapter interface and log-only adapter. Real work calls three of them.
- `lib/Settings/openconnector-sources.json`, the configuration half of
  `integration-config-to-openconnector`.
- The `RegisterSlugResolverInterface` alias in `Application`. The slug-pin
  test tells new code to use it.
- `FxRatesAdmin`, which does real work and is now the treasury row's link.

## D6. Where the contract is loose

1. **Simulated needs an app-config key.** Rule 3 only fires on an empty
   `adapter.configKey`. Shillinq selects adapters by DI binding, so the three
   called rows reach Simulated through a report (rule 4). A probe newer than
   that report outranks it. If an admin links a source to `mollie`, the row
   can read Configured while the log-only adapter still answers. The contract
   says simulated beats a passing probe, and it cannot keep that promise here.
   Proposed amendment: let a `simulated` report outrank a probe, as rule 3
   does, or add a declaration field that says the app reports its adapter
   binding.
2. **Available false outranks everything.** If a downstream app binds a real
   Digipoort adapter, the row stays Not available until shillinq's
   declaration changes. That matches what shillinq ships.
3. **A report before the first sync is refused.** On a fresh install the job
   can run before integriq syncs shillinq's file. Integriq logs a warning and
   the next daily run lands.
