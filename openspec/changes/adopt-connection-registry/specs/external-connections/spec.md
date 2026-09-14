## ADDED Requirements

### Requirement: The External Connections page lists shillinq's rows from integriq's registry (REQ-EXTCONN-001)

The External Connections page SHALL be an `index` page over integriq's
`app_connection` schema, preset to `app` equal to `shillinq` through its menu
entry's `query` (hydra REQ-CONN-006). It SHALL declare Integriq as the app it
requires. The menu entry SHALL keep its id, its place in the settings foldout
and its route, and SHALL only render when integriq is installed. The page
SHALL NOT offer the generic Add button. Its Add integration action SHALL open
integriq's Connections overview with `app=shillinq&link=1`.

This requirement supersedes REQ-ICO-002 and REQ-ICO-007 of
`integration-config-to-openconnector`.

**Feature tier**: MVP

#### Scenario: The menu opens the page on shillinq's own rows

- **GIVEN** shillinq and integriq are installed and integriq has synced shillinq's declaration
- **WHEN** an admin opens the settings foldout and chooses External Connections
- **THEN** the page SHALL list the fifteen declared families in declared order
- **AND** every listed row SHALL have `app` equal to `shillinq`

#### Scenario: Without integriq the page says what is missing

@e2e exclude The CI instance installs integriq, so no browser flow reaches shillinq without it. tests/vitest/externalConnectionsPage.spec.js asserts the requiresApp and visibleIf declarations, and CnPageRenderer renders the screen.

- **GIVEN** integriq is not installed
- **WHEN** an admin opens `/apps/shillinq/external-adapters` by URL
- **THEN** the missing-dependency screen SHALL name Integriq
- **AND** the menu SHALL NOT list External Connections

#### Scenario: Add integration goes to integriq

- **GIVEN** the External Connections page
- **WHEN** the admin looks for a way to add a connection
- **THEN** no generic Add button SHALL be offered
- **AND** the Add integration action SHALL open integriq's Connections overview with `app=shillinq` and `link=1`

### Requirement: Shillinq declares its adapter families in one static file (REQ-EXTCONN-002)

Shillinq SHALL declare its fifteen adapter families in
`lib/Settings/connections.json` with the keys the roster used (hydra
REQ-CONN-001). A family whose port no screen or service calls SHALL be
declared not available, with a message saying a log-only adapter is bound and
nothing calls it. A family whose port is called SHALL NOT be declared not
available, and SHALL carry a message saying shillinq has not reported on it
yet. A `settingsUrl` SHALL only point at a page shillinq's manifest declares.
A `sourceTemplate` SHALL only name a template integriq ships.

**Feature tier**: MVP

#### Scenario: An uncalled family reads Not available and says why

- **GIVEN** the declaration integriq synced
- **WHEN** the admin reads the Digipoort SBR row
- **THEN** it SHALL read Not available
- **AND** its message SHALL say a log-only adapter is bound and nothing calls it

#### Scenario: Only the treasury row links to a shillinq page

- **GIVEN** the declaration integriq synced
- **WHEN** the admin reads the External Connections page
- **THEN** the Treasury rates row SHALL offer Open settings to the FX rates admin page
- **AND** no other row SHALL offer Open settings

#### Scenario: The declaration is one integriq accepts

@e2e exclude A file shape has no browser surface. tests/Unit/Settings/ConnectionsDeclarationTest.php validates it against the vendored connections.schema.json, checks key uniqueness and checks the treasury link against the manifest.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL pass
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique

### Requirement: Shillinq reports the adapter it sees for each called family (REQ-EXTCONN-003)

Once a day shillinq SHALL send `ConnectionStatusReportedEvent` for each family
whose port is called: `mollie`, `deposit-payment` and `treasury-rates` (hydra
REQ-CONN-004). A bound adapter that reports itself dormant SHALL be reported
`simulated`, with a message saying a log-only adapter answers and what does
not happen. A bound adapter that is not dormant SHALL be reported
`configured`, with a message saying shillinq does not test it. A binding that
cannot be resolved SHALL be reported `error` with the reason. The event SHALL
be named by string and sent only when the class exists. A listener that
throws SHALL NOT reach the job.

This requirement supersedes REQ-ICO-003 of
`integration-config-to-openconnector`: shillinq no longer looks up a source
by slug.

**Feature tier**: MVP

#### Scenario: A log-only Mollie adapter reads Simulated

- **GIVEN** the declaration integriq synced and the default bindings
- **WHEN** shillinq's daily connection report has run
- **THEN** the Mollie payments row SHALL read Simulated
- **AND** its message SHALL say a log-only adapter answers

#### Scenario: Without integriq nothing is sent and nothing is logged

@e2e exclude The CI instance installs integriq. tests/Unit/Service/ConnectionReportServiceTest.php asserts that nothing is dispatched or logged when the event class is absent.

- **GIVEN** integriq is not installed
- **WHEN** the daily connection report runs
- **THEN** no event SHALL be sent
- **AND** no warning SHALL be logged

#### Scenario: A failing listener never reaches the job

@e2e exclude A listener that throws cannot be installed from a browser. tests/Unit/Service/ConnectionReportServiceTest.php asserts the exception is caught and logged.

- **GIVEN** integriq's report listener throws
- **WHEN** the daily connection report runs
- **THEN** the job SHALL finish
- **AND** the failure SHALL be logged as a warning naming the family

### Requirement: The roster is removed with the page it served (REQ-EXTCONN-004)

Shillinq SHALL NOT work out a connection's status on a page request. The
`externalAdaptersAdmin#index` route, `ExternalAdaptersAdminController` and
`ExternalAdaptersStatus.vue` SHALL be removed. The adapter interfaces, the
log-only adapters and `lib/Settings/openconnector-sources.json` SHALL stay.

**Feature tier**: MVP

#### Scenario: The roster endpoint is gone

- **GIVEN** this version of shillinq
- **WHEN** an admin requests `/apps/shillinq/api/admin/external-adapters`
- **THEN** the response SHALL NOT be a roster of adapter families
