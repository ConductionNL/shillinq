# Tasks — Member 01: config + contact link

Sourced from the giant's Phase 1 (Setup & Configuration) plus the
booking-data-model task from Phase 2.

## Connection config

- [x] Create `PipelinqConfig` settings interface to manage endpoint + token storage
- [x] Add `getPipelinqEndpoint()` and `setPipelinqEndpoint($url)` methods
- [x] Add `getPipelinqToken()` / `setPipelinqToken($token)` (store in secrets store)
- [x] Add `testConnection()` method that calls the pipelinq health-check endpoint

## Admin settings UI

- [x] Add admin settings page: text input for pipelinq API endpoint
- [x] Add masked password input for the API token
- [x] Wire "Test Connection" button to `PipelinqConfig::testConnection()`
- [x] Show success/error feedback messages

## Booking↔contact link (declarative)

- [x] Declare optional `pipelinqContactId` field in the booking schema (`shillinq_register.json` + manifest), nullable, non-unique
- [x] Surface `pipelinqContactId` so detail views can read it

## Tests

- [x] Unit-test `PipelinqConfig` endpoint getter/setter
- [x] Unit-test token storage (verify it is not logged / not plaintext)
- [x] Unit-test `testConnection()` success case
- [x] Unit-test `testConnection()` failure cases (timeout, 401, 404, 5xx)
- [x] Add the pipelinq API mock-server integration-test scaffold reused by later members

## Implementation notes

- Endpoint stored in `IAppConfig` under `pipelinq_api_endpoint`
  (`PipelinqConfig::KEY_ENDPOINT`).
- API token stored via `ICredentialsManager` under `pipelinq_api_token`
  (`PipelinqConfig::CREDENTIAL_ID_TOKEN`) per ADR-005 — never plaintext,
  never logged. The frontend only receives a `hasToken` flag, never
  the token value.
- `pipelinqContactId` declared additively on the existing `Appointment`
  schema via `lib/Settings/register.d/bookings-pipelinq-customer-bridge-01-config-contact-link.json`
  (ADR-037 modular fragment — no edits to the monolith
  `shillinq_register.json`, no raw `ALTER TABLE`).
- Detail-page surfacing: the field is appended to the existing
  `AfspraakDetail` manifest fields list; the rich profile-card UI
  lands in member 06 (`bookings-pipelinq-customer-bridge-06-profile-card-ui`).
- Integration scaffold: `tests/Integration/Pipelinq/PipelinqMockServer.php`
  is an in-process router (no socket listener — CI-safe, no port
  conflicts) reused by members 03-10. Canned `contact-{id}.json`,
  `klantbeeld-{id}.json` and `timeline-{id}.json` fixtures live under
  `fixtures/`; `forceStatus()` + `getRequests()` support
  retry/circuit-breaker tests in member 09.
- Hydra mechanical gates: 14/15 PASS (composer-audit is a pre-existing
  fleet-wide Twig CVE — unrelated).
- Unit suite: 356 tests pass (6 pre-existing warnings in
  `InitializeSettings`).
