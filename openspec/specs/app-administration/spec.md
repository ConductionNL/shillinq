# Spec: app-administration

**Status:** done
**Scope:** shillinq
**Tier:** T0 (platform)
**Depends on:** none

**OpenSpec changes:** [retrofit-2026-05-25-app-administration](../../changes/archive/2026-05-31-retrofit-2026-05-25-app-administration/) _(archived 2026-05-31)_

> Reverse-engineered from observed code (ADR-003 retrofit). Describes the
> application-administration surface as it ships today.

## Purpose

Defines the application-administration surface of Shillinq as it ships
today: the authenticated settings endpoint, forced register-configuration
re-import, the public health probe, the admin-only metrics endpoint, and
the generic OpenRegister object store used by the Vue shell.

## Requirements

### Requirement: REQ-Admin-001 The system SHALL expose the app's configuration through a settings endpoint

Shillinq MUST provide an authenticated settings surface backed by
`SettingsService`. `GET /apps/shillinq/api/settings` returns the
managed config keys (`register`, `rgs_template`, `administration_id`)
plus the derived metadata fields `openregisters` (whether OpenRegister
is installed) and `isAdmin` (whether the current user is a Nextcloud
admin). `POST /apps/shillinq/api/settings` persists any supplied managed
key via `IAppConfig` and returns the refreshed settings. Keys not in the
managed set are ignored.

#### Scenario: Operator reads current settings

@e2e exclude REST API contract: covered by PHPUnit SettingsControllerTest — not browser-observable

- **GIVEN** shillinq is installed
- **WHEN** an authenticated user calls `GET /apps/shillinq/api/settings`
- **THEN** the response MUST contain each managed config key (empty
  string when unset) plus `openregisters` and `isAdmin` flags.

#### Scenario: Operator updates a managed key

@e2e exclude REST API contract: covered by PHPUnit SettingsControllerTest — not browser-observable

- **GIVEN** an authenticated request to `POST /apps/shillinq/api/settings`
  with `{ "register": "shillinq" }`
- **WHEN** the request is processed
- **THEN** the `register` app-config value MUST be stored as a string and
  the response MUST report `success: true` with the refreshed `config`.

### Requirement: REQ-Admin-002 The system SHALL support forced re-import of the register configuration

Shillinq MUST offer an operator action that re-imports
`lib/Settings/shillinq_register.json` into OpenRegister via
`ConfigurationService::importFromApp()` with `force: true`, regardless of
the currently-installed version. When OpenRegister is unavailable, the
configuration file is missing, or the import returns an empty result, the
action MUST fail gracefully with `success: false` and a human-readable
message rather than throwing.

#### Scenario: Operator forces a configuration re-import

@e2e exclude REST API contract: covered by PHPUnit SettingsControllerTest::testLoad — not browser-observable

- **GIVEN** OpenRegister is installed and `shillinq_register.json` is present
- **WHEN** the operator triggers the `load` action
- **THEN** the configuration MUST be imported with `force: true` and the
  response MUST report `success: true` with the imported `version`.

#### Scenario: Re-import attempted without OpenRegister

@e2e exclude REST API contract: requires uninstalling OpenRegister mid-test; covered by PHPUnit SettingsControllerTest — not browser-testable

- **GIVEN** OpenRegister is not installed
- **WHEN** the `load` action runs
- **THEN** the response MUST report `success: false` with a message
  stating OpenRegister is not installed, and MUST NOT throw.

### Requirement: REQ-Admin-003 The system SHALL expose a public health endpoint

Shillinq MUST provide an unauthenticated (`PublicPage`, CSRF-exempt)
health endpoint that returns a JSON body `{ status: "ok", app: "shillinq" }`,
suitable for liveness probes and uptime monitors.

#### Scenario: Monitor probes health

@e2e exclude REST API liveness probe: HTTP-level check only, no DOM interaction — covered by PHPUnit HealthControllerTest

- **GIVEN** the app is running
- **WHEN** any client calls the health endpoint
- **THEN** the response MUST be JSON with `status: "ok"` and
  `app: "shillinq"`, requiring no authentication.

### Requirement: REQ-Admin-004 The system SHALL expose an admin-only metrics endpoint

Shillinq MUST provide a metrics endpoint guarded by
`AuthorizedAdminSetting` that returns a JSON body containing the app id and
a `metrics` collection. The collection MAY be empty (current placeholder
shape); the endpoint MUST remain reachable only to authorized admins.

#### Scenario: Admin reads metrics

@e2e exclude REST API contract: covered by PHPUnit MetricsControllerTest — not browser-observable

- **GIVEN** an authorized admin
- **WHEN** the admin calls the metrics endpoint
- **THEN** the response MUST be JSON containing `app: "shillinq"` and a
  `metrics` array (currently empty).

### Requirement: REQ-Admin-005 The frontend SHALL read register data through a generic OpenRegister object store

The Vue shell MUST use a generic Pinia object store that is configured
once with the OpenRegister object and schema base URLs, lets callers
register named object types (mapping a type to its register + schema),
and fetches objects for a registered type via the OpenRegister object
API (carrying the Nextcloud request token). Fetching an unregistered
type MUST warn and return an empty list rather than erroring. The
settings store MUST likewise load and persist settings against the
shillinq settings endpoint, exposing `hasOpenRegisters` / `isAdmin`
flags derived from the response.

#### Scenario: Shell fetches objects for a registered type

@e2e exclude Pinia store unit-test territory: verifies JS store logic (HTTP interception), not DOM rendering — covered by Vitest unit tests

- **GIVEN** the object store is configured and a type is registered with
  its register + schema
- **WHEN** the shell calls `fetchObjects(type)`
- **THEN** the store MUST request the OpenRegister object API with the
  mapped register/schema and the request token, and store the returned
  results under that type.

#### Scenario: Shell fetches an unregistered type

@e2e exclude Pinia store unit-test territory: verifies warning emission + no-op fetch logic, not DOM rendering — covered by Vitest unit tests

- **GIVEN** the object store is configured
- **WHEN** the shell calls `fetchObjects` with a type that was never
  registered
- **THEN** the store MUST emit a warning and return an empty list without
  performing a request.
