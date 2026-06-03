# Tasks — Member 01: config + contact link

Sourced from the giant's Phase 1 (Setup & Configuration) plus the
booking-data-model task from Phase 2.

## Connection config

- [ ] Create `PipelinqConfig` settings interface to manage endpoint + token storage
- [ ] Add `getPipelinqEndpoint()` and `setPipelinqEndpoint($url)` methods
- [ ] Add `getPipelinqToken()` / `setPipelinqToken($token)` (store in secrets store)
- [ ] Add `testConnection()` method that calls the pipelinq health-check endpoint

## Admin settings UI

- [ ] Add admin settings page: text input for pipelinq API endpoint
- [ ] Add masked password input for the API token
- [ ] Wire "Test Connection" button to `PipelinqConfig::testConnection()`
- [ ] Show success/error feedback messages

## Booking↔contact link (declarative)

- [ ] Declare optional `pipelinqContactId` field in the booking schema (`shillinq_register.json` + manifest), nullable, non-unique
- [ ] Surface `pipelinqContactId` so detail views can read it

## Tests

- [ ] Unit-test `PipelinqConfig` endpoint getter/setter
- [ ] Unit-test token storage (verify it is not logged / not plaintext)
- [ ] Unit-test `testConnection()` success case
- [ ] Unit-test `testConnection()` failure cases (timeout, 401, 404, 5xx)
- [ ] Add the pipelinq API mock-server integration-test scaffold reused by later members
