# Tasks — Member 10: integration & E2E tests

Sourced from the giant's Phase 5 (Integration & E2E Testing).

## Integration tests

- [x] Integration: create booking → timeline event publishes (assert POST payload + booking saved + confirmation logged)
- [x] Integration: customer profile card displays in detail view (name, email, phone + up to 5 transactions)
- [x] Integration: graceful degradation when pipelinq unavailable (booking saved, event queued, card shows error, history hidden)

## E2E tests

- [x] E2E: admin configures pipelinq endpoint (enter endpoint + token, Test Connection success, settings persist across reload)
- [x] E2E: booking lifecycle triggers all four timeline events (created, confirmed, cancelled, completed appear in the timeline)
